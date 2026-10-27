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
use Polaris\Fetching\Service\OdometerFetcher;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Enum\NoReadingReason;
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

/**
 * The wake of a manual refresh the user confirmed: wake_up, then a poll of the list of vehicles every 10 s
 * for up to 90 s, then the normal read. The clock is frozen and moved by hand.
 */
#[CoversClass(OdometerFetcher::class)]
final class WakeFetchTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;
    use TeslaFixtures;

    private const string NOW = '2026-10-27 20:00:00';
    private const string WITH_WAKE = 'openid offline_access vehicle_device_data vehicle_cmds';

    private TeslaApp $app;
    private Vehicle $vehicle;

    /**
     * @var list<string> "METHOD url" of every request sent to Tesla
     */
    private array $requests = [];

    /**
     * The state of the car as the list of vehicles and the single vehicle report it.
     */
    private string $state = 'asleep';

    /**
     * The HTTP status of wake_up.
     */
    private int $wakeStatus = 200;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW . ' UTC');
        $owner = self::createUser();
        $this->app = self::createConfiguredApp($owner);
        self::createToken($this->app, CarbonImmutable::parse(self::NOW, 'UTC')->addHours(8), scopes: self::WITH_WAKE);
        self::getContainer()->set('http_client', new MockHttpClient($this->respond(...)));
        $this->vehicle = new Vehicle(new Vin(FleetApiResponses::VIN), 'Model 3', $owner);
        $this->entityManager()->persist($this->vehicle);
        $this->entityManager()->flush();
    }

    public function testAConfirmedWakeSendsWakeUpThenPollsTheListUntilTheCarIsOnlineAndReadsIt(): void
    {
        $attempt = $this->newAttempt();

        $status = $this->fetch($attempt, true);

        self::assertSame(SlotRunStatus::Retrying, $status);
        self::assertSame(['GET https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles/' . FleetApiResponses::VIN, 'POST https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles/' . FleetApiResponses::VIN . '/wake_up'], $this->requests);
        self::assertSame(RefreshOutcome::Waking, $this->outcomeOf($attempt));
        self::assertSame(self::NOW, $attempt->getWokeAt()?->toDateTimeString());
        $queued = $this->queued();
        self::assertCount(1, $queued);
        self::assertSame(10_000, $queued[0]->last(DelayStamp::class)?->getDelay());
        $poll = $queued[0]->getMessage();
        self::assertInstanceOf(FetchOdometerMessage::class, $poll);
        self::assertSame(CarbonImmutable::parse(self::NOW, 'UTC')->addSeconds(90)->getTimestamp(), $poll->wakeDeadline);
        self::assertSame($attempt->getId()->toRfc4122(), $poll->refreshId);

        // Ten seconds later the car is online: the list says so and the normal read follows.
        $this->requests = [];
        $this->state = 'online';
        self::mockTime('2026-10-27 20:00:10 UTC');

        self::assertSame(SlotRunStatus::Stored, $this->process($poll));
        self::assertSame(RefreshOutcome::Stored, $this->outcomeOf($attempt));
        self::assertSame(
            [
                'GET https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles',
                'GET https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles/' . FleetApiResponses::VIN . '/vehicle_data',
            ],
            $this->requests,
            'The list already said the car is online, so its state is not asked again before the live read.',
        );
        self::assertCount(1, $this->entityManager()->getRepository(OdometerReading::class)->findAll());
        self::assertCount(1, $this->queued(), 'Nothing is queued once the car is read.');
    }

    public function testTheListIsPolledEveryTenSecondsAndTheWakeIsGivenUpAtNinetySeconds(): void
    {
        $attempt = $this->newAttempt();
        $this->fetch($attempt, true);
        $message = $this->lastQueued();
        $steps = 0;

        for ($seconds = 10; $seconds < 90; $seconds += 10) {
            self::mockTime(\sprintf('2026-10-27 20:%02d:%02d UTC', intdiv($seconds, 60), $seconds % 60));
            $this->requests = [];

            self::assertSame(SlotRunStatus::Retrying, $this->process($message), "At {$seconds} s the car is still asleep.");
            self::assertSame(['GET https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles'], $this->requests, 'A poll only reads the list.');
            self::assertSame(RefreshOutcome::Waking, $this->outcomeOf($attempt));
            self::assertSame(10_000, $this->lastEnvelope()->last(DelayStamp::class)?->getDelay());
            $message = $this->lastQueued();
            ++$steps;
        }

        self::assertSame(8, $steps);

        self::mockTime('2026-10-27 20:01:30 UTC');
        $this->requests = [];

        self::assertSame(SlotRunStatus::Skipped, $this->process($message));
        self::assertSame(RefreshOutcome::WakeTimeout, $this->outcomeOf($attempt));
        self::assertCount(9, $this->queued(), 'The wake, then eight polls: the last check queues nothing.');
        self::assertSame([], $this->entityManager()->getRepository(OdometerReading::class)->findAll());
        self::assertSame(['GET https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles'], $this->requests, 'The last check reads the list and never the car.');
    }

    public function testACarThatComesOnlineAtTheDeadlineIsStillRead(): void
    {
        $attempt = $this->newAttempt();
        $this->fetch($attempt, true);
        $message = $this->lastQueued();
        $this->state = 'online';
        self::mockTime('2026-10-27 20:01:30 UTC');

        self::assertSame(SlotRunStatus::Stored, $this->process($message));
        self::assertSame(RefreshOutcome::Stored, $this->outcomeOf($attempt));
    }

    public function testAnOfflineCarIsWokenTooAndWhatItWasBeforeIsRecorded(): void
    {
        $this->state = 'offline';
        $attempt = $this->newAttempt();

        self::assertSame(SlotRunStatus::Retrying, $this->fetch($attempt, true));

        self::assertSame(RefreshOutcome::Waking, $this->outcomeOf($attempt));
        self::assertSame(NoReadingReason::Offline, $attempt->getWokeFrom());
        self::assertContains('POST https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles/' . FleetApiResponses::VIN . '/wake_up', $this->requests);

        // The wake works: the car is online ten seconds later.
        $this->state = 'online';
        self::mockTime('2026-10-27 20:00:10 UTC');
        self::assertSame(SlotRunStatus::Stored, $this->process($this->lastQueued()));
        self::assertSame(RefreshOutcome::Stored, $this->outcomeOf($attempt));
        self::assertSame(NoReadingReason::Offline, $attempt->getWokeFrom(), 'The state before the wake stays, so the result can be read against it.');
    }

    public function testAnOfflineCarThatDoesNotAnswerTheWakeEndsInATimeoutWithItsStateRecorded(): void
    {
        $this->state = 'offline';
        $attempt = $this->newAttempt();
        $this->fetch($attempt, true);
        self::mockTime('2026-10-27 20:01:30 UTC');

        self::assertSame(SlotRunStatus::Skipped, $this->process($this->lastQueued()));

        self::assertSame(RefreshOutcome::WakeTimeout, $this->outcomeOf($attempt));
        self::assertSame(NoReadingReason::Offline, $attempt->getWokeFrom());
    }

    public function testACarThatTeslaCannotReachForTheWakeFailsTheWakeAndIsNotCounted(): void
    {
        $this->wakeStatus = 408;
        $attempt = $this->newAttempt();

        self::assertSame(SlotRunStatus::Skipped, $this->fetch($attempt, true));

        self::assertSame(RefreshOutcome::WakeFailed, $this->outcomeOf($attempt));
        self::assertNull($attempt->getWokeAt());
        self::assertSame([], $this->queued());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function refusedStatuses(): iterable
    {
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedStatuses')]
    public function testTeslaRefusingTheWakeIsAMissingScopeAndTheConnectionStaysUsable(int $status): void
    {
        $this->wakeStatus = $status;
        $attempt = $this->newAttempt();

        self::assertSame(SlotRunStatus::Skipped, $this->fetch($attempt, true));

        self::assertSame(RefreshOutcome::WakeScopeMissing, $this->outcomeOf($attempt));
        self::assertNull($attempt->getWokeAt(), 'A refused wake is not billed.');
        self::assertSame([], $this->queued());
        $token = $this->entityManager()->getRepository(OAuthToken::class)->findOneBy([]);
        self::assertTrue($token?->isConnected());
    }

    public function testTheCapStopsAWakeThatWasAllowedWhenTheUserConfirmed(): void
    {
        $this->app->changeDailyWakeCap(2);
        $this->wokeAgo(2 * 3600);
        $this->wokeAgo(23 * 3600);
        $attempt = $this->newAttempt();

        self::assertSame(SlotRunStatus::Skipped, $this->fetch($attempt, true));

        self::assertSame(RefreshOutcome::WakeCapReached, $this->outcomeOf($attempt));
        self::assertNull($attempt->getWokeAt());
        self::assertNotContains('POST', array_map(static fn (string $request): string => explode(' ', $request)[0], $this->requests));
    }

    public function testAWakeOlderThanTwentyFourHoursDoesNotCountAnyMore(): void
    {
        $this->app->changeDailyWakeCap(1);
        $this->wokeAgo(24 * 3600 + 1);
        $attempt = $this->newAttempt();

        self::assertSame(SlotRunStatus::Retrying, $this->fetch($attempt, true));
        self::assertSame(RefreshOutcome::Waking, $this->outcomeOf($attempt));
    }

    public function testACapOfZeroNeverWakes(): void
    {
        $this->app->changeDailyWakeCap(0);
        $attempt = $this->newAttempt();

        $this->fetch($attempt, true);

        self::assertSame(RefreshOutcome::WakeCapReached, $this->outcomeOf($attempt));
        self::assertCount(1, $this->requests, 'Only the state check was sent.');
    }

    public function testWithoutTheScopeNothingIsSentToWake(): void
    {
        // A connection made before vehicle_cmds was requested.
        $this->entityManager()->createQuery('DELETE FROM ' . OAuthToken::class)->execute();
        self::createToken($this->app, CarbonImmutable::parse(self::NOW, 'UTC')->addHours(8));
        $attempt = $this->newAttempt();

        $this->fetch($attempt, true);

        self::assertSame(RefreshOutcome::WakeScopeMissing, $this->outcomeOf($attempt));
        self::assertCount(1, $this->requests, 'Only the state check was sent.');
    }

    public function testAManualRefreshThatWasNotConfirmedNeverWakes(): void
    {
        $attempt = $this->newAttempt();

        self::assertSame(SlotRunStatus::Skipped, $this->fetch($attempt, false));

        self::assertSame(RefreshOutcome::Asleep, $this->outcomeOf($attempt));
        self::assertCount(1, $this->requests);
    }

    public function testAScheduledFetchNeverWakesTheCarWhateverTheScopeAndTheFlags(): void
    {
        $slot = CarbonImmutable::parse(self::NOW, 'UTC');
        $this->entityManager()->persist(new FetchSlotRun($this->vehicle, $slot, $slot));
        $this->entityManager()->flush();
        $fetcher = self::getContainer()->get(OdometerFetcher::class);
        $id = $this->vehicle->getId()->toRfc4122();

        // A slot message, even one that wrongly carries the wake flag, and a refresh that carries no id.
        $fetcher->fetch(new FetchOdometerMessage($id, $slot->getTimestamp(), 0));
        $fetcher->fetch(new FetchOdometerMessage($id, $slot->getTimestamp(), 1, null, true));
        $fetcher->fetch(new FetchOdometerMessage($id, null, 0, null, true));

        self::assertCount(3, $this->requests);
        foreach ($this->requests as $request) {
            self::assertStringStartsWith('GET ', $request);
            self::assertStringNotContainsString('wake_up', $request);
        }
    }

    public function testAScheduledFetchNeverWakesAnOfflineCarEither(): void
    {
        $this->state = 'offline';
        $slot = CarbonImmutable::parse(self::NOW, 'UTC');
        $this->entityManager()->persist(new FetchSlotRun($this->vehicle, $slot, $slot));
        $this->entityManager()->flush();

        self::getContainer()->get(OdometerFetcher::class)->fetch(new FetchOdometerMessage($this->vehicle->getId()->toRfc4122(), $slot->getTimestamp(), 0, null, true));

        self::assertCount(1, $this->requests);
        self::assertStringStartsWith('GET ', $this->requests[0]);
    }

    public function testAScheduledFetchWaitsWhileAWakeIsPendingThenRunsAgain(): void
    {
        $slot = CarbonImmutable::parse(self::NOW, 'UTC');
        $this->entityManager()->persist(new FetchSlotRun($this->vehicle, $slot, $slot));
        $attempt = $this->newAttempt();
        $attempt->markWoken(CarbonImmutable::parse(self::NOW, 'UTC')->subSeconds(30), NoReadingReason::Asleep);
        $this->entityManager()->flush();
        $fetcher = self::getContainer()->get(OdometerFetcher::class);
        $message = new FetchOdometerMessage($this->vehicle->getId()->toRfc4122(), $slot->getTimestamp(), 2);

        self::assertSame(SlotRunStatus::Retrying, $fetcher->fetch($message));

        self::assertSame([], $this->requests, 'The car is not asked while the wake runs.');
        $queued = $this->queued();
        self::assertCount(1, $queued);
        self::assertSame(30_000, $queued[0]->last(DelayStamp::class)?->getDelay());
        $retry = $queued[0]->getMessage();
        self::assertInstanceOf(FetchOdometerMessage::class, $retry);
        self::assertSame(2, $retry->attempt, 'A wait does not use up a check.');

        // The wake is over (the poll died with its worker): the margin after the deadline frees the vehicle.
        self::mockTime('2026-10-27 20:02:01 UTC');
        $this->state = 'online';
        $this->requests = [];

        self::assertSame(SlotRunStatus::Stored, $fetcher->fetch($message));
    }

    public function testAManualRefreshWhileAWakeIsPendingIsBusy(): void
    {
        $waking = $this->newAttempt();
        $waking->markWoken(CarbonImmutable::parse(self::NOW, 'UTC')->subSeconds(30), NoReadingReason::Asleep);
        $this->entityManager()->flush();
        $other = $this->newAttempt();

        self::assertSame(SlotRunStatus::Skipped, $this->fetch($other, false));

        self::assertSame(RefreshOutcome::Busy, $this->outcomeOf($other));
        self::assertSame([], $this->requests);
    }

    private function fetch(RefreshAttempt $attempt, bool $allowWake): SlotRunStatus
    {
        return $this->process(new FetchOdometerMessage($this->vehicle->getId()->toRfc4122(), null, 0, $attempt->getId()->toRfc4122(), $allowWake));
    }

    private function process(FetchOdometerMessage $message): SlotRunStatus
    {
        return self::getContainer()->get(OdometerFetcher::class)->fetch($message);
    }

    private function newAttempt(): RefreshAttempt
    {
        $attempt = new RefreshAttempt($this->vehicle, CarbonImmutable::parse(self::NOW, 'UTC'));
        $this->entityManager()->persist($attempt);
        $this->entityManager()->flush();

        return $attempt;
    }

    /**
     * An earlier refresh that woke the vehicle this many seconds ago.
     */
    private function wokeAgo(int $seconds): void
    {
        $earlier = new RefreshAttempt($this->vehicle, CarbonImmutable::parse(self::NOW, 'UTC')->subSeconds($seconds));
        $earlier->markWoken(CarbonImmutable::parse(self::NOW, 'UTC')->subSeconds($seconds), NoReadingReason::Asleep);
        $earlier->settle(RefreshOutcome::Stored, CarbonImmutable::parse(self::NOW, 'UTC')->subSeconds($seconds));
        $this->entityManager()->persist($earlier);
        $this->entityManager()->flush();
    }

    private function outcomeOf(RefreshAttempt $attempt): RefreshOutcome
    {
        $this->entityManager()->refresh($attempt);

        return $attempt->getOutcome();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function respond(string $method, string $url, array $options = []): MockResponse
    {
        $this->requests[] = $method . ' ' . $url;

        if (str_ends_with($url, '/wake_up')) {
            // Like Tesla: a POST that is not JSON is refused.
            /** @var list<string> $lines */
            $lines = $options['headers'] ?? [];
            $headers = strtolower(implode("\n", $lines));

            if (!str_contains($headers, 'content-type: application/json') || '{}' !== ($options['body'] ?? null)) {
                return new MockResponse('{"error":"not_a_JSON_request"}', ['http_code' => 406]);
            }

            return new MockResponse(200 === $this->wakeStatus ? FleetApiResponses::vehicle('asleep') : '{}', ['http_code' => $this->wakeStatus]);
        }

        if (str_ends_with($url, '/api/1/vehicles')) {
            return new MockResponse((string) json_encode(['response' => [['vin' => FleetApiResponses::VIN, 'display_name' => 'Lucia', 'state' => $this->state]]]));
        }

        if (str_ends_with($url, '/vehicle_data')) {
            return new MockResponse(FleetApiResponses::vehicleData(100.0));
        }

        return new MockResponse(FleetApiResponses::vehicle($this->state));
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

    private function lastEnvelope(): Envelope
    {
        $envelope = array_last($this->queued());
        self::assertInstanceOf(Envelope::class, $envelope);

        return $envelope;
    }

    private function lastQueued(): FetchOdometerMessage
    {
        $message = $this->lastEnvelope()->getMessage();
        self::assertInstanceOf(FetchOdometerMessage::class, $message);

        return $message;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
