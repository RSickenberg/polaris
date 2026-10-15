<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Tesla\Command\KeyCheckCommand;
use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(KeyCheckCommand::class)]
final class KeyCheckCommandTest extends KernelTestCase
{
    use SignsIn;

    private const string KEY = "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE\n-----END PUBLIC KEY-----\n";

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

    public function testSucceedsWhenTheServedKeyMatches(): void
    {
        $this->createApp();
        $tester = $this->tester(new MockHttpClient(new MockResponse(self::KEY)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('https://polaris.example.com/.well-known/appspecific/com.tesla.3p.public-key.pem', $tester->getDisplay());
    }

    public function testFailsWhenTheKeyIsNotServed(): void
    {
        $this->createApp();
        $tester = $this->tester(new MockHttpClient(new MockResponse('', ['http_code' => 404])));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('HTTP 404', $tester->getDisplay());
    }

    public function testFailsWithoutADomain(): void
    {
        self::bootKernel();
        $owner = self::createUser();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TeslaApp($owner, self::KEY, 'v1:private', CarbonImmutable::parse('2026-10-20 12:00:00 UTC')));
        $entityManager->flush();
        $client = new MockHttpClient([]);
        $tester = $this->tester($client);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('polaris:tesla:configure', $tester->getDisplay());
        self::assertSame(0, $client->getRequestsCount());
    }

    private function tester(MockHttpClient $client): CommandTester
    {
        $kernel = self::bootKernel();
        self::getContainer()->set('http_client', $client);

        return new CommandTester(new Application($kernel)->find('polaris:tesla:key:check'));
    }

    private function createApp(): void
    {
        self::bootKernel();
        $owner = self::createUser();
        $app = new TeslaApp($owner, self::KEY, 'v1:private', CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));
        $app->configure('client-id', 'v1:secret', AppDomain::fromString('polaris.example.com'), [FleetRegion::EuropeMiddleEastAfrica]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($app);
        $entityManager->flush();
    }
}
