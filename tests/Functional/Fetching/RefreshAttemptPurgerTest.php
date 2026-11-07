<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Fetching;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Message\OwnerMidnightPassed;
use Polaris\Fetching\Domain\RefreshOutcome;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\MessageHandler\PurgeOwnerRefreshAttemptsHandler;
use Polaris\Fetching\Scheduler\FetchScheduleProvider;
use Polaris\Fetching\Service\RefreshAttemptPurger;
use Polaris\Tesla\Service\Enum\NoReadingReason;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Scheduler\RecurringMessage;

#[CoversClass(RefreshAttemptPurger::class)]
#[CoversClass(PurgeOwnerRefreshAttemptsHandler::class)]
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
        $owner = self::createUser();
        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000401'), 'Model 3', $owner);
        $entityManager->persist($vehicle);
        $now = CarbonImmutable::parse('2026-10-26 12:00:00', 'UTC');
        $old = new RefreshAttempt($vehicle, $now->subHours(25));
        $old->settle(RefreshOutcome::Unchanged, $now->subHours(25));
        $stuck = new RefreshAttempt($vehicle, $now->subHours(30));
        $recent = new RefreshAttempt($vehicle, $now->subHours(23));
        $strangerVehicle = new Vehicle(new Vin('5YJ3E7EB2NF000402'), 'Model 3', self::createUser('other@example.test'));
        $strangers = new RefreshAttempt($strangerVehicle, $now->subHours(30));
        array_map($entityManager->persist(...), [$old, $stuck, $recent, $strangerVehicle, $strangers]);
        $entityManager->flush();
        $recentId = $recent->getId();

        $deleted = self::getContainer()->get(RefreshAttemptPurger::class)->purgeOf($owner->getId());

        self::assertSame(2, $deleted, 'A settled attempt and one the worker never settled, both of this owner.');
        $entityManager->clear();
        $left = $entityManager->getRepository(RefreshAttempt::class)->findAll();
        self::assertCount(2, $left, 'The recent one, and the old one of another owner (purged at their own midnight).');
        $leftIds = array_map(static fn (RefreshAttempt $attempt): string => $attempt->getId()->toRfc4122(), $left);
        self::assertContains($recentId->toRfc4122(), $leftIds);
    }

    public function testAnAttemptWhoseWakeIsYoungerThanTheRetentionIsKeptForTheWakeCap(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-26 12:00:00 UTC');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $owner = self::createUser();
        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000403'), 'Model 3', $owner);
        $entityManager->persist($vehicle);
        $now = CarbonImmutable::parse('2026-10-26 12:00:00', 'UTC');
        // Requested 24 h 5 min ago, the wake was confirmed 8 minutes later: still counted for 23 h 57 min.
        $attempt = new RefreshAttempt($vehicle, $now->subHours(24)->subMinutes(5));
        $attempt->markWoken($now->subHours(23)->subMinutes(57), NoReadingReason::Asleep);
        $entityManager->persist($attempt);
        $entityManager->flush();

        $deleted = self::getContainer()->get(RefreshAttemptPurger::class)->purgeOf($owner->getId());

        self::assertSame(0, $deleted);
    }

    public function testTheHandlerRunsThePurgeAndTheScheduleHasNoDailyEntry(): void
    {
        self::bootKernel();
        self::getContainer()->get(PurgeOwnerRefreshAttemptsHandler::class)(new OwnerMidnightPassed(self::createUser()->getId()->toRfc4122(), '2026-10-26', 0));

        $triggers = array_map(
            static fn (RecurringMessage $recurring): string => (string) $recurring->getTrigger(),
            self::getContainer()->get(FetchScheduleProvider::class)->getSchedule()->getRecurringMessages(),
        );
        self::assertSame(['* * * * *', '* * * * *'], $triggers, 'The fetch tick and the owners\' midnight tick: no daily entry at a UTC time.');
    }
}
