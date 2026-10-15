<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Command\KeygenCommand;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(KeygenCommand::class)]
final class KeygenCommandTest extends KernelTestCase
{
    use SignsIn;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testFailsWithoutAUser(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('polaris:user:create', $tester->getDisplay());
        self::assertNull($this->app());
    }

    public function testStoresTheEncryptedPrivateKeyAndPrintsOnlyThePublicKey(): void
    {
        self::createUser();
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $app = $this->app();
        self::assertInstanceOf(TeslaApp::class, $app);
        $privateKey = self::getContainer()->get(SecretEncrypter::class)->decrypt($app->getEncryptedPrivateKey());
        self::assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $privateKey);
        self::assertStringStartsWith('v1:', $app->getEncryptedPrivateKey());
        self::assertStringNotContainsString('PRIVATE KEY', $app->getPublicKey());

        $display = $tester->getDisplay();
        self::assertStringContainsString(trim($app->getPublicKey()), $display);
        self::assertStringNotContainsString('PRIVATE KEY', $display);
        self::assertStringNotContainsString(trim($privateKey), $display);
        self::assertStringNotContainsString($app->getEncryptedPrivateKey(), $display);
    }

    public function testRefusesToReplaceTheKeyWithoutForce(): void
    {
        self::createUser();
        $this->tester()->execute([]);
        $before = $this->app();
        self::assertInstanceOf(TeslaApp::class, $before);
        $publicKey = $before->getPublicKey();

        $tester = $this->tester();
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame($publicKey, $this->app()?->getPublicKey());
    }

    public function testForceReplacesTheKeyOnTheSameApp(): void
    {
        self::createUser();
        $this->tester()->execute([]);
        $before = $this->app();
        self::assertInstanceOf(TeslaApp::class, $before);
        $publicKey = $before->getPublicKey();
        $id = $before->getId();

        $tester = $this->tester();
        $status = $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $status);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $after = $this->app();
        self::assertInstanceOf(TeslaApp::class, $after);
        self::assertTrue($id->equals($after->getId()));
        self::assertNotSame($publicKey, $after->getPublicKey());
        self::assertSame(1, $entityManager->getRepository(TeslaApp::class)->count());
        self::assertStringNotContainsString('PRIVATE KEY', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('polaris:tesla:keygen'));
    }

    private function app(): ?TeslaApp
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(TeslaApp::class)->findOneBy([]);
    }
}
