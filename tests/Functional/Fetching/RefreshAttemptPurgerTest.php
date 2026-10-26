<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Fetching;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Fetching\Domain\RefreshOutcome;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\Message\PurgeRefreshAttempts;
use Polaris\Fetching\MessageHandler\PurgeRefreshAttemptsHandler;
use Polaris\Fetching\Scheduler\FetchScheduleProvider;
use Polaris\Fetching\Service\RefreshAttemptPurger;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Scheduler\RecurringMessage;

#[CoversClass(RefreshAttemptPurger::class)]
#[CoversClass(PurgeRefreshAttemptsHandler::class)]
#[CoversClass(FetchScheduleProvider::class)]
final class RefreshAttemptPurgerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    public function testOnlyAttemptsOlderThanTheRetentionAreDeleted(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-26 12:00:00 UTC');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000401'), 'Model 3', self::createUser());
        $entityManager->persist($vehicle);
        $now = CarbonImmutable::parse('2026-10-26 12:00:00', 'UTC');
        $old = new RefreshAttempt($vehicle, $now->subHours(25));
        $old->settle(RefreshOutcome::Unchanged, $now->subHours(25));
        $stuck = new RefreshAttempt($vehicle, $now->subHours(30));
        $recent = new RefreshAttempt($vehicle, $now->subHours(23));
        array_map($entityManager->persist(...), [$old, $stuck, $recent]);
        $entityManager->flush();
        $recentId = $recent->getId();

        $deleted = self::getContainer()->get(RefreshAttemptPurger::class)->purge();

        self::assertSame(2, $deleted, 'A settled attempt and one the worker never settled.');
        $entityManager->clear();
        $left = $entityManager->getRepository(RefreshAttempt::class)->findAll();
        self::assertCount(1, $left);
        self::assertTrue($recentId->equals($left[0]->getId()));
    }

    public function testTheHandlerAndTheScheduleRunThePurge(): void
    {
        self::bootKernel();
        self::getContainer()->get(PurgeRefreshAttemptsHandler::class)(new PurgeRefreshAttempts());

        $triggers = array_map(
            static fn (RecurringMessage $recurring): string => (string) $recurring->getTrigger(),
            self::getContainer()->get(FetchScheduleProvider::class)->getSchedule()->getRecurringMessages(),
        );
        self::assertCount(2, $triggers, 'The fetch tick and the daily purge.');
        self::assertCount(1, array_filter($triggers, static fn (string $trigger): bool => str_contains($trigger, FetchScheduleProvider::PURGE)));
    }
}
