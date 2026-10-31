<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Shared\Reporting;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Entity\User;
use Polaris\Account\EventListener\BindReportingUser;
use Polaris\Account\Service\UserReportingPreferences;
use Polaris\Alert\Message\CheckRiskLevel;
use Polaris\Fetching\Message\DispatchDueFetches;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Shared\Reporting\BreadcrumbFilter;
use Polaris\Shared\Reporting\EventFilter;
use Polaris\Shared\Reporting\LogFilter;
use Polaris\Shared\Reporting\ReportingContext;
use Polaris\Shared\Reporting\ReportingGate;
use Polaris\Shared\Reporting\Scrubber;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\EventListener\BindReportingUserOfMessage;
use Polaris\Vehicle\Service\VehicleOwnerLookup;
use Sentry\Event;
use Sentry\Logs\Log;
use Sentry\Logs\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * An opted-out user's data never reaches Sentry: the gate (behind before_send, before_send_transaction and
 * before_send_log) drops it, in a request and in a Messenger handler, and drops it too when the user cannot be found.
 * An event with no user follows the instance switch alone (the DSN, tested in SentryWiringTest).
 */
#[CoversClass(ReportingGate::class)]
#[CoversClass(EventFilter::class)]
#[CoversClass(LogFilter::class)]
#[CoversClass(BreadcrumbFilter::class)]
#[CoversClass(BindReportingUser::class)]
#[CoversClass(BindReportingUserOfMessage::class)]
#[CoversClass(ReportingContext::class)]
final class ReportingGateTest extends KernelTestCase
{
    use SignsIn;

    private const string VIN = '5YJ3E7EB2NF000091';

    private EntityManagerInterface $entityManager;
    private ReportingContext $context;
    private EventFilter $events;
    private LogFilter $logs;
    private BreadcrumbFilter $crumbs;
    private KernelInterface $appKernel;
    private BindReportingUser $bindRequestUser;
    private BindReportingUserOfMessage $bindMessageUser;

    protected function setUp(): void
    {
        $this->appKernel = self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);

