<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Command\KeyImportCommand;
use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Domain\KeyPair;
use Polaris\Tesla\Domain\KeyPairGenerator;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(KeyImportCommand::class)]
final class KeyImportCommandTest extends KernelTestCase
{
    use SignsIn;

    private KeyPair $pair;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->pair = new KeyPairGenerator()->generate();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testFailsWithoutAUser(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('polaris:user:create', $tester->getDisplay());
    }

    public function testImportsThePrivateKeyFromTheStandardInput(): void
    {
        self::createUser();
        $tester = $this->tester();
        $tester->setInputs([$this->pair->privateKeyPem()]);

        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $app = $this->app();
        self::assertSame($this->pair->publicKeyPem, $app->getPublicKey());
        self::assertStringStartsWith('v1:', $app->getEncryptedPrivateKey());
        self::assertSame(
            $this->pair->privateKeyPem(),
            self::getContainer()->get(SecretEncrypter::class)->decrypt($app->getEncryptedPrivateKey()),
        );

        $display = $tester->getDisplay();
        self::assertStringContainsString(trim($this->pair->publicKeyPem), $display);
        self::assertStringNotContainsString('PRIVATE KEY', $display);
        self::assertStringNotContainsString($app->getEncryptedPrivateKey(), $display);
    }

    public function testImportsThePrivateKeyFromAFile(): void
    {
        self::createUser();
        $path = tempnam(sys_get_temp_dir(), 'polaris-key');
        self::assertIsString($path);
        $this->files[] = $path;
        file_put_contents($path, $this->pair->privateKeyPem());
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--file' => $path]));
        self::assertSame($this->pair->publicKeyPem, $this->app()->getPublicKey());
    }

    public function testRefusesToReplaceTheKeyWithoutForceAndKeepsTheCredentialsWithIt(): void
    {
        $owner = self::createUser();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existing = new TeslaApp($owner, 'old public key', 'v1:old', CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));
        $existing->configure('client-id', 'v1:secret', AppDomain::fromString('polaris.example.com'), [FleetRegion::EuropeMiddleEastAfrica]);
        $entityManager->persist($existing);
        $entityManager->flush();

        $tester = $this->tester();
        $tester->setInputs([$this->pair->privateKeyPem()]);
        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('already exists', $tester->getDisplay());
        $entityManager->clear();
        self::assertSame('old public key', $this->app()->getPublicKey());

        $forced = $this->tester();
        $forced->setInputs([$this->pair->privateKeyPem()]);
        self::assertSame(Command::SUCCESS, $forced->execute(['--force' => true], ['interactive' => false]));
        $entityManager->clear();
        $app = $this->app();
        self::assertSame($this->pair->publicKeyPem, $app->getPublicKey());
        self::assertSame('client-id', $app->getClientId());
        self::assertSame(1, $entityManager->getRepository(TeslaApp::class)->count());
    }

    public function testRejectsAnUnusableKeyAndStoresNothing(): void
    {
        self::createUser();
        $tester = $this->tester();
        $tester->setInputs([$this->pair->publicKeyPem]);

        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('not a PEM private key', $tester->getDisplay());
        self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->getRepository(TeslaApp::class)->findOneBy([]));
    }

    public function testNeedsAKeyOnTheInputOrAFile(): void
    {
        self::createUser();
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('Pipe the PEM file', $tester->getDisplay());

        $missing = $this->tester();
        self::assertSame(Command::INVALID, $missing->execute(['--file' => '/nonexistent/key.pem']));
        self::assertStringContainsString('cannot be read', $missing->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find('polaris:tesla:key:import'));
    }

    private function app(): TeslaApp
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(TeslaApp::class)->findOneBy([]) ?? throw new \LogicException('No Tesla app.');
    }
}
