<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Command\TokenRefreshCommand;
use Polaris\Tesla\Domain\ConnectionStatus;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(TokenRefreshCommand::class)]
final class TokenRefreshCommandTest extends KernelTestCase
{
    use SignsIn;
    use TeslaFixtures;

    public function testFailsWithoutAUser(): void
    {
        $tester = $this->tester(new MockHttpClient([]));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('polaris:user:create', $tester->getDisplay());
    }

    public function testFailsWithoutAConnection(): void
    {
        self::bootKernel();
        self::createConfiguredApp(self::createUser());
        $tester = $this->tester(new MockHttpClient([]));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('not connected', strtolower($tester->getDisplay()));
    }

    public function testDoesNotCallTeslaWhenTheTokenIsNotDue(): void
    {
        self::bootKernel();
        self::createToken(self::createConfiguredApp(self::createUser()), CarbonImmutable::now()->addHours(2));
        $client = new MockHttpClient([]);
        $tester = $this->tester($client);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(0, $client->getRequestsCount());
        self::assertStringContainsString('--force', $tester->getDisplay());
    }

    public function testForceRefreshesAndNeverPrintsATokenAndRotatesBoth(): void
    {
        self::bootKernel();
        self::createToken(self::createConfiguredApp(self::createUser()), CarbonImmutable::now()->addHours(2));
        $client = new MockHttpClient(new MockResponse((string) json_encode([
            'access_token' => 'new-access-value',
            'refresh_token' => 'new-refresh-value',
            'expires_in' => 28800,
        ])));
        $tester = $this->tester($client);

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));

        self::assertSame(1, $client->getRequestsCount());
        self::assertStringContainsString('Refreshed', $tester->getDisplay());
        self::assertStringNotContainsString('new-access-value', $tester->getDisplay());
        self::assertStringNotContainsString('new-refresh-value', $tester->getDisplay());
        $token = self::getContainer()->get('doctrine.orm.entity_manager')->getRepository(OAuthToken::class)->findOneBy([]);
        self::assertInstanceOf(OAuthToken::class, $token);
        self::assertSame('new-refresh-value', self::getContainer()->get(SecretEncrypter::class)->decrypt($token->getEncryptedRefreshToken()));
    }

    public function testARefusedRefreshTokenMarksTheConnectionExpired(): void
    {
        self::bootKernel();
        self::createToken(self::createConfiguredApp(self::createUser()), CarbonImmutable::now()->addHours(2));
        $tester = $this->tester(new MockHttpClient(new MockResponse('{"error":"login_required"}', ['http_code' => 401])));

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));

        $token = self::getContainer()->get('doctrine.orm.entity_manager')->getRepository(OAuthToken::class)->findOneBy([]);
        self::assertInstanceOf(OAuthToken::class, $token);
        self::assertSame(ConnectionStatus::Expired, $token->getStatus());
    }

    public function testAnOutageKeepsTheStoredTokens(): void
    {
        self::bootKernel();
        self::createToken(self::createConfiguredApp(self::createUser()), CarbonImmutable::now()->addHours(2));
        $tester = $this->tester(new MockHttpClient(new MockResponse('', ['http_code' => 503])));

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));

        self::assertStringContainsString('unchanged', $tester->getDisplay());
        $token = self::getContainer()->get('doctrine.orm.entity_manager')->getRepository(OAuthToken::class)->findOneBy([]);
        self::assertInstanceOf(OAuthToken::class, $token);
        self::assertSame(ConnectionStatus::Connected, $token->getStatus());
    }

    private function tester(MockHttpClient $client): CommandTester
    {
        $kernel = self::bootKernel();
        self::getContainer()->set('http_client', $client);

        return new CommandTester(new Application($kernel)->find('polaris:tesla:token:refresh'));
    }
}
