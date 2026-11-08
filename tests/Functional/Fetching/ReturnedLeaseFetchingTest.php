<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Fetching;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Fetching\Domain\RefreshOutcome;
use Polaris\Fetching\Domain\SlotRunStatus;
use Polaris\Fetching\Entity\FetchSlotRun;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Service\DueFetchDispatcher;
use Polaris\Fetching\Service\Enum\RefreshRequestStatus;
use Polaris\Fetching\Service\Enum\WakeRequestStatus;
use Polaris\Fetching\Service\ManualRefreshService;
use Polaris\Fetching\Service\OdometerFetcher;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A car given back to the bank is not read any more: no slot, no manual refresh, no wake, and not a single call to
 * Tesla, not even for a message queued before the return.
 */
#[CoversClass(DueFetchDispatcher::class)]
#[CoversClass(ManualRefreshService::class)]
#[CoversClass(OdometerFetcher::class)]
final class ReturnedLeaseFetchingTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;
    use TeslaFixtures;

    private Vehicle $vehicle;

    /**
     * @var list<string> "METHOD url" of every request sent to Tesla
     */
    private array $requests = [];

    protected function setUp(): void
    {
        self::bootKernel();
        // The default slot, 21:00 in UTC for a user who never changed their time zone.
        self::mockTime('2026-10-25 21:02:00 UTC');
        $owner = self::createUser();
        $app = self::createConfiguredApp($owner);
        self::createToken($app, CarbonImmutable::parse('2026-10-26 12:00:00', 'UTC'));
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = $method . ' ' . $url;

            $answer = str_ends_with($url, '/vehicle_data')
                ? FleetApiResponses::vehicleData(100.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS)
                : FleetApiResponses::vehicle('online');

            return new MockResponse($answer);
        });
        self::getContainer()->set('http_client', $client);

        $this->vehicle = new Vehicle(new Vin(FleetApiResponses::VIN), 'Model 3', $owner);
        $this->entityManager()->persist($this->vehicle);
        $this->entityManager()->persist(new LeaseContract(
            $this->vehicle,
            LeaseTerm::fromCalendarDates(
                CarbonImmutable::parse('2026-01-01', 'UTC'),
                CarbonImmutable::parse('2027-01-01', 'UTC'),
                EndDateConvention::Exclusive,
            ),
            Allowance::ofTotal(Distance::fromKilometres(15_000)),
            Distance::fromKilometres(0),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent(0),
        ));
        $this->entityManager()->persist(
            OdometerReading::manual(
                $this->vehicle,
                CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'),
                Distance::fromKilometres(9_000),
            )
        );
        $this->entityManager()->flush();
    }

    public function testTheDispatcherQueuesNothingAndWritesNoSlotForAReturnedVehicle(): void
    {
        $this->leases()->markReturned($this->vehicle, new \DateTimeImmutable('2026-10-24'));

        self::assertSame(0, $this->dispatcher()->dispatch());
        self::assertSame([], $this->queued());
        self::assertSame([], $this->entityManager()->getRepository(FetchSlotRun::class)->findAll());
    }

    public function testFetchingResumesAtTheNextSlotOnceTheReturnIsUndone(): void
    {
        $this->leases()->markReturned($this->vehicle, new \DateTimeImmutable('2026-10-24'));
        self::assertSame(0, $this->dispatcher()->dispatch());

        // The window of the slot of the day closed (3 hours): no catch-up, the next slot is the next day.
        self::mockTime('2026-10-26 00:30:00 UTC');
        $this->leases()->undoReturn($this->vehicle);
        self::assertSame(0, $this->dispatcher()->dispatch());

        self::mockTime('2026-10-26 21:01:00 UTC');
        self::assertSame(1, $this->dispatcher()->dispatch());
        self::assertCount(1, $this->queued());
    }

    public function testAManualRefreshIsRefusedAndAsksNothing(): void
    {
        $this->leases()->markReturned($this->vehicle, new \DateTimeImmutable('2026-10-24'));

        $result = $this->refreshes()->request($this->vehicle);

        self::assertSame(RefreshRequestStatus::Returned, $result->status);
        self::assertFalse($result->isAccepted());
        self::assertNull($result->attemptId);
        self::assertSame([], $this->entityManager()->getRepository(RefreshAttempt::class)->findAll());
        self::assertSame([], $this->queued());
    }

    public function testAWakeIsRefusedWhetherOfferedOrRequested(): void
    {
        $attempt = new RefreshAttempt($this->vehicle, CarbonImmutable::parse('2026-10-25 21:00:00', 'UTC'));
        $attempt->settle(RefreshOutcome::Asleep, CarbonImmutable::parse('2026-10-25 21:01:00', 'UTC'));
        $this->entityManager()->persist($attempt);
        $this->entityManager()->flush();
        $this->leases()->markReturned($this->vehicle, new \DateTimeImmutable('2026-10-24'));
        $id = $attempt->getId()->toRfc4122();

        self::assertSame(WakeRequestStatus::Returned, $this->refreshes()->wakeOffer($this->vehicle, $id)->status);
        self::assertSame(WakeRequestStatus::Returned, $this->refreshes()->requestWake($this->vehicle, $id)->status);
        self::assertSame([], $this->queued());
    }

    public function testAScheduledMessageQueuedBeforeTheReturnMakesNoTeslaCall(): void
    {
        $slot = CarbonImmutable::parse('2026-10-25 21:00:00', 'UTC');
        $this->entityManager()->persist(new FetchSlotRun($this->vehicle, $slot, $slot));
        $this->entityManager()->flush();
        $this->leases()->markReturned($this->vehicle, new \DateTimeImmutable('2026-10-24'));

        $status = self::getContainer()->get(OdometerFetcher::class)->fetch(
            new FetchOdometerMessage($this->vehicle->getId()->toRfc4122(), $slot->getTimestamp())
        );

        self::assertSame(SlotRunStatus::Skipped, $status);
        self::assertSame([], $this->requests);
        $this->entityManager()->clear();
        $run = $this->entityManager()->getRepository(FetchSlotRun::class)->findOneBy([]);
        self::assertSame(SlotRunStatus::Skipped, $run?->getStatus());
        self::assertCount(1, $this->entityManager()->getRepository(OdometerReading::class)->findAll());
    }

    public function testAManualMessageQueuedBeforeTheReturnSettlesTheRefreshAsReturned(): void
    {
        $accepted = $this->refreshes()->request($this->vehicle);
        self::assertTrue($accepted->isAccepted());
        $attemptId = $accepted->attemptId?->toRfc4122();
        self::assertNotNull($attemptId);
        $this->leases()->markReturned($this->vehicle, new \DateTimeImmutable('2026-10-24'));

        $status = self::getContainer()->get(OdometerFetcher::class)->fetch(
            new FetchOdometerMessage($this->vehicle->getId()->toRfc4122(), null, 0, $attemptId)
        );

        self::assertSame(SlotRunStatus::Skipped, $status);
        self::assertSame([], $this->requests);
        $this->entityManager()->clear();
        $attempt = $this->entityManager()->getRepository(RefreshAttempt::class)->findOneBy([]);
        self::assertSame(RefreshOutcome::Returned, $attempt?->getOutcome());
        self::assertTrue($attempt->getOutcome()->isSettled());
    }

    private function leases(): LeaseTermsService
    {
        return self::getContainer()->get(LeaseTermsService::class);
    }

    private function dispatcher(): DueFetchDispatcher
    {
        return self::getContainer()->get(DueFetchDispatcher::class);
    }

    private function refreshes(): ManualRefreshService
    {
        return self::getContainer()->get(ManualRefreshService::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return list<\Symfony\Component\Messenger\Envelope>
     */
    private function queued(): array
    {
        $transport = self::getContainer()->get('messenger.transport.tesla');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values($transport->getSent());
    }
}
