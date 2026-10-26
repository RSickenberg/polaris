<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Fetching;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Entity\User;
use Polaris\Fetching\Domain\RefreshOutcome;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Twig\Components\OdometerRefreshButton;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

#[CoversClass(OdometerRefreshButton::class)]
final class OdometerRefreshButtonTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use SignsIn;

    private const string NAME = 'Fetching:OdometerRefreshButton';

    private KernelBrowser $client;
    private User $me;
    private Vehicle $mine;
    private Vehicle $theirs;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->me = self::createUser('me@example.test');
        $other = self::createUser('other@example.test');
        $this->mine = new Vehicle(new Vin('5YJ3E7EB2NF000301'), 'Mine', $this->me);
        $this->theirs = new Vehicle(new Vin('5YJ3E7EB2NF000302'), 'Theirs', $other);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($this->mine);
        $entityManager->persist($this->theirs);
        $entityManager->flush();
    }

    public function testTheClickQueuesOneFetchWithoutASlotAndTheComponentPollsUntilItIsSettled(): void
    {
        $component = $this->component($this->mine);
        self::assertStringNotContainsString('data-poll', (string) $component->render());

        $component->call('refresh');

        $queued = $this->queued();
        self::assertCount(1, $queued);
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(FetchOdometerMessage::class, $message);
        self::assertNull($message->slotTimestamp);
        self::assertSame($this->mine->getId()->toRfc4122(), $message->vehicleId);
        $html = (string) $component->render();
        self::assertStringContainsString('data-poll="delay(2000)|$render"', $html);
        self::assertStringContainsString('Reading the odometer', $html);

        $attempt = $this->entityManager()->find(RefreshAttempt::class, $message->refreshId);
        self::assertInstanceOf(RefreshAttempt::class, $attempt);
        $attempt->settle(RefreshOutcome::Asleep, CarbonImmutable::now('UTC'));
        $this->entityManager()->flush();

        $html = (string) $component->refresh()->render();
        self::assertStringNotContainsString('data-poll', $html);
        self::assertStringContainsString('The car is asleep. Polaris does not wake it', $html);
        self::assertCount(1, $this->entityManager()->getRepository(RefreshAttempt::class)->findAll(), 'Rendering never starts another refresh.');
    }

    public function testASecondClickWithinTheHourShowsWhenToTryAgainAndQueuesNothing(): void
    {
        $component = $this->component($this->mine);
        $component->call('refresh');

        $html = (string) $component->call('refresh')->render();

        self::assertStringContainsString('You can refresh again at', $html);
        self::assertStringNotContainsString('data-poll', $html);
        self::assertCount(1, $this->entityManager()->getRepository(RefreshAttempt::class)->findAll(), 'The limited click starts nothing.');
    }

    public function testAnotherUsersVehicleIsRefusedOnEveryRequest(): void
    {
        $component = $this->component($this->theirs);

        $this->expectException(NotFoundHttpException::class);

        try {
            $component->call('refresh');
        } finally {
            self::assertSame([], $this->entityManager()->getRepository(RefreshAttempt::class)->findAll());
        }
    }

    public function testTheAttemptOfAnotherVehicleIsNotShown(): void
    {
        $attempt = new RefreshAttempt($this->theirs, CarbonImmutable::now('UTC'));
        $attempt->settle(RefreshOutcome::Stored, CarbonImmutable::now('UTC'));
        $this->entityManager()->persist($attempt);
        $this->entityManager()->flush();
        $this->client->loginUser($this->me);

        $html = (string) $this->createLiveComponent(self::NAME, ['vehicleId' => $this->mine->getId()->toRfc4122(), 'attemptId' => $attempt->getId()->toRfc4122()], $this->client)->render();

        self::assertStringNotContainsString('New reading stored.', $html);
    }

    private function component(Vehicle $vehicle): \Symfony\UX\LiveComponent\Test\TestLiveComponent
    {
        $this->client->loginUser($this->me);

        return $this->createLiveComponent(self::NAME, ['vehicleId' => $vehicle->getId()->toRfc4122()], $this->client);
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
