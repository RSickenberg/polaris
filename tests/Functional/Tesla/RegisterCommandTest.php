<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Command\RegisterCommand;
use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Domain\KeyPairGenerator;
use Polaris\Tesla\Domain\PemComparer;
use Polaris\Tesla\Domain\PublicKeyFormat;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(RegisterCommand::class)]
final class RegisterCommandTest extends KernelTestCase
{
    use SignsIn;

    private const string SECRET = 'plain-client-secret';

    public function testFailsWithoutAUser(): void
    {
        $tester = $this->tester(new MockHttpClient([]));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('polaris:user:create', $tester->getDisplay());
    }

    public function testFailsWithoutAKeyPair(): void
    {
        self::bootKernel();
        self::createUser();
        $tester = $this->tester(new MockHttpClient([]));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('polaris:tesla:keygen', $tester->getDisplay());
    }

    public function testRegistersTheEnabledRegions(): void
    {
        $pem = $this->createApp([FleetRegion::EuropeMiddleEastAfrica]);
        $point = new PublicKeyFormat(new PemComparer())->uncompressedPointHex($pem);
        $client = new MockHttpClient([
            new MockResponse($pem),
            new MockResponse('{"access_token":"partner-token"}'),
            new MockResponse('{"response":{}}'),
            new MockResponse(json_encode(['response' => ['public_key' => $point]], \JSON_THROW_ON_ERROR)),
        ]);
        $tester = $this->tester($client);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('[eu] Registered', $display);
        self::assertStringContainsString('registered in every enabled region', $display);
        self::assertSame(4, $client->getRequestsCount());
        self::assertStringNotContainsString(self::SECRET, $display);
        self::assertStringNotContainsString('partner-token', $display);
    }

    public function testStopsWhenTheKeyCheckFails(): void
    {
        $this->createApp([FleetRegion::EuropeMiddleEastAfrica]);
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 404]));
        $tester = $this->tester($client);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('HTTP 404', $tester->getDisplay());
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testReportsTeslaErrorsAndFails(): void
    {
        $pem = $this->createApp([FleetRegion::EuropeMiddleEastAfrica]);
        $client = new MockHttpClient([
            new MockResponse($pem),
            new MockResponse('{"error":"invalid_client"}', ['http_code' => 401]),
        ]);
        $tester = $this->tester($client);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('[eu] Getting a partner token in region eu failed', $display);
        self::assertStringContainsString('invalid_client', $display);
        self::assertStringContainsString('not registered in every enabled region', $display);
    }

    public function testRefusesChinaWithAClearMessage(): void
    {
        $pem = $this->createApp([FleetRegion::China]);
        $client = new MockHttpClient(new MockResponse($pem));
        $tester = $this->tester($client);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('[cn] This region is not supported', $tester->getDisplay());
        self::assertSame(1, $client->getRequestsCount());
    }

    private function tester(MockHttpClient $client): CommandTester
    {
        $kernel = self::bootKernel();
        self::getContainer()->set('http_client', $client);

        return new CommandTester(new Application($kernel)->find('polaris:tesla:register'));
    }

    /**
     * @param list<FleetRegion> $regions
     *
     * @return string the public key PEM of the app
     */
    private function createApp(array $regions): string
    {
        self::bootKernel();
        $owner = self::createUser();
        $pair = new KeyPairGenerator()->generate();
        $app = new TeslaApp($owner, $pair->publicKeyPem, 'v1:private', CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));
        $app->configure(
            'client-id',
            self::getContainer()->get(SecretEncrypter::class)->encrypt(self::SECRET),
            AppDomain::fromString('polaris.example.com'),
            $regions,
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($app);
        $entityManager->flush();

        return $pair->publicKeyPem;
    }
}
