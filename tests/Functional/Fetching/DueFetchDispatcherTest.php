<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Fetching;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Fetching\Domain\LocalTime;
use Polaris\Fetching\Domain\SlotRunStatus;
use Polaris\Fetching\Entity\FetchSlotRun;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Service\DueFetchDispatcher;
use Polaris\Fetching\Service\FetchScheduleService;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(DueFetchDispatcher::class)]
#[CoversClass(FetchScheduleService::class)]
final class DueFetchDispatcherTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::bootKernel();
        $owner = self::createUser();
        $owner->changeTimeZone('Europe/Zurich');
        $this->vehicle = new Vehicle(new Vin(FleetApiResponses::VIN), 'Model 3', $owner);
        $this->entityManager()->persist($this->vehicle);
        $this->entityManager()->flush();
    }

    public function testNothingIsQueuedBeforeTheDefaultSlot(): void
    {
        // 21:00 in Zurich on 2026-07-10 is 19:00 UTC.
        self::mockTime('2026-07-10 18:59:00 UTC');

        self::assertSame(0, $this->dispatch());
        self::assertSame([], $this->queued());
    }

    public function testTheDefaultSlotIsQueuedOnceWhateverTheNumberOfTicks(): void
    {
        self::mockTime('2026-07-10 19:02:00 UTC');

        self::assertSame(1, $this->dispatch());
        self::mockTime('2026-07-10 19:07:00 UTC');
        self::assertSame(0, $this->dispatch());

        $queued = $this->queued();
        self::assertCount(1, $queued);
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(FetchOdometerMessage::class, $message);
        self::assertSame($this->vehicle->getId()->toRfc4122(), $message->vehicleId);
        self::assertSame(0, $message->attempt);
        self::assertSame('2026-07-10 19:00:00', gmdate('Y-m-d H:i:s', (int) $message->slotTimestamp));
        self::assertCount(1, $this->entityManager()->getRepository(FetchSlotRun::class)->findAll());
    }

    public function testTheSlotFollowsDaylightSavingTime(): void
    {
        // Winter: 21:00 in Zurich is 20:00 UTC, so 19:30 UTC is too early.
        self::mockTime('2026-12-10 19:30:00 UTC');
        self::assertSame(0, $this->dispatch());

        self::mockTime('2026-12-10 20:00:00 UTC');
        self::assertSame(1, $this->dispatch());
    }

    public function testASlotWhoseWindowClosedIsNotRunLate(): void
    {
        self::mockTime('2026-07-10 22:00:00 UTC');

        self::assertSame(0, $this->dispatch());
    }

    public function testTwoSlotsAreQueuedEachOnce(): void
    {
        self::getContainer()->get(FetchScheduleService::class)->change($this->vehicle, LocalTime::fromString('08:00'), LocalTime::fromString('21:00'));

        // 08:00 in Zurich is 06:00 UTC in summer.
        self::mockTime('2026-07-10 06:05:00 UTC');
        self::assertSame(1, $this->dispatch());

        self::mockTime('2026-07-10 19:05:00 UTC');
        self::assertSame(1, $this->dispatch());

        self::assertCount(2, $this->queued());
    }

    public function testASlotJustBeforeLocalMidnightIsStillRunAfterIt(): void
    {
        self::getContainer()->get(FetchScheduleService::class)->change($this->vehicle, LocalTime::fromString('23:30'));

        // 23:30 on 2026-07-10 in Zurich is 21:30 UTC; at 22:10 UTC it is already 00:10 local on the 11th.
        self::mockTime('2026-07-10 22:10:00 UTC');

        self::assertSame(1, $this->dispatch());
    }

    public function testANewSlotDropsAnOlderOneStillRetrying(): void
    {
        self::getContainer()->get(FetchScheduleService::class)->change($this->vehicle, LocalTime::fromString('08:00'), LocalTime::fromString('09:00'));

        // 08:00 and 09:00 in Zurich are 06:00 and 07:00 UTC in summer.
        self::mockTime('2026-07-10 06:01:00 UTC');
        $this->dispatch();
        $run = $this->entityManager()->getRepository(FetchSlotRun::class)->findOneBy([]);
        self::assertInstanceOf(FetchSlotRun::class, $run);
        $run->record(SlotRunStatus::Retrying, 1, CarbonImmutable::now());
        $this->entityManager()->flush();

        self::mockTime('2026-07-10 07:01:00 UTC');
        $this->dispatch();

        $statuses = array_map(
            static fn (FetchSlotRun $r): string => $r->getSlotAt()->format('H:i') . ' ' . $r->getStatus()->value,
            $this->entityManager()->getRepository(FetchSlotRun::class)->findBy([], ['slotAt' => 'ASC']),
        );
        self::assertSame(['06:00 superseded', '07:00 dispatched'], $statuses);
    }

    public function testTheSlotsOfAVehicleCanBeChangedBack(): void
    {
        $service = self::getContainer()->get(FetchScheduleService::class);

        self::assertSame(['21:00'], array_map(strval(...), $service->slotsFor($this->vehicle)));

        $service->change($this->vehicle, LocalTime::fromString('07:30'), LocalTime::fromString('18:00'));
        $service->change($this->vehicle, LocalTime::fromString('06:00'));

        self::assertSame(['06:00'], array_map(strval(...), $service->slotsFor($this->vehicle)));
    }

    public function testTwoIdenticalSlotsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::getContainer()->get(FetchScheduleService::class)->change($this->vehicle, LocalTime::fromString('07:30'), LocalTime::fromString('07:30'));
    }

    private function dispatch(): int
    {
        return self::getContainer()->get(DueFetchDispatcher::class)->dispatch();
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

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
