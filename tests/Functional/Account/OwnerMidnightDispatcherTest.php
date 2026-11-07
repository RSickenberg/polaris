<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Account;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Entity\User;
use Polaris\Account\Message\DispatchOwnerMidnights;
use Polaris\Account\Message\OwnerMidnightPassed;
use Polaris\Account\MessageHandler\DispatchOwnerMidnightsHandler;
use Polaris\Account\Repository\UserRepository;
use Polaris\Account\Service\OwnerMidnightDispatcher;
use Polaris\Tests\Support\SignsIn;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(OwnerMidnightDispatcher::class)]
#[CoversClass(DispatchOwnerMidnightsHandler::class)]
#[CoversClass(UserRepository::class)]
final class OwnerMidnightDispatcherTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private EntityManagerInterface $entityManager;
    private User $zurich;
    private User $losAngeles;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->zurich = $this->owner('zurich@example.test', 'Europe/Zurich');
        $this->losAngeles = $this->owner('la@example.test', 'America/Los_Angeles');
        $this->transport()->reset();
    }

    public function testTheMidnightIsDueFiveMinutesAfterItNotBefore(): void
    {
        // Midnight of 8 November in Zurich (UTC+1) is 23:00 UTC on the 7th.
        self::mockTime('2026-11-07 23:04:59 UTC');
        $this->dispatch();

        self::assertSame(['2026-11-07'], $this->daysFor($this->zurich), 'Still the midnight that opened the 7th.');
    }

    public function testJustAfterTheOwnersMidnightTheirDailyWorkIsQueuedOnceAndNobodyElses(): void
    {
        self::mockTime('2026-11-07 23:05:00 UTC');
        $this->dispatch();

        $sent = $this->sentFor($this->zurich);
        self::assertCount(1, $sent);
        self::assertSame('2026-11-08', $sent[0]->localDay);
        self::assertSame(CarbonImmutable::parse('2026-11-07 23:00:00', 'UTC')->getTimestamp(), $sent[0]->midnightTimestamp);
        // 23:05 UTC is 15:05 in Los Angeles: their midnight of the 7th (08:00 UTC) ran earlier, and nothing is due now.
        self::assertSame(['2026-11-07'], $this->daysFor($this->losAngeles), 'Their own midnight of the 7th, queued the first time the tick ran, not at 23:05.');
    }

    public function testAMinuteLaterOrAnHourLaterRunsNothingMore(): void
    {
        self::mockTime('2026-11-07 23:05:00 UTC');
        $this->dispatch();
        $before = \count($this->transport()->getSent());

        self::mockTime('2026-11-07 23:06:00 UTC');
        $this->dispatch();
        self::mockTime('2026-11-08 05:00:00 UTC');
        $this->dispatch();

        self::assertCount($before, $this->transport()->getSent());
    }

    public function testADoubledTickAndARestartRunNothingTwice(): void
    {
        self::mockTime('2026-11-07 23:05:00 UTC');
        $first = $this->dispatcher();
        $second = $this->dispatcher();

        $first->dispatch();
        $second->dispatch();
        $first->dispatch();

        self::assertCount(1, $this->sentFor($this->zurich));
        self::assertCount(1, $this->sentFor($this->losAngeles));
    }

    public function testTheClaimIsAtomicSoTwoWorkersCannotBothWin(): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        $day = CarbonImmutable::parse('2026-11-08', 'UTC');

        self::assertTrue($users->claimMidnight($this->zurich, $day));
        self::assertFalse($users->claimMidnight($this->zurich, $day));
        self::assertFalse($users->claimMidnight($this->zurich, CarbonImmutable::parse('2026-11-07', 'UTC')), 'An older day is never claimed.');
        self::assertTrue($users->claimMidnight($this->zurich, CarbonImmutable::parse('2026-11-09', 'UTC')));
    }

    public function testAfterAnOutageOnlyTheLatestMissedMidnightRuns(): void
    {
        self::mockTime('2026-11-07 23:05:00 UTC');
        $this->dispatch();
        $this->transport()->reset();

        // The worker was down for three days.
        self::mockTime('2026-11-11 10:00:00 UTC');
        $this->dispatch();

        $sent = $this->sentFor($this->zurich);
        self::assertCount(1, $sent);
        self::assertSame('2026-11-11', $sent[0]->localDay);
    }

    public function testAChangedTimeZoneRunsNeitherADayTwiceNorAMidnightBeforeItsTime(): void
    {
        self::mockTime('2026-11-07 10:00:00 UTC');
        $this->dispatch();
        self::assertSame(['2026-11-07'], $this->daysFor($this->zurich));
        $this->transport()->reset();

        // Moving to Los Angeles (02:00 on the 7th there): the 7th has already run.
        $user = $this->entityManager->find(User::class, $this->zurich->getId());
        self::assertInstanceOf(User::class, $user);
        $user->changeTimeZone('America/Los_Angeles');
        $this->entityManager->flush();
        self::mockTime('2026-11-07 10:05:00 UTC');
        $this->dispatch();
        self::assertSame([], $this->sentFor($this->zurich));

        // Their next midnight is the start of the 8th in Los Angeles (08:00 UTC on the 8th).
        self::mockTime('2026-11-08 08:05:00 UTC');
        $this->dispatch();
        self::assertSame(['2026-11-08'], $this->daysFor($this->zurich));
    }

    public function testBothDaylightSavingDaysOfZurichRunOnceEach(): void
    {
        foreach ([['2026-10-24 22:05:00', '2026-10-25'], ['2026-10-25 23:05:00', '2026-10-26'], ['2027-03-27 23:05:00', '2027-03-28'], ['2027-03-28 22:05:00', '2027-03-29']] as [$now, $day]) {
            $this->transport()->reset();
            self::mockTime($now . ' UTC');
            $this->dispatch();
            $this->dispatch();

            self::assertSame([$day], $this->daysFor($this->zurich), $now);
        }
    }

    public function testAClaimIsGivenBackWhenTheMessageCannotBeQueued(): void
    {
        self::mockTime('2026-11-07 23:05:00 UTC');
        $failing = $this->createStub(MessageBusInterface::class);
        $failing->method('dispatch')->willThrowException(new TransportException('Redis is down.'));
        $dispatcher = new OwnerMidnightDispatcher(self::getContainer()->get(UserRepository::class), $failing, self::getContainer()->get(ClockInterface::class), new NullLogger());

        try {
            $dispatcher->dispatch();
            self::fail('The failure is not swallowed.');
        } catch (TransportException) {
        }

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'zurich@example.test'])?->getLastMidnightDay());
        $this->dispatch();
        self::assertCount(1, $this->sentFor($this->zurich), 'The next tick tries again.');
    }

    public function testTheHandlerRunsTheDispatcher(): void
    {
        self::mockTime('2026-11-07 23:05:00 UTC');

        self::getContainer()->get(DispatchOwnerMidnightsHandler::class)(new DispatchOwnerMidnights());

        self::assertCount(1, $this->sentFor($this->zurich));
    }

    private function dispatch(): void
    {
        $this->dispatcher()->dispatch();
    }

    private function dispatcher(): OwnerMidnightDispatcher
    {
        $this->entityManager->clear();

        return self::getContainer()->get(OwnerMidnightDispatcher::class);
    }

    private function owner(string $email, string $timeZone): User
    {
        $user = self::createUser($email);
        $user->changeTimeZone($timeZone);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @return list<string> the local days queued for the user, in the order they were queued
     */
    private function daysFor(User $user): array
    {
        $days = [];

        foreach ($this->sentFor($user) as $message) {
            $days[] = $message->localDay;
        }

        return $days;
    }

    /**
     * @return list<OwnerMidnightPassed> the midnights queued for the user
     */
    private function sentFor(User $user): array
    {
        $messages = [];

        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof OwnerMidnightPassed && $message->userId === $user->getId()->toRfc4122()) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
