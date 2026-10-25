<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Fetching;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Fetching\Domain\SlotRunStatus;
use Polaris\Fetching\Entity\FetchSlotRun;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Service\OdometerFetcher;
use Polaris\Reading\Domain\ReadingSource;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(OdometerFetcher::class)]
final class OdometerFetcherTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;
    use TeslaFixtures;

    private const string SLOT = '2026-10-25 19:00:00';

    private Vehicle $vehicle;

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

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(self::SLOT . ' UTC');
        $owner = self::createUser();
        $app = self::createConfiguredApp($owner);
        self::createToken($app, CarbonImmutable::parse(self::SLOT, 'UTC')->addHours(8));
        self::getContainer()->set('http_client', new MockHttpClient($this->respond(...)));
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

    public function testAnOnlineCarWithTheSameValueIsNotStoredAgain(): void
    {
        $this->answerWith('online', 100.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS);
        $this->fetch(0);
        $this->answerWith('online', 100.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS + 3_600_000);

        $status = $this->fetch(0);

        self::assertSame(SlotRunStatus::Unchanged, $status);
        self::assertCount(1, $this->entityManager()->getRepository(OdometerReading::class)->findAll());
    }

    public function testAnOnlineCarWithAHigherValueIsStoredBesideThePreviousOne(): void
    {
        $this->answerWith('online', 100.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS);
        $this->fetch(0);
        $this->answerWith('online', 112.5, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS + 3_600_000);

        self::assertSame(SlotRunStatus::Stored, $this->fetch(0));
        self::assertCount(2, $this->entityManager()->getRepository(OdometerReading::class)->findAll());
    }

    public function testAValueLowerThanTheLastStoredReadingIsRejectedAndNothingIsRetried(): void
    {
        $this->answerWith('online', 100.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS);
        $this->fetch(0);
        $this->answerWith('online', 50.0, FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS + 3_600_000);

        $status = $this->fetch(0);

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

    public function testAVehicleThatNoLongerExistsIsDropped(): void
    {
        $status = self::getContainer()->get(OdometerFetcher::class)->fetch(new FetchOdometerMessage('01JAAAAAAAAAAAAAAAAAAAAAAA', self::slot()->getTimestamp()));

        self::assertSame(SlotRunStatus::Skipped, $status);
        self::assertSame([], $this->requests);
    }

    private function fetch(int $attempt): SlotRunStatus
    {
        return self::getContainer()->get(OdometerFetcher::class)->fetch(
            new FetchOdometerMessage($this->vehicle->getId()->toRfc4122(), self::slot()->getTimestamp(), $attempt),
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
        $run = $this->entityManager()->getRepository(FetchSlotRun::class)->findOneBy([]);
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
