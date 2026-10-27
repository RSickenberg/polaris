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
use Polaris\Fetching\Service\ManualRefreshLimit;
use Polaris\Fetching\Twig\Components\OdometerRefreshButton;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
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
    use TeslaFixtures;

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
        self::assertMatchesRegularExpression('/<button[^>]*\sdisabled(?=[\s=>])/', $html, 'The button stays disabled while the fetch is queued.');
        self::assertStringContainsString('animate-spin', $html);

        $attempt = $this->entityManager()->find(RefreshAttempt::class, $message->refreshId);
        self::assertInstanceOf(RefreshAttempt::class, $attempt);
        $attempt->settle(RefreshOutcome::Asleep, CarbonImmutable::now('UTC'));
        $this->entityManager()->flush();

        $html = (string) $component->refresh()->render();
        self::assertStringNotContainsString('data-poll', $html);
        self::assertStringContainsString('data-toast-announce-variant-value="warning"', $html, 'A car that is asleep is a warning, not an error.');
        self::assertDoesNotMatchRegularExpression('/<button[^>]*\sdisabled(?=[\s=>])/', $html);
        self::assertStringContainsString('The car is asleep: the last reading is kept', $html);
        self::assertCount(1, $this->entityManager()->getRepository(RefreshAttempt::class)->findAll(), 'Rendering never starts another refresh.');
    }

    public function testASecondClickWithinTheHourShowsWhenToTryAgainAndQueuesNothing(): void
    {
        // A refresh that read the car spent the hour (the fetch does it, the click only looks).
        self::assertNull(self::getContainer()->get(ManualRefreshLimit::class)->consume($this->mine));
        $component = $this->component($this->mine);

        $html = (string) $component->call('refresh')->render();

        self::assertStringContainsString('You can refresh again at', $html);
        self::assertStringContainsString('data-toast-announce-variant-value="warning"', $html);
        self::assertMatchesRegularExpression('/You can refresh again at [^(]+\(UTC\)\./', $html, 'The time says its zone.');
        self::assertStringContainsString('id="limited-1"', $html);
        self::assertStringNotContainsString('data-poll', $html);
        self::assertSame([], $this->entityManager()->getRepository(RefreshAttempt::class)->findAll(), 'The limited click starts nothing.');
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

    public function testACarFoundAsleepOffersAWakeWithItsCostAndTheConfirmationQueuesIt(): void
    {
        $this->connect();
        $component = $this->component($this->mine);
        $component->call('refresh');
        $this->settle(RefreshOutcome::Asleep);

        $html = (string) $component->refresh()->render();

        self::assertStringContainsString('data-wake-dialog', $html);
        self::assertStringContainsString('Your car is asleep. Wake it to get the current mileage?', $html);
        self::assertStringContainsString('$0.02', $html, 'The cost per wake comes from the configuration, formatted for the locale.');
        self::assertStringContainsString('Use last reading', $html);
        self::assertStringContainsString('Wake and refresh', $html);
        self::assertStringContainsString('data-dialog-open-value="true"', $html);
        self::assertStringNotContainsString('data-toast-announce', $html, 'The dialog replaces the asleep toast.');
        self::assertStringNotContainsString('data-wake-reconnect', $html);

        $html = (string) $component->call('wake')->render();

        // Each request boots the kernel again, so only what the confirmation sent is in the transport.
        $queued = $this->queued();
        self::assertCount(1, $queued, 'Showing the offer sent nothing; the confirmation sent one message.');
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(FetchOdometerMessage::class, $message);
        self::assertTrue($message->allowWake);
        self::assertNull($message->slotTimestamp);
        self::assertSame($this->attemptId(), $message->refreshId);
        self::assertStringNotContainsString('data-wake-dialog', $html);
        self::assertStringContainsString('data-poll', $html, 'The component polls while the wake runs.');
        self::assertCount(1, $this->entityManager()->getRepository(RefreshAttempt::class)->findAll(), 'A wake is not a new refresh.');
    }

    public function testDecliningTheWakeKeepsTheLastReadingAndTheDialogStaysClosed(): void
    {
        $this->connect();
        $component = $this->component($this->mine);
        $component->call('refresh');
        $this->settle(RefreshOutcome::Asleep);

        $html = (string) $component->call('declineWake')->render();

        self::assertStringNotContainsString('data-wake-dialog', $html);
        self::assertStringContainsString('The car is asleep: the last reading is kept.', $html);
        self::assertSame([], $this->queued(), 'Declining sends nothing.');
    }

    public function testWithoutTheScopeThereIsNoWakeButALinkToReconnect(): void
    {
        $this->connect('openid offline_access vehicle_device_data');
        $component = $this->component($this->mine);
        $component->call('refresh');
        $this->settle(RefreshOutcome::Asleep);

        $html = (string) $component->refresh()->render();

        self::assertStringNotContainsString('data-wake-dialog', $html);
        self::assertStringNotContainsString('Wake and refresh', $html);
        self::assertStringContainsString('data-wake-reconnect', $html);
        self::assertStringContainsString('href="/settings/tesla/connect/reauthorize"', $html);
        self::assertStringContainsString('Reconnect to allow waking', $html);
    }

    public function testWhenTheCapIsReachedThereIsNoDialogAndTheToastSaysWhenToTryAgain(): void
    {
        $this->connect()->changeDailyWakeCap(1);
        $earlier = new RefreshAttempt($this->mine, CarbonImmutable::now('UTC')->subHours(2));
        $earlier->markWoken(CarbonImmutable::now('UTC')->subHours(2));
        $earlier->settle(RefreshOutcome::Stored, CarbonImmutable::now('UTC')->subHours(2));
        $this->entityManager()->persist($earlier);
        $this->entityManager()->flush();
        $component = $this->component($this->mine);
        $component->call('refresh');
        $this->settle(RefreshOutcome::Asleep);

        $html = (string) $component->refresh()->render();

        self::assertStringNotContainsString('data-wake-dialog', $html);
        self::assertStringContainsString('was already woken as often as allowed in 24 hours', $html);
        self::assertMatchesRegularExpression('/You can wake it again at [^(]+\(UTC\)\./', $html);
        self::assertStringContainsString('data-toast-announce-variant-value="warning"', $html);
    }

    public function testWhenWakingIsTurnedOffTheToastSaysSo(): void
    {
        $this->connect()->changeDailyWakeCap(0);
        $this->entityManager()->flush();
        $component = $this->component($this->mine);
        $component->call('refresh');
        $this->settle(RefreshOutcome::Asleep);

        $html = (string) $component->refresh()->render();

        self::assertStringNotContainsString('data-wake-dialog', $html);
        self::assertStringContainsString('waking is turned off in the Tesla settings', $html);
    }

    /**
     * @return iterable<string, array{RefreshOutcome, string, string}>
     */
    public static function wakeEndings(): iterable
    {
        yield 'timeout' => [RefreshOutcome::WakeTimeout, 'warning', 'did not come online within 90 seconds'];
        yield 'scope refused' => [RefreshOutcome::WakeScopeMissing, 'danger', 'Tesla refused the wake'];
        yield 'cap' => [RefreshOutcome::WakeCapReached, 'warning', 'already woken as often as allowed'];
        yield 'limited' => [RefreshOutcome::Limited, 'warning', 'Another refresh read the car first'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wakeEndings')]
    public function testHowAWakeEndedIsToldToTheUser(RefreshOutcome $outcome, string $variant, string $message): void
    {
        $this->connect();
        $component = $this->component($this->mine);
        $component->call('refresh');
        $this->settle($outcome);

        $html = (string) $component->refresh()->render();

        self::assertStringContainsString($message, $html);
        self::assertStringContainsString('data-toast-announce-variant-value="' . $variant . '"', $html);
        self::assertStringNotContainsString('data-poll', $html);
    }

    private function connect(string $scopes = 'openid offline_access vehicle_device_data vehicle_cmds'): TeslaApp
    {
        $app = self::createConfiguredApp($this->me);
        self::createToken($app, CarbonImmutable::now('UTC')->addHours(8), scopes: $scopes);

        return $app;
    }

    private function attemptId(): string
    {
        return $this->entityManager()->getRepository(RefreshAttempt::class)->findOneBy([])?->getId()->toRfc4122() ?? '';
    }

    private function settle(RefreshOutcome $outcome): void
    {
        $attempt = $this->entityManager()->getRepository(RefreshAttempt::class)->findOneBy([], ['requestedAt' => 'DESC']);
        self::assertInstanceOf(RefreshAttempt::class, $attempt);
        $attempt->settle($outcome, CarbonImmutable::now('UTC'));
        $this->entityManager()->flush();
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
