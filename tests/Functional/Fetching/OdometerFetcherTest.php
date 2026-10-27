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
use Polaris\Fetching\Service\ManualRefreshLimit;
use Polaris\Fetching\Service\OdometerFetcher;
use Polaris\Reading\Domain\ReadingSource;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Reading\Entity\VehicleDataSnapshot;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Polaris\Tests\Support\ToggledRefreshPolicy;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(OdometerFetcher::class)]
final class OdometerFetcherTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;
    use TeslaFixtures;

    private const string SLOT = '2026-10-25 19:00:00';

    private Vehicle $vehicle;

    private ToggledRefreshPolicy $hourlyLimit;

    /**
     * @var list<string> "METHOD url" of every request sent to Tesla
     */
    private array $requests = [];

    private string $state = 'online';

    private float $miles = 0.0;

    private int $timestampMs = FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS;

    /**
     * Whether the live call answers 408, as a car that fell asleep after reporting itself online.
     */
    private bool $fallsAsleep = false;

    /**
     * Whether Tesla answers every call with 400.
     */
    private bool $teslaRefuses = false;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(self::SLOT . ' UTC');
        $owner = self::createUser();
        $app = self::createConfiguredApp($owner);
        self::createToken($app, CarbonImmutable::parse(self::SLOT, 'UTC')->addHours(8));
        self::getContainer()->set('http_client', new MockHttpClient($this->respond(...)));
        $this->hourlyLimit = new ToggledRefreshPolicy();
        self::getContainer()->set(
            ManualRefreshLimit::class,
            new ManualRefreshLimit(
                $this->hourlyLimit,
                new RateLimiterFactory(['id' => 'manual_refresh', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage()),
            ),
        );
        $this->vehicle = new Vehicle(new Vin(FleetApiResponses::VIN), 'Model 3', $owner);
        $this->entityManager()->persist($this->vehicle);
        $this->entityManager()->persist(new FetchSlotRun($this->vehicle, self::slot(), self::slot()));
        $this->entityManager()->flush();
    }

    public function testAnOnlineCarWithANewValueIsStored(): void
    {
        $this->answerWith('online', 100.0);

        $status = $this->fetch(0);

        self::assertSame(SlotRunStatus::Stored, $status);
        $readings = $this->entityManager()->getRepository(OdometerReading::class)->findAll();
        self::assertCount(1, $readings);
        self::assertSame(ReadingSource::Api, $readings[0]->getSource());
        self::assertSame(160_934, $readings[0]->getOdometer()->metres());
        self::assertSame('100.000', $readings[0]->getOdometerRaw());
        self::assertSame('2026-10-24T17:53:45+00:00', $readings[0]->getReadAt()->toIso8601String());
        self::assertSame(SlotRunStatus::Stored, $this->slotRun()->getStatus());
        self::assertSame(1, $this->slotRun()->getAttempts());
        self::assertSame([], $this->queued());
    }

    public function testWhatTheCarAnsweredIsKeptWithoutItsLocationOnceForEachInstant(): void
    {
        $this->answerWith('online', 100.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS);
        $this->fetch(0);
        $this->answerWith('online', 100.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS);
        $this->fetch(0, 1);
        $this->answerWith('online', 100.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS + 3_600_000);
        $this->fetch(0, 2);

        $this->entityManager()->clear();
        $snapshots = $this->entityManager()->getRepository(VehicleDataSnapshot::class)->findBy([], ['capturedAt' => 'ASC']);
        self::assertCount(2, $snapshots, 'The same instant twice is one row; an unchanged odometer at a new instant is a new one.');
        self::assertSame('2026-10-24T17:53:45+00:00', $snapshots[0]->getCapturedAt()->toIso8601String());
        $payload = $snapshots[0]->getPayload();
        $charge = $payload['charge_state'] ?? null;
        $vehicle = $payload['vehicle_state'] ?? null;
        self::assertIsArray($charge);
        self::assertIsArray($vehicle);
        self::assertSame(76, $charge['battery_level'] ?? null);
        self::assertEquals(100, $vehicle['odometer'] ?? null, 'JSON does not tell 100 from 100.0.');
        self::assertSame(['shift_state' => null], $payload['drive_state'] ?? null);
        self::assertStringNotContainsString('12.3456', json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    public function testNothingIsKeptWhenTheCarIsNotRead(): void
    {
        $this->answerWith('asleep');
        $this->fetch(0);

        self::assertSame([], $this->entityManager()->getRepository(VehicleDataSnapshot::class)->findAll());
    }

    public function testAnOnlineCarWithTheSameValueIsNotStoredAgain(): void
    {
        $this->answerWith('online', 100.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS);
        $this->fetch(0);
        $this->answerWith('online', 100.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS + 3_600_000);

        $status = $this->fetch(0, 1);

        self::assertSame(SlotRunStatus::Unchanged, $status);
        self::assertCount(1, $this->entityManager()->getRepository(OdometerReading::class)->findAll());
    }

    public function testAnOnlineCarWithAHigherValueIsStoredBesideThePreviousOne(): void
    {
        $this->answerWith('online', 100.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS);
        $this->fetch(0);
        $this->answerWith('online', 112.5, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS + 3_600_000);

        self::assertSame(SlotRunStatus::Stored, $this->fetch(0, 1));
        self::assertCount(2, $this->entityManager()->getRepository(OdometerReading::class)->findAll());
    }

    public function testAValueLowerThanTheLastStoredReadingIsRejectedAndNothingIsRetried(): void
    {
        $this->answerWith('online', 100.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS);
        $this->fetch(0);
        $this->answerWith('online', 50.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS + 3_600_000);

        $status = $this->fetch(0, 1);

        self::assertSame(SlotRunStatus::Rejected, $status);
        self::assertSame(SlotRunStatus::Rejected, $this->slotRun()->getStatus());
        self::assertCount(1, $this->entityManager()->getRepository(OdometerReading::class)->findAll());
        self::assertSame([], $this->queued());
    }

    public function testAnAsleepCarIsCheckedAgainAnHourLaterAndStoredOnceOnline(): void
    {
        $this->answerWith('asleep');

        self::assertSame(SlotRunStatus::Retrying, $this->fetch(0));

        $queued = $this->queued();
        self::assertCount(1, $queued);
        $retry = $queued[0]->getMessage();
        self::assertInstanceOf(FetchOdometerMessage::class, $retry);
        self::assertSame(1, $retry->attempt);
        self::assertSame(CarbonImmutable::parse(self::SLOT, 'UTC')->getTimestamp(), $retry->slotTimestamp);
        self::assertSame(3_600_000, $queued[0]->last(DelayStamp::class)?->getDelay());
        self::assertSame(SlotRunStatus::Retrying, $this->slotRun()->getStatus());

        $this->answerWith('online', 100.0);

        self::assertSame(SlotRunStatus::Stored, $this->fetch(1));
        self::assertCount(1, $this->entityManager()->getRepository(OdometerReading::class)->findAll());
        self::assertSame(2, $this->slotRun()->getAttempts());
    }

    public function testALateFirstCheckDoesNotShiftTheNextOnes(): void
    {
        self::mockTime('2026-10-25 19:04:00 UTC');
        $this->answerWith('asleep');

        $this->fetch(0);

        self::assertSame(3_360_000, $this->queued()[0]->last(DelayStamp::class)?->getDelay(), 'The next check is at 20:00, an hour after the slot.');
    }

    public function testACarThatStaysAsleepForTheWholeWindowIsSkippedAfterFourChecksWithoutWaking(): void
    {
        $this->answerWith('asleep');

        $statuses = [];
        foreach ([0, 1, 2, 3] as $attempt) {
            $statuses[] = $this->fetch($attempt);
        }

        self::assertSame([SlotRunStatus::Retrying, SlotRunStatus::Retrying, SlotRunStatus::Retrying, SlotRunStatus::Skipped], $statuses);
        self::assertCount(3, $this->queued(), 'The last check queues nothing.');
        self::assertSame(SlotRunStatus::Skipped, $this->slotRun()->getStatus());
        self::assertSame(4, $this->slotRun()->getAttempts());
        self::assertCount(4, $this->requests);
        foreach ($this->requests as $request) {
            self::assertStringStartsWith('GET ', $request);
            self::assertStringNotContainsString('wake_up', $request);
            self::assertStringNotContainsString('vehicle_data', $request);
        }
        self::assertSame([], $this->entityManager()->getRepository(OdometerReading::class)->findAll());
    }

    public function testAnOfflineCarIsHandledLikeAnAsleepOne(): void
    {
        $this->answerWith('offline');

        self::assertSame(SlotRunStatus::Retrying, $this->fetch(0));
        self::assertCount(1, $this->requests);
    }

    public function testACarThatFallsAsleepBetweenTheTwoCallsIsCheckedAgain(): void
    {
        $this->answerWith('online', 100.0);
        $this->fallsAsleep = true;

        self::assertSame(SlotRunStatus::Retrying, $this->fetch(0));
        self::assertCount(2, $this->requests, '408 is not retried.');
        self::assertCount(1, $this->queued());
    }

    public function testWithoutAConnectionNothingIsAskedAndTheSlotEndsNotConnected(): void
    {
        $this->entityManager()->createQuery('DELETE FROM ' . \Polaris\Tesla\Entity\OAuthToken::class)->execute();
        $this->answerWith('online', 100.0);

        self::assertSame(SlotRunStatus::NotConnected, $this->fetch(0));
        self::assertSame([], $this->requests);
        self::assertSame([], $this->queued());
        self::assertSame(SlotRunStatus::NotConnected, $this->slotRun()->getStatus());
    }

    public function testAFetchWithoutASlotIsNeverRetried(): void
    {
        $this->answerWith('asleep');

        $status = self::getContainer()->get(OdometerFetcher::class)->fetch(new FetchOdometerMessage($this->vehicle->getId()->toRfc4122()));

        self::assertSame(SlotRunStatus::Skipped, $status);
        self::assertSame([], $this->queued());
    }

    public function testASuccessfulReadDropsTheSlotsStillWaitingForACheck(): void
    {
        $earlier = new FetchSlotRun($this->vehicle, self::slot()->subMinutes(10), self::slot());
        $this->entityManager()->persist($earlier);
        $this->entityManager()->flush();
        $earlierId = $earlier->getId();
        $this->answerWith('online', 100.0);

        self::assertSame(SlotRunStatus::Stored, $this->fetch(0));

        $this->entityManager()->clear();
        $dropped = $this->entityManager()->find(FetchSlotRun::class, $earlierId);
        self::assertSame(SlotRunStatus::Superseded, $dropped?->getStatus());
    }

    public function testACheckQueuedForASupersededSlotDoesNotAskTheCar(): void
    {
        $this->answerWith('asleep');
        $this->fetch(0);
        $this->answerWith('online', 100.0);
        $this->requests = [];
        $this->entityManager()->createQuery('UPDATE ' . FetchSlotRun::class . ' r SET r.status = :status')->setParameter('status', SlotRunStatus::Superseded)->execute();
        $this->entityManager()->clear();

        self::assertSame(SlotRunStatus::Superseded, $this->fetch(1));
        self::assertSame([], $this->requests);
        self::assertSame([], $this->entityManager()->getRepository(OdometerReading::class)->findAll());
    }

    public function testAVehicleThatNoLongerExistsIsDropped(): void
    {
        $status = self::getContainer()->get(OdometerFetcher::class)->fetch(new FetchOdometerMessage('01JAAAAAAAAAAAAAAAAAAAAAAA', self::slot()->getTimestamp()));

        self::assertSame(SlotRunStatus::Skipped, $status);
        self::assertSame([], $this->requests);
    }

    public function testASuccessfulReadDeletesTheSlotsOlderThanADay(): void
    {
        $this->entityManager()->persist(new FetchSlotRun($this->vehicle, self::slot()->subHours(25), self::slot()->subHours(25)));
        $this->entityManager()->persist(new FetchSlotRun($this->vehicle, self::slot()->subHours(23), self::slot()->subHours(23)));
        $this->entityManager()->flush();
        $this->answerWith('online', 100.0);

        self::assertSame(SlotRunStatus::Stored, $this->fetch(0));

        $this->entityManager()->clear();
        $slots = array_map(
            static fn (FetchSlotRun $run): string => $run->getSlotAt()->toDateTimeString(),
            $this->entityManager()->getRepository(FetchSlotRun::class)->findBy([], ['slotAt' => 'ASC']),
        );
        self::assertSame([self::slot()->subHours(23)->toDateTimeString(), self::slot()->toDateTimeString()], $slots);
    }

    public function testNothingIsDeletedWhenTheCarDoesNotAnswer(): void
    {
        $this->entityManager()->persist(new FetchSlotRun($this->vehicle, self::slot()->subHours(25), self::slot()->subHours(25)));
        $this->entityManager()->flush();
        $this->answerWith('asleep');

        $this->fetch(0);

        $this->entityManager()->clear();
        self::assertCount(2, $this->entityManager()->getRepository(FetchSlotRun::class)->findAll());
    }

    public function testAManualRefreshOfAnAsleepCarDoesNotWakeItAndKeepsTheLastReading(): void
    {
        $this->answerWith('online', 100.0);
        $this->fetch(0);
        $this->requests = [];
        $this->answerWith('asleep');

        [$status, $outcome] = $this->refresh();

        self::assertSame(SlotRunStatus::Skipped, $status);
        self::assertSame(RefreshOutcome::Asleep, $outcome);
        self::assertSame([], $this->queued());
        self::assertCount(1, $this->requests);
        self::assertStringStartsWith('GET ', $this->requests[0]);
        self::assertStringNotContainsString('wake_up', $this->requests[0]);
        $readings = $this->entityManager()->getRepository(OdometerReading::class)->findAll();
        self::assertCount(1, $readings);
        self::assertSame(160_934, $readings[0]->getOdometer()->metres());
    }

    public function testACarFoundAsleepDoesNotSpendTheHourAndTheNextRefreshOfAnOnlineCarDoes(): void
    {
        $this->useHourlyLimit(true);
        $this->answerWith('asleep');
        self::assertSame(RefreshOutcome::Asleep, $this->refresh()[1]);
        self::assertSame(RefreshOutcome::Asleep, $this->refresh()[1], 'Looking at a sleeping car is free, as often as asked.');

        $this->answerWith('online', 100.0);
        $this->forgetRequests();

        self::assertSame(RefreshOutcome::Stored, $this->refresh()[1]);
        self::assertCount(2, $this->requests, 'The state, then the billed live read.');
    }

    public function testASecondReadWithinTheHourIsRefusedBeforeTheCarIsAskedForItsData(): void
    {
        $this->useHourlyLimit(true);
        $this->answerWith('online', 100.0);
        $this->refresh();
        $this->requests = [];
        $this->answerWith('online', 120.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS + 60_000);

        [$status, $outcome] = $this->refresh();

        self::assertSame(SlotRunStatus::Skipped, $status);
        self::assertSame(RefreshOutcome::Limited, $outcome);
        self::assertCount(1, $this->requests, 'Only the free state check was sent.');
        self::assertStringEndsNotWith('/vehicle_data', $this->requests[0]);
        self::assertCount(1, $this->entityManager()->getRepository(OdometerReading::class)->findAll());
    }

    public function testAScheduledReadIsNeitherHeldByNorSpendsTheManualHour(): void
    {
        $this->useHourlyLimit(true);
        $this->answerWith('online', 100.0);
        $this->refresh();
        $this->answerWith('online', 120.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS + 3_600_000);

        self::assertSame(SlotRunStatus::Stored, $this->fetch(0, 1), 'The scheduled read of a later slot goes through.');

        $this->answerWith('online', 130.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS + 7_200_000);
        self::assertSame(RefreshOutcome::Limited, $this->refresh()[1], 'It did not give the manual refresh a new hour.');
    }

    public function testAManualRefreshOfAnOfflineCarSaysSo(): void
    {
        $this->answerWith('offline');

        self::assertSame(RefreshOutcome::Offline, $this->refresh()[1]);
    }

    public function testAManualRefreshOfAnOnlineCarStoresTheNewValue(): void
    {
        $this->answerWith('online', 100.0);

        [$status, $outcome] = $this->refresh();

        self::assertSame(SlotRunStatus::Stored, $status);
        self::assertSame(RefreshOutcome::Stored, $outcome);
        self::assertCount(1, $this->entityManager()->getRepository(OdometerReading::class)->findAll());
    }

    public function testAManualRefreshThatFindsTheSameValueIsUnchanged(): void
    {
        $this->answerWith('online', 100.0);
        $this->refresh();

        self::assertSame(RefreshOutcome::Unchanged, $this->refresh()[1]);
    }

    public function testAManualRefreshOfACarThatFallsAsleepBetweenTheTwoCallsIsUnavailable(): void
    {
        $this->answerWith('online', 100.0);
        $this->fallsAsleep = true;

        self::assertSame(RefreshOutcome::Unavailable, $this->refresh()[1]);
        self::assertCount(2, $this->requests, '408 is not retried.');
        self::assertSame([], $this->queued());
    }

    public function testAManualRefreshWithoutAConnectionIsNotConnected(): void
    {
        $this->entityManager()->createQuery('DELETE FROM ' . \Polaris\Tesla\Entity\OAuthToken::class)->execute();

        self::assertSame(RefreshOutcome::NotConnected, $this->refresh()[1]);
        self::assertSame([], $this->requests);
    }

    public function testAManualRefreshThatTeslaRefusesIsFailedAndTheErrorGoesOn(): void
    {
        $this->teslaRefuses = true;
        $attempt = $this->newAttempt();

        try {
            self::getContainer()->get(OdometerFetcher::class)->fetch(new FetchOdometerMessage($this->vehicle->getId()->toRfc4122(), null, 0, $attempt->getId()->toRfc4122()));
            self::fail('The Tesla error must reach the failure transport.');
        } catch (FleetApiError) {
            self::assertSame(RefreshOutcome::Failed, $this->outcomeOf($attempt));
        }
    }

    public function testAManualRefreshWhileAnotherFetchRunsIsBusyAndAsksNothing(): void
    {
        $this->answerWith('online', 100.0);
        $lock = $this->holdLock();

        [$status, $outcome] = $this->refresh();
        $lock->release();

        self::assertSame(SlotRunStatus::Skipped, $status);
        self::assertSame(RefreshOutcome::Busy, $outcome);
        self::assertSame([], $this->requests);
        self::assertSame([], $this->entityManager()->getRepository(OdometerReading::class)->findAll());
    }

    public function testAScheduledCheckWhileAnotherFetchRunsIsQueuedAgainWithoutUsingACheck(): void
    {
        $this->answerWith('online', 100.0);
        $lock = $this->holdLock();

        $status = $this->fetch(0);
        $lock->release();

        self::assertSame(SlotRunStatus::Retrying, $status);
        self::assertSame([], $this->requests);
        $queued = $this->queued();
        self::assertCount(1, $queued);
        self::assertSame(30_000, $queued[0]->last(DelayStamp::class)?->getDelay());
        $retry = $queued[0]->getMessage();
        self::assertInstanceOf(FetchOdometerMessage::class, $retry);
        self::assertSame(0, $retry->attempt);
        self::assertSame(SlotRunStatus::Dispatched, $this->slotRun()->getStatus());
    }

    public function testTheLockIsReleasedAfterEachFetch(): void
    {
        $this->answerWith('asleep');
        $this->refresh();

        // holdLock() fails the test when the lock is still held.
        $this->holdLock()->release();
    }

    /**
     * @return array{SlotRunStatus, RefreshOutcome}
     */
    private function refresh(): array
    {
        $attempt = $this->newAttempt();
        $status = self::getContainer()->get(OdometerFetcher::class)->fetch(
            new FetchOdometerMessage($this->vehicle->getId()->toRfc4122(), null, 0, $attempt->getId()->toRfc4122()),
        );

        return [$status, $this->outcomeOf($attempt)];
    }

    private function newAttempt(): RefreshAttempt
    {
        $attempt = new RefreshAttempt($this->vehicle, self::slot());
        $this->entityManager()->persist($attempt);
        $this->entityManager()->flush();

        return $attempt;
    }

    private function outcomeOf(RefreshAttempt $attempt): RefreshOutcome
    {
        $this->entityManager()->refresh($attempt);

        return $attempt->getOutcome();
    }

    /**
     * Turns the hourly limit of the manual refresh on (it is off by default, so that a test can refresh twice).
     */
    private function useHourlyLimit(bool $limited): void
    {
        $this->hourlyLimit->limited = $limited;
    }

    private function forgetRequests(): void
    {
        $this->requests = [];
    }

    private function holdLock(): \Symfony\Component\Lock\SharedLockInterface
    {
        $lock = self::getContainer()->get(LockFactory::class)->createLock('odometer_fetch_' . $this->vehicle->getId()->toBase32(), 60);
        self::assertTrue($lock->acquire(), 'The test could not take the lock.');

        return $lock;
    }

    /**
     * @param int $hoursLater fetches a later slot (its run is created), as the next day's slot would
     */
    private function fetch(int $attempt, int $hoursLater = 0): SlotRunStatus
    {
        $slot = self::slot()->addHours($hoursLater);

        if ($hoursLater > 0) {
            $this->entityManager()->persist(new FetchSlotRun($this->vehicle, $slot, $slot));
            $this->entityManager()->flush();
        }

        return self::getContainer()->get(OdometerFetcher::class)->fetch(
            new FetchOdometerMessage($this->vehicle->getId()->toRfc4122(), $slot->getTimestamp(), $attempt),
        );
    }

    private function answerWith(string $state, ?float $miles = null, int $timestampMs = FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS): void
    {
        $this->state = $state;
        $this->miles = $miles ?? 0.0;
        $this->timestampMs = $timestampMs;
    }

    private function respond(string $method, string $url): MockResponse
    {
        $this->requests[] = $method . ' ' . $url;

        if ($this->teslaRefuses) {
            return new MockResponse('{}', ['http_code' => 400]);
        }

        if (!str_ends_with($url, '/vehicle_data')) {
            return new MockResponse(FleetApiResponses::vehicle($this->state));
        }

        return $this->fallsAsleep
            ? new MockResponse(FleetApiResponses::vehicleNotOnline(), ['http_code' => 408])
            : new MockResponse(FleetApiResponses::vehicleData($this->miles, $this->timestampMs));
    }

    /**
     * @return list<Envelope>
     */
    private function queued(): array
    {
        $transport = self::getContainer()->get('messenger.transport.tesla');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values($transport->getSent());
    }

    private function slotRun(): FetchSlotRun
    {
        $this->entityManager()->clear();
        $run = $this->entityManager()->getRepository(FetchSlotRun::class)->findOneBy([], ['slotAt' => 'DESC']);
        self::assertInstanceOf(FetchSlotRun::class, $run);

        return $run;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private static function slot(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::SLOT, 'UTC');
    }
}