        // The same classes the production wiring uses, put together by hand: the Sentry bundle (and so the services
        // that only it references) does not exist in the test environment.
        $this->context = new ReportingContext();
        $scrubber = new Scrubber();
        $gate = new ReportingGate($this->context, new UserReportingPreferences($connection));
        $this->events = new EventFilter($gate, $scrubber);
        $this->logs = new LogFilter($gate, $scrubber);
        $this->crumbs = new BreadcrumbFilter($scrubber);
        $this->bindRequestUser = new BindReportingUser($container->get('security.token_storage'), $this->context);
        $this->bindMessageUser = new BindReportingUserOfMessage($this->context, new VehicleOwnerLookup($connection));
    }

    public function testAnEventWithNoUserFollowsTheInstanceSwitchAlone(): void
    {
        $event = $this->events->__invoke($this->event());

        self::assertInstanceOf(Event::class, $event);
        self::assertNull($event->getUser());
        self::assertStringNotContainsString(self::VIN, (string) $event->getMessage());
    }

    public function testAnOptedInUserIsSentWithTheirUlidAndNothingElse(): void
    {
        $user = $this->user('in@example.test', true, false);
        $this->context->enterUser($user->getId()->toRfc4122());

        $event = $this->events->__invoke($this->event());

        self::assertInstanceOf(Event::class, $event);
        self::assertSame($user->getId()->toRfc4122(), $event->getUser()?->getId());
        self::assertNull($event->getUser()->getEmail());
        self::assertNull($event->getUser()->getIpAddress());
        self::assertNull($event->getUser()->getUsername());
    }

    public function testAnOptedOutUserIsNeverSent(): void
    {
        $user = $this->user('out@example.test', false, false);
        $this->context->enterUser($user->getId()->toRfc4122());

        self::assertNull($this->events->__invoke($this->event()));
        self::assertNull($this->logs->__invoke($this->log()));
    }

    public function testAUserThatCannotBeFoundIsNeverSent(): void
    {
        $this->context->enterUser((string) new \Symfony\Component\Uid\Ulid());
        self::assertNull($this->events->__invoke($this->event()));

        $this->context->enterUnresolved();
        self::assertNull($this->events->__invoke($this->event()));
        self::assertNull($this->logs->__invoke($this->log()));
    }

    public function testTheOptOutCountsFromTheNextReport(): void
    {
        $user = $this->user('later@example.test', true, false);
        $this->context->enterUser($user->getId()->toRfc4122());
        self::assertInstanceOf(Event::class, $this->events->__invoke($this->event()));

        $user->changeErrorReporting(false, false);
        $this->entityManager->flush();

        self::assertNull($this->events->__invoke($this->event()));
    }

    public function testVehicleDataStaysOnlyWhenTheUserAllowedIt(): void
    {
        $without = $this->user('plain@example.test', true, false);
        $this->context->enterUser($without->getId()->toRfc4122());
        $event = $this->event();
        $event->setExtra(['vehicle_data' => ['charge_state' => ['battery_level' => 80]]]);
        self::assertSame(['vehicle_data' => '[Filtered]'], $this->events->__invoke($event)?->getExtra());

        $with = $this->user('detail@example.test', true, true);
        $this->context->enterUser($with->getId()->toRfc4122());
        $event = $this->event();
        $event->setExtra(['vehicle_data' => ['charge_state' => ['battery_level' => 80]]]);
        self::assertSame(['vehicle_data' => ['charge_state' => ['battery_level' => 80]]], $this->events->__invoke($event)?->getExtra());
    }

    public function testALogOfAnOptedInUserIsScrubbedAndCarriesTheUlid(): void
    {
        $user = $this->user('log@example.test', true, false);
        $this->context->enterUser($user->getId()->toRfc4122());

        $log = $this->logs->__invoke($this->log());

        self::assertInstanceOf(Log::class, $log);
        self::assertSame('Refused for [Filtered] Bearer-less', $log->getBody());
        self::assertSame($user->getId()->toRfc4122(), $log->attributes()->get('user.id')?->getValue());
    }

    public function testASignedInRequestBindsItsUserBeforeTheController(): void
    {
        $user = $this->user('web@example.test', false, false);
        $tokens = self::getContainer()->get('security.token_storage');
        $tokens->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles()));

        $this->controllerRuns();

        self::assertSame($user->getId()->toRfc4122(), $this->context->userId());
        self::assertNull($this->events->__invoke($this->event()));

        $tokens->setToken(null);
        $this->controllerRuns();
        self::assertNull($this->context->userId());
    }

    public function testAMessageAboutAVehicleOfAnOptedOutUserIsNeverSent(): void
    {
        $vehicle = $this->vehicleOf($this->user('driver@example.test', false, false), 'A');

        $this->receive(new FetchOdometerMessage($vehicle->getId()->toRfc4122()));
        self::assertNull($this->events->__invoke($this->event()));
        self::assertNull($this->logs->__invoke($this->log()));

        $this->receive(new CheckRiskLevel($vehicle->getId()->toRfc4122()));
        self::assertNull($this->events->__invoke($this->event()));
    }

    public function testAMessageAboutAVehicleOfAnOptedInUserIsSentWithTheirUlid(): void
    {
        $owner = $this->user('driver-in@example.test', true, false);
        $vehicle = $this->vehicleOf($owner, 'B');

        $this->receive(new FetchOdometerMessage($vehicle->getId()->toRfc4122()));

        self::assertSame($owner->getId()->toRfc4122(), $this->events->__invoke($this->event())?->getUser()?->getId());
    }

    public function testAMessageAboutAVehicleThatIsGoneIsNeverSent(): void
    {
        $this->receive(new FetchOdometerMessage((string) new \Symfony\Component\Uid\Ulid()));

        self::assertTrue($this->context->isUnresolved());
        self::assertNull($this->events->__invoke($this->event()));
    }

    public function testNoContextIsCarriedFromOneMessageToTheNext(): void
    {
        $out = $this->vehicleOf($this->user('first@example.test', false, false), 'C');
        $in = $this->vehicleOf($this->user('second@example.test', true, false), 'D');

        $this->receive(new FetchOdometerMessage($out->getId()->toRfc4122()));
        self::assertNull($this->events->__invoke($this->event()));

        $this->receive(new FetchOdometerMessage($in->getId()->toRfc4122()));
        self::assertSame($in->getOwner()->getId()->toRfc4122(), $this->events->__invoke($this->event())?->getUser()?->getId());

        // The scheduler tick belongs to no user: neither the previous nor a dropped one.
        $this->receive(new DispatchDueFetches());
        self::assertNull($this->context->userId());
        self::assertFalse($this->context->isUnresolved());
        $event = $this->events->__invoke($this->event());
        self::assertInstanceOf(Event::class, $event);
        self::assertNull($event->getUser());
    }

    public function testABreadcrumbIsScrubbed(): void
    {
        $crumb = $this->crumbs->__invoke(new \Sentry\Breadcrumb(\Sentry\Breadcrumb::LEVEL_INFO, \Sentry\Breadcrumb::TYPE_DEFAULT, 'app', 'GET /vehicles/' . self::VIN));

        self::assertSame('GET /vehicles/[Filtered]', $crumb->getMessage());
    }

    private function controllerRuns(): void
    {
        $this->bindRequestUser->onController(
            new ControllerEvent($this->appKernel, static fn (): null => null, new Request(), HttpKernelInterface::MAIN_REQUEST),
        );
    }

    private function receive(object $message): void
    {
        $this->bindMessageUser->onMessageReceived(new WorkerMessageReceivedEvent(new Envelope($message), 'tesla'));
    }

    private function user(string $email, bool $enabled, bool $details): User
    {
        $user = self::createUser($email);
        $user->changeErrorReporting($enabled, $details);
        $this->entityManager->flush();

        return $user;
    }

    private function vehicleOf(User $owner, string $suffix): Vehicle
    {
        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF00009' . $suffix), 'Model 3', $owner);
        $this->entityManager->persist($vehicle);
        $this->entityManager->flush();

        return $vehicle;
    }

    private function event(): Event
    {
        $event = Event::createEvent();
        $event->setMessage('Fetch failed for ' . self::VIN);

        return $event;
    }

    private function log(): Log
    {
        return new Log(microtime(true), 'trace', LogLevel::warn(), 'Refused for ' . self::VIN . ' Bearer-less');
    }
}
