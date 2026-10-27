<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Command\ConfigureCommand;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ConfigureCommand::class)]
final class ConfigureCommandTest extends KernelTestCase
{
    use SignsIn;

    private const string SECRET = 'plain-client-secret';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testFailsWithoutAUser(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('polaris:user:create', $tester->getDisplay());
    }

    public function testFailsWithoutAKeyPair(): void
    {
        self::createUser();
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('polaris:tesla:keygen', $tester->getDisplay());
    }

    public function testStoresTheSettingsAndReadsTheSecretFromTheStandardInput(): void
    {
        $this->createApp();
        $tester = $this->tester();
        $tester->setInputs([self::SECRET]);

        $status = $tester->execute(
            ['--client-id' => 'client-id', '--domain' => 'Polaris.Example.com', '--region' => ['eu', 'na']],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $status);
        $app = $this->app();
        self::assertSame('client-id', $app->getClientId());
        self::assertSame('polaris.example.com', $app->getDomain()?->toString());
        self::assertSame(
            [FleetRegion::EuropeMiddleEastAfrica, FleetRegion::NorthAmericaAsiaPacific],
            $app->getEnabledRegions(),
        );
        self::assertSame(
            self::SECRET,
            self::getContainer()->get(SecretEncrypter::class)->decrypt((string) $app->getEncryptedClientSecret()),
        );

        $display = $tester->getDisplay();
        self::assertStringContainsString('client-id', $display);
        self::assertStringNotContainsString(self::SECRET, $display);
        self::assertStringNotContainsString((string) $app->getEncryptedClientSecret(), $display);
    }

    public function testTheConsoleKeepsTheChoicesMadeOnTheSettingsPage(): void
    {
        $this->createApp();
        $app = $this->app();
        $app->changeDailyWakeCap(7);
        $app->changeKeepVehicleData(true);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $tester = $this->tester();
        $tester->setInputs([self::SECRET]);

        $tester->execute(['--client-id' => 'client-id', '--domain' => 'polaris.example.com', '--region' => ['eu']], ['interactive' => false]);

        self::assertSame(7, $this->app()->getDailyWakeCap(), 'Configuring the credentials does not reset the wake cap.');
        self::assertTrue($this->app()->keepsVehicleData());
    }

    public function testAPipedSecretIsReadEvenWhenTheCommandIsNotMarkedNonInteractive(): void
    {
        $this->createApp();
        $tester = $this->tester();
        $tester->setInputs([self::SECRET]);

        // No "interactive" option: like docker compose exec -T, only the input tells it is a pipe.
        $status = $tester->execute(['--client-id' => 'client-id', '--domain' => 'polaris.example.com', '--region' => ['eu']]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertTrue($this->app()->isConfigured());
    }

    public function testRefusesChina(): void
    {
        $this->createApp();
        $tester = $this->tester();
        $tester->setInputs([self::SECRET]);

        $status = $tester->execute(
            ['--client-id' => 'client-id', '--domain' => 'polaris.example.com', '--region' => ['cn']],
            ['interactive' => false],
        );

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('China', $tester->getDisplay());
        self::assertFalse($this->app()->isConfigured());
    }

    public function testRejectsAnUnknownRegion(): void
    {
        $this->createApp();
        $tester = $this->tester();

        $status = $tester->execute(['--region' => ['us']], ['interactive' => false]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Unknown region', $tester->getDisplay());
    }

    public function testOmittedOptionsKeepTheStoredValues(): void
    {
        $this->createApp();
        $first = $this->tester();
        $first->setInputs([self::SECRET]);
        $first->execute(
            ['--client-id' => 'client-id', '--domain' => 'polaris.example.com', '--region' => ['eu']],
            ['interactive' => false],
        );
        $ciphertext = $this->app()->getEncryptedClientSecret();

        $second = $this->tester();
        $second->setInputs(['']);
        $status = $second->execute(['--domain' => 'other.example.com'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $app = $this->app();
        self::assertSame('client-id', $app->getClientId());
        self::assertSame('other.example.com', $app->getDomain()?->toString());
        self::assertSame([FleetRegion::EuropeMiddleEastAfrica], $app->getEnabledRegions());
        self::assertSame($ciphertext, $app->getEncryptedClientSecret());
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('polaris:tesla:configure'));
    }

    private function createApp(): void
    {
        $owner = self::createUser();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TeslaApp($owner, 'public key', 'v1:private', CarbonImmutable::parse('2026-10-20 12:00:00 UTC')));
        $entityManager->flush();
    }

    private function app(): TeslaApp
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(TeslaApp::class)->findOneBy([]) ?? throw new \LogicException('No Tesla app.');
    }
}
