<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Account;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Command\CreateUserCommand;
use Polaris\Account\Entity\User;
use Polaris\Account\Repository\UserRepository;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(CreateUserCommand::class)]
final class CreateUserCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string PASSWORD = 'correct horse battery';

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-20 12:00:00 UTC');
    }

    public function testCreatesTheUserWithAHiddenPromptAndStoresOnlyAHash(): void
    {
        $tester = $this->tester();
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $status = $tester->execute(['email' => ' Driver@Example.test ']);

        self::assertSame(Command::SUCCESS, $status);
        $user = $this->users()->findOneBy([]);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('driver@example.test', $user->getEmail());
        self::assertSame('2026-10-20 12:00:00', $user->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('en', $user->getLocale());
        self::assertSame('UTC', $user->getTimeZone());
        self::assertNotSame(self::PASSWORD, $user->getPassword());
        self::assertStringNotContainsString(self::PASSWORD, $user->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::PASSWORD));
        self::assertStringContainsString('driver@example.test', $tester->getDisplay());
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
    }

    public function testReadsThePasswordFromTheStandardInputWhenNotInteractive(): void
    {
        $tester = $this->tester();
        $tester->setInputs([self::PASSWORD . "\n"]);

        $status = $tester->execute(['email' => 'driver@example.test'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($this->users()->findOneBy([]) ?? self::fail(), self::PASSWORD));
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
    }

    public function testThePasswordIsNeverAnArgumentOrAnOption(): void
    {
        $definition = new Application(self::bootKernel())->find('polaris:user:create')->getDefinition();

        self::assertSame(['email'], array_keys($definition->getArguments()));
        self::assertFalse($definition->hasOption('password'));
    }

    public function testNothingToReadWhenNotInteractive(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['']);

        self::assertSame(Command::INVALID, $tester->execute(['email' => 'driver@example.test'], ['interactive' => false]));
        self::assertCount(0, $this->users()->findAll());
    }

    public function testAnInvalidEmailIsRefused(): void
    {
        $tester = $this->tester();
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::INVALID, $tester->execute(['email' => 'not an email']));
        self::assertStringContainsString('email', $tester->getDisplay());
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
        self::assertCount(0, $this->users()->findAll());
    }

    public function testATooShortPasswordIsRefused(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['short', 'short']);

        self::assertSame(Command::INVALID, $tester->execute(['email' => 'driver@example.test']));
        self::assertStringContainsString('12 characters', $tester->getDisplay());
        self::assertCount(0, $this->users()->findAll());
    }

    public function testAMismatchingConfirmationIsRefused(): void
    {
        $tester = $this->tester();
        $tester->setInputs([self::PASSWORD, self::PASSWORD . 'x']);

        self::assertSame(Command::INVALID, $tester->execute(['email' => 'driver@example.test']));
        self::assertCount(0, $this->users()->findAll());
    }

    public function testASecondUserIsRefused(): void
    {
        $first = $this->tester();
        $first->setInputs([self::PASSWORD, self::PASSWORD]);
        $first->execute(['email' => 'driver@example.test']);

        $second = $this->tester();
        $second->setInputs([self::PASSWORD, self::PASSWORD]);
        $status = $second->execute(['email' => 'other@example.test']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('single user', $second->getDisplay());
        self::assertCount(1, $this->users()->findAll());
    }

    public function testLocaleAndTimeZoneOptions(): void
    {
        $tester = $this->tester();
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $status = $tester->execute(['email' => 'driver@example.test', '--locale' => 'fr', '--time-zone' => 'Europe/Zurich']);

        self::assertSame(Command::SUCCESS, $status);
        $user = $this->users()->findOneBy([]) ?? self::fail();
        self::assertSame('fr', $user->getLocale());
        self::assertSame('Europe/Zurich', $user->getTimeZone());
    }

    public function testAnUnknownLocaleOrTimeZoneIsRefused(): void
    {
        $tester = $this->tester();
        $tester->setInputs([self::PASSWORD, self::PASSWORD, self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::INVALID, $tester->execute(['email' => 'driver@example.test', '--locale' => 'de']));
        self::assertSame(Command::INVALID, $tester->execute(['email' => 'driver@example.test', '--time-zone' => 'Mars/Olympus']));
        self::assertCount(0, $this->users()->findAll());
    }

    public function testVehiclesWithoutOwnerBelongToTheNewUser(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000090'), 'Model 3');
        $entityManager->persist($vehicle);
        $entityManager->flush();
        $tester = $this->tester();
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $tester->execute(['email' => 'driver@example.test']);

        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->find(Vehicle::class, $vehicle->getId());
        self::assertSame('driver@example.test', $reloaded?->getOwner()?->getEmail());
    }

    private function users(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('polaris:user:create'));
    }
}
