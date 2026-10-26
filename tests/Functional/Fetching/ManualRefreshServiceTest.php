<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Fetching;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Extension\ConfiguredManualRefreshPolicy;
use Polaris\Extension\ManualRefreshPolicy;
use Polaris\Fetching\Domain\RefreshOutcome;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Service\Enum\RefreshRequestStatus;
use Polaris\Fetching\Service\ManualRefreshService;
use Polaris\Tests\Support\SignsIn;
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

    private InMemoryStorage $storage;
    private Vehicle $vehicle;
    private Vehicle $other;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-26 12:00:00 UTC');
        $this->storage = new InMemoryStorage();
        $owner = self::createUser();
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000201'), 'First', $owner);
        $this->other = new Vehicle(new Vin('5YJ3E7EB2NF000202'), 'Second', $owner);
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

    public function testASecondRequestWithinTheHourIsLimitedAndQueuesNothing(): void
    {
        $this->service()->request($this->vehicle);

        $result = $this->service()->request($this->vehicle);

        self::assertSame(RefreshRequestStatus::RateLimited, $result->status);
        self::assertFalse($result->isAccepted());
        self::assertNull($result->attemptId);
        self::assertNotNull($result->retryAfter);
        self::assertSame(0, $result->retryAfter->getOffset(), 'The retry instant is UTC.');
        $secondsLeft = $result->retryAfter->getTimestamp() - time();
        self::assertGreaterThan(0, $secondsLeft);
        self::assertLessThanOrEqual(3_600, $secondsLeft);
        self::assertCount(1, $this->queued());
        self::assertCount(1, $this->entityManager()->getRepository(RefreshAttempt::class)->findAll());
    }

    public function testTwoVehiclesHaveSeparateLimits(): void
    {
        self::assertTrue($this->service()->request($this->vehicle)->isAccepted());

        self::assertTrue($this->service()->request($this->other)->isAccepted());
        self::assertFalse($this->service()->request($this->vehicle)->isAccepted());
        self::assertFalse($this->service()->request($this->other)->isAccepted());
    }

    public function testARequestIsAcceptedAgainOnceTheHourIsOver(): void
    {
        $this->service()->request($this->vehicle);
        // The limiter reads the wall clock, which the test cannot freeze: its stored window is aged instead.
        $this->storage->save(self::agedWindow($this->vehicle));

        self::assertTrue($this->service()->request($this->vehicle)->isAccepted());
        self::assertCount(2, $this->queued());
    }

    public function testWithoutALimitEveryRequestIsAccepted(): void
    {
        $service = $this->service(new class implements ManualRefreshPolicy {
            public function isLimited(Vehicle $vehicle): bool
            {
                return false;
            }
        });

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

    private function service(?ManualRefreshPolicy $policy = null): ManualRefreshService
    {
        return new ManualRefreshService(
            $this->entityManager(),
            $policy ?? self::getContainer()->get(ManualRefreshPolicy::class),
            new RateLimiterFactory(['id' => 'manual_refresh', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], $this->storage),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(ClockInterface::class),
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
