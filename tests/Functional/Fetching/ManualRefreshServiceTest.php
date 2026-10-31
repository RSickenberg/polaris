<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Fetching;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Entity\User;
use Polaris\Extension\ConfiguredManualRefreshPolicy;
use Polaris\Extension\ManualRefreshPolicy;
use Polaris\Fetching\Domain\RefreshOutcome;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Repository\RefreshAttemptRepository;
use Polaris\Fetching\Service\Enum\RefreshRequestStatus;
use Polaris\Fetching\Service\Enum\WakeRequestStatus;
use Polaris\Fetching\Service\ManualRefreshLimit;
use Polaris\Fetching\Service\ManualRefreshService;
use Polaris\Fetching\Service\WakeCap;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Enum\NoReadingReason;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaVehicleWaker;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(ManualRefreshService::class)]
#[CoversClass(ConfiguredManualRefreshPolicy::class)]
final class ManualRefreshServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;
    use TeslaFixtures;

    private InMemoryStorage $storage;
    private User $owner;
    private Vehicle $vehicle;
    private Vehicle $other;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-26 12:00:00 UTC');
        $this->storage = new InMemoryStorage();
        $this->owner = self::createUser();
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000201'), 'First', $this->owner);
        $this->other = new Vehicle(new Vin('5YJ3E7EB2NF000202'), 'Second', $this->owner);
        $this->entityManager()->persist($this->vehicle);
        $this->entityManager()->persist($this->other);
        $this->entityManager()->flush();
    }

    public function testTheFirstRequestIsAcceptedAndQueuesAFetchWithoutASlot(): void
    {
        $result = $this->service()->request($this->vehicle);

        self::assertSame(RefreshRequestStatus::Accepted, $result->status);
        self::assertTrue($result->isAccepted());
        self::assertNull($result->retryAfter);
        $queued = $this->queued();
        self::assertCount(1, $queued);
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(FetchOdometerMessage::class, $message);
        self::assertSame($this->vehicle->getId()->toRfc4122(), $message->vehicleId);
        self::assertNull($message->slotTimestamp);
        self::assertSame(0, $message->attempt);
        self::assertSame($result->attemptId?->toRfc4122(), $message->refreshId);
        $attempt = $this->entityManager()->find(RefreshAttempt::class, $result->attemptId);
        self::assertSame(RefreshOutcome::Queued, $attempt?->getOutcome());
        self::assertSame('2026-10-26T12:00:00+00:00', $attempt->getRequestedAt()->toIso8601String());
    }

    public function testARequestDoesNotSpendTheHourUntilTheCarIsRead(): void
    {
        self::assertTrue($this->service()->request($this->vehicle)->isAccepted());
        self::assertTrue($this->service()->request($this->vehicle)->isAccepted(), 'A car found asleep costs nothing against the limit.');
        self::assertCount(2, $this->queued());

        self::assertNull($this->limit()->consume($this->vehicle), 'The fetch spends the hour when it reads the car.');
        self::assertNotNull($this->limit()->consume($this->vehicle), 'A second read within the hour is refused.');
        self::assertNotNull($this->limit()->retryAfter($this->vehicle));
    }

    public function testASecondRequestWithinTheHourOfARealReadIsLimitedAndQueuesNothing(): void
    {
        $this->service()->request($this->vehicle);
        $this->limit()->consume($this->vehicle);

        $result = $this->service()->request($this->vehicle);

        self::assertSame(RefreshRequestStatus::RateLimited, $result->status);
        self::assertFalse($result->isAccepted());
        self::assertNull($result->attemptId);
        self::assertNotNull($result->retryAfter);
        self::assertSame(0, $result->retryAfter->getOffset(), 'The retry instant is UTC.');
        $secondsLeft = $result->retryAfter->getTimestamp() - time();
        self::assertGreaterThan(0, $secondsLeft);
        // The limiter rounds the wait up to a whole second.
        self::assertLessThanOrEqual(3_601, $secondsLeft);
        self::assertCount(1, $this->queued());
        self::assertCount(1, $this->entityManager()->getRepository(RefreshAttempt::class)->findAll());
    }

    public function testTwoVehiclesHaveSeparateLimits(): void
    {
        $this->limit()->consume($this->vehicle);

        self::assertTrue($this->service()->request($this->other)->isAccepted());
        $this->limit()->consume($this->other);
        self::assertFalse($this->service()->request($this->vehicle)->isAccepted());
        self::assertFalse($this->service()->request($this->other)->isAccepted());
    }

    public function testARequestIsAcceptedAgainOnceTheHourIsOver(): void
    {
        $this->limit()->consume($this->vehicle);
        // The limiter reads the wall clock, which the test cannot freeze: its stored window is aged instead.
        $this->storage->save(self::agedWindow($this->vehicle));

        self::assertTrue($this->service()->request($this->vehicle)->isAccepted());
        self::assertCount(1, $this->queued());
    }

    public function testWithoutALimitEveryRequestIsAccepted(): void
    {
        $policy = new class implements ManualRefreshPolicy {
            public function isLimited(Vehicle $vehicle): bool
            {
                return false;
            }
        };
        $service = $this->service($policy);

        self::assertNull($this->limit($policy)->consume($this->vehicle));
        self::assertNull($this->limit($policy)->consume($this->vehicle));
        self::assertTrue($service->request($this->vehicle)->isAccepted());
        self::assertTrue($service->request($this->vehicle)->isAccepted());
        self::assertCount(2, $this->queued());
    }

    public function testTheDefaultPolicyLimitsUnlessTheEnvironmentLiftsIt(): void
    {
        self::assertTrue(new ConfiguredManualRefreshPolicy(true)->isLimited($this->vehicle));
        self::assertFalse(new ConfiguredManualRefreshPolicy(false)->isLimited($this->vehicle));
        self::assertTrue(self::getContainer()->get(ManualRefreshPolicy::class)->isLimited($this->vehicle), 'The shipped default limits.');
    }

    public function testAWakeConfirmedAfterAnAsleepRefreshContinuesTheSameRefreshWithoutUsingTheHourlyLimit(): void
    {
        $this->connect();
        $service = $this->service();
        $attempt = $this->asleepAttempt($service);
        $service->request($this->other);
        $this->entityManager()->clear();
        $vehicle = $this->entityManager()->find(Vehicle::class, $this->vehicle->getId());
        self::assertInstanceOf(Vehicle::class, $vehicle);

        $offer = $service->wakeOffer($vehicle, $attempt->getId()->toRfc4122());
        $result = $service->requestWake($vehicle, $attempt->getId()->toRfc4122());

        self::assertSame(WakeRequestStatus::Accepted, $offer->status);
        self::assertTrue($result->isAccepted());
        $envelope = array_last($this->queued());
        self::assertInstanceOf(Envelope::class, $envelope);
        $message = $envelope->getMessage();
        self::assertInstanceOf(FetchOdometerMessage::class, $message);
        self::assertTrue($message->allowWake);
        self::assertNull($message->slotTimestamp);
        self::assertNull($message->wakeDeadline);
        self::assertSame($attempt->getId()->toRfc4122(), $message->refreshId);
        $stored = $this->entityManager()->find(RefreshAttempt::class, $attempt->getId());
        self::assertSame(RefreshOutcome::Queued, $stored?->getOutcome());
        self::assertCount(2, $this->entityManager()->getRepository(RefreshAttempt::class)->findAll(), 'The wake did not create a refresh.');
        $this->limit()->consume($vehicle);
        self::assertFalse($service->request($vehicle)->isAccepted(), 'The hourly limit still applies after a wake.');
    }

    public function testAnOfflineCarCanBeWokenToo(): void
    {
        $this->connect();
        $service = $this->service();
        $attempt = $this->asleepAttempt($service);
        $attempt->settle(RefreshOutcome::Offline, $this->now());
        $this->entityManager()->flush();

        $result = $service->requestWake($this->vehicle, $attempt->getId()->toRfc4122());

        self::assertTrue($result->isAccepted());
        self::assertSame(RefreshOutcome::Queued, $this->entityManager()->find(RefreshAttempt::class, $attempt->getId())?->getOutcome());
    }

    public function testAWakeThatTeslaCouldNotDeliverIsNotOfferedAgain(): void
    {
        $this->connect();
        $service = $this->service();
        $attempt = $this->asleepAttempt($service);
        $attempt->settle(RefreshOutcome::WakeFailed, $this->now());
        $this->entityManager()->flush();

        self::assertSame(WakeRequestStatus::NothingToWake, $service->requestWake($this->vehicle, $attempt->getId()->toRfc4122())->status);
    }

    public function testAWakeIsRefusedUnlessTheRefreshFoundTheCarAsleepAndRecently(): void
    {
        $this->connect();
        $service = $this->service();
        $attempt = $this->asleepAttempt($service);
        $id = $attempt->getId()->toRfc4122();
        $sent = \count($this->queued());

        self::mockTime('2026-10-26 12:10:01 UTC');
        self::assertSame(WakeRequestStatus::NothingToWake, $service->requestWake($this->vehicle, $id)->status, 'Ten minutes is the limit.');

        self::mockTime('2026-10-26 12:09:59 UTC');
        $attempt->settle(RefreshOutcome::Stored, $this->now());
        $this->entityManager()->flush();
        self::assertSame(WakeRequestStatus::NothingToWake, $service->requestWake($this->vehicle, $id)->status, 'A car that was read is not woken.');
        self::assertSame(WakeRequestStatus::NothingToWake, $service->requestWake($this->other, $id)->status, 'The refresh of another vehicle is not found.');
        self::assertSame(WakeRequestStatus::NothingToWake, $service->requestWake($this->vehicle, 'not-an-id')->status);
        self::assertCount($sent, $this->queued());
    }

    public function testAWakeIsRefusedWithoutAUsableConnectionOrTheScope(): void
    {
        $service = $this->service();
        $attempt = $this->asleepAttempt($service);
        $id = $attempt->getId()->toRfc4122();

        self::assertSame(WakeRequestStatus::NotConnected, $service->requestWake($this->vehicle, $id)->status, 'No Tesla app yet.');

        $app = self::createConfiguredApp($this->owner);
        self::assertSame(WakeRequestStatus::NotConnected, $service->requestWake($this->vehicle, $id)->status, 'An app, but no connection.');

        self::createToken($app, CarbonImmutable::parse('2026-10-26 20:00:00', 'UTC'));
        $result = $service->requestWake($this->vehicle, $id);
        self::assertSame(WakeRequestStatus::ScopeMissing, $result->status);
        self::assertFalse($result->isAccepted());
        self::assertSame(RefreshOutcome::Asleep, $this->entityManager()->find(RefreshAttempt::class, $attempt->getId())?->getOutcome());
    }

    public function testAWakeIsRefusedWhenTheCapIsReachedAndSaysWhenItFreesUp(): void
    {
        $app = $this->connect();
        $app->changeDailyWakeCap(1);
        $earlier = new RefreshAttempt($this->vehicle, CarbonImmutable::parse('2026-10-26 02:00:00', 'UTC'));
        $earlier->markWoken(CarbonImmutable::parse('2026-10-26 02:00:00', 'UTC'), NoReadingReason::Asleep);
        $earlier->settle(RefreshOutcome::Stored, CarbonImmutable::parse('2026-10-26 02:01:00', 'UTC'));
        $this->entityManager()->persist($earlier);
        $this->entityManager()->flush();
        $service = $this->service();
        $attempt = $this->asleepAttempt($service);

        $result = $service->requestWake($this->vehicle, $attempt->getId()->toRfc4122());

        self::assertSame(WakeRequestStatus::CapReached, $result->status);
        self::assertSame('2026-10-27 02:00:00', $result->availableAt?->toDateTimeString());
        self::assertSame(RefreshOutcome::Asleep, $this->entityManager()->find(RefreshAttempt::class, $attempt->getId())?->getOutcome());

        $other = $this->asleepAttempt($service, $this->other);
        self::assertTrue($service->requestWake($this->other, $other->getId()->toRfc4122())->isAccepted(), 'The cap is per vehicle.');
    }

    public function testAWakeIsRefusedWhileTheHourIsSpentBecauseNoReadCouldFollow(): void
    {
        $this->connect();
        $service = $this->service();
        $attempt = $this->asleepAttempt($service);
        $this->limit()->consume($this->vehicle);

        $result = $service->requestWake($this->vehicle, $attempt->getId()->toRfc4122());

        self::assertSame(WakeRequestStatus::RateLimited, $result->status);
        self::assertNotNull($result->availableAt);
        self::assertSame(RefreshOutcome::Asleep, $this->entityManager()->find(RefreshAttempt::class, $attempt->getId())?->getOutcome());
    }

    public function testAWakeIsRefusedWhileAnotherIsRunning(): void
    {
        $this->connect();
        $service = $this->service();
        $waking = new RefreshAttempt($this->vehicle, $this->now()->subSeconds(20));
        $waking->markWoken($this->now()->subSeconds(20), NoReadingReason::Asleep);
        $this->entityManager()->persist($waking);
        $this->entityManager()->flush();
        $attempt = $this->asleepAttempt($service);

        self::assertSame(WakeRequestStatus::NothingToWake, $service->requestWake($this->vehicle, $attempt->getId()->toRfc4122())->status);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(self::getContainer()->get(ClockInterface::class)->now());
    }

    private function connect(): TeslaApp
    {
        $app = self::createConfiguredApp($this->owner);
        self::createToken($app, CarbonImmutable::parse('2026-10-26 20:00:00', 'UTC'), scopes: 'openid offline_access vehicle_device_data vehicle_cmds');

        return $app;
    }

    /**
     * A refresh that the worker found asleep.
     */
    private function asleepAttempt(ManualRefreshService $service, ?Vehicle $vehicle = null): RefreshAttempt
    {
        $vehicle ??= $this->vehicle;
        $result = $service->request($vehicle);
        $attempt = $this->entityManager()->find(RefreshAttempt::class, $result->attemptId);
        self::assertInstanceOf(RefreshAttempt::class, $attempt);
        $attempt->settle(RefreshOutcome::Asleep, $this->now());
        $this->entityManager()->flush();

        return $attempt;
    }

    private function service(?ManualRefreshPolicy $policy = null): ManualRefreshService
    {
        return new ManualRefreshService(
            $this->entityManager(),
            $this->limit($policy),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(ClockInterface::class),
            self::getContainer()->get(RefreshAttemptRepository::class),
            self::getContainer()->get(TeslaAppSettings::class),
            self::getContainer()->get(TeslaVehicleWaker::class),
            self::getContainer()->get(WakeCap::class),
        );
    }

    private function limit(?ManualRefreshPolicy $policy = null): ManualRefreshLimit
    {
        return new ManualRefreshLimit(
            $policy ?? self::getContainer()->get(ManualRefreshPolicy::class),
            new RateLimiterFactory(['id' => 'manual_refresh', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], $this->storage),
        );
    }

    private static function agedWindow(Vehicle $vehicle): Window
    {
        $start = microtime(true) - 3_601;
        $window = new Window('manual_refresh-' . $vehicle->getId()->toRfc4122(), 3_600, 1, $start);
        $window->add(1, $start);

        return $window;
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

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
