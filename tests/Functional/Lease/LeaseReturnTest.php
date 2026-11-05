<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Lease;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polaris\Lease\Controller\LeaseSettingsController;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Service\Exception\LeaseReturnRejected;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The car gone back to the bank: the lease settings, the stored instant, the final figures on the dashboard.
 */
#[CoversClass(LeaseSettingsController::class)]
#[CoversClass(LeaseTermsService::class)]
final class LeaseReturnTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::mockTime('2026-07-02 12:00:00 UTC');
        $this->client = self::createClient();
        $user = self::signIn($this->client);
        $user->changeTimeZone('Europe/Zurich');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000061'), 'Model 3', $user);
        $this->entityManager->persist($this->vehicle);
        // A one-year lease of 15,000 km from 2026-01-01.
        $this->entityManager->persist(new LeaseContract(
            $this->vehicle,
            LeaseTerm::fromCalendarDates(CarbonImmutable::parse('2026-01-01', 'UTC'), CarbonImmutable::parse('2027-01-01', 'UTC'), EndDateConvention::Exclusive),
            Allowance::ofTotal(Distance::fromKilometres(15_000)),
            Distance::fromKilometres(0),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent(0),
        ));
        $this->reading('2026-06-01 10:00:00', 8_000);
        $this->reading('2026-06-30 10:00:00', 9_000);
        $this->entityManager->flush();
    }

    public function testTheCardOffersTheReturnWhileTheLeaseRuns(): void
    {
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#lease-return');
        self::assertSelectorTextContains('#lease-return', 'Mark as returned');
        self::assertSelectorNotExists('#lease-return [data-returned]');
        self::assertSelectorNotExists('#lease-return form[action$="/lease/return/undo"]');
    }

    public function testTheCardIsInTheStickyColumnUnderTheProjection(): void
    {
        $this->client->request('GET', $this->url());

        self::assertSelectorExists('aside.lg\\:sticky #lease-return');
        self::assertSelectorNotExists('form[name=lease_contract] #lease-return');
    }

    public function testNoCardWithoutAContract(): void
    {
        $this->entityManager->remove($this->contract());
        $this->entityManager->flush();

        $this->client->request('GET', $this->url());

        self::assertSelectorNotExists('#lease-return');
    }

    public function testMarkingTheCarStoresTheEndOfTheDayInTheOwnersTimeZone(): void
    {
        $this->markReturned('2026-06-30');

        self::assertResponseRedirects($this->url());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-slot=alert]', 'The car is marked as returned.');
        self::assertSelectorTextContains('#lease-return [data-returned]', 'returned to the bank on June 30, 2026');

        // Zurich is UTC+2 on 2026-06-30: the end of that day is 22:00 UTC.
        self::assertSame('2026-06-30T22:00:00+00:00', $this->contract()->getReturnedAt()?->toIso8601String());
    }

    public function testTheDateCanBeMovedAndTheReturnUndone(): void
    {
        $this->markReturned('2026-06-30');
        $this->markReturned('2026-06-15');
        self::assertSame('2026-06-15T22:00:00+00:00', $this->contract()->getReturnedAt()?->toIso8601String());

        $this->client->request('GET', $this->url());
        self::assertInputValueSame('lease_return[returnedOn]', '2026-06-15');
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/lease/return/undo"]')->form());

        self::assertResponseRedirects($this->url());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-slot=alert]', 'The return was undone.');
        self::assertNull($this->contract()->getReturnedAt());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'before the lease start' => ['2025-12-31', 'The car cannot go back before the lease started.'];
        yield 'tomorrow' => ['2026-07-03', 'The car cannot be marked as returned on a day that has not come yet.'];
    }

    #[DataProvider('invalidDates')]
    public function testAnInvalidDateIsRejectedWithATranslatedMessage(string $date, string $message): void
    {
        $this->markReturned($date);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lease-return', $message);
        self::assertNull($this->contract()->getReturnedAt());

        $this->markReturned($date, 'fr');
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheErrorIsInFrench(): void
    {
        $this->markReturned('2025-12-31', 'fr');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lease-return', 'Le véhicule ne peut pas être rendu avant le début du leasing.');
    }

    public function testTodayIsAllowed(): void
    {
        $this->markReturned('2026-07-02');

        self::assertResponseRedirects($this->url());
        // The end of 2026-07-02 in Zurich.
        self::assertSame('2026-07-02T22:00:00+00:00', $this->contract()->getReturnedAt()?->toIso8601String());
    }

    public function testAMissingDateIsRejected(): void
    {
        $this->markReturned('');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->contract()->getReturnedAt());
    }

    public function testTheReturnNeedsItsCsrfToken(): void
    {
        $this->client->request('POST', $this->url() . '/return', ['lease_return' => ['returnedOn' => '2026-06-30']]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->contract()->getReturnedAt());
    }

    public function testTheUndoNeedsItsCsrfToken(): void
    {
        $this->markReturned('2026-06-30');

        $this->client->request('POST', $this->url() . '/return/undo', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->contract()->getReturnedAt());
    }

    public function testAnotherUsersVehicleIsNotFound(): void
    {
        $other = new Vehicle(new Vin('5YJ3E7EB2NF000062'), 'Theirs', self::createUser('other@example.test'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        foreach (['/return', '/return/undo'] as $suffix) {
            $this->client->request('POST', \sprintf('/vehicles/%s/lease%s', $other->getId()->toBase32(), $suffix));
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testSavingTheTermsKeepsTheReturn(): void
    {
        $this->markReturned('2026-06-30');

        $this->client->request('GET', $this->url());
        $this->client->submitForm('Save lease terms', ['lease_contract[allowance]' => '16000']);

        self::assertResponseRedirects($this->url());
        $contract = $this->contract();
        self::assertSame(16_000_000, $contract->getAllowance()->total()->metres());
        self::assertSame('2026-06-30T22:00:00+00:00', $contract->getReturnedAt()?->toIso8601String());
    }

    public function testAStartOnOrAfterTheReturnIsRejected(): void
    {
        $this->markReturned('2026-06-30');

        $this->client->request('GET', $this->url());
        $this->client->submitForm('Save lease terms', ['lease_contract[startDate]' => '2026-07-01', 'lease_contract[endDate]' => '2027-07-01']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'The start date must be before the day the car went back.');
        self::assertSame('2026-01-01', $this->contract()->getTerm()->start()->toDateString());
    }

    public function testTheDashboardShowsTheFinalFiguresAndNoRefreshButton(): void
    {
        $this->reading('2026-07-01 10:00:00', 20_000, flush: false);
        $this->markReturned('2026-06-30');

        $this->client->request('GET', \sprintf('/vehicles/%s', $this->vehicle->getId()->toBase32()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'The car was returned on June 30, 2026. These are the final figures.');
        // 9,000 km driven of 15,000: the later 20,000 km reading is not counted.
        self::assertSelectorTextContains('[data-slot=alert]', 'Returned within the 15,000 km allowance.');
        self::assertSelectorNotExists('[data-live-name-value="Fetching:OdometerRefreshButton"]');
        self::assertStringNotContainsString('Projected at lease end', $this->client->getCrawler()->filter('main')->text());
    }

    public function testTheDashboardShowsTheFinalCostOfAnExcess(): void
    {
        $this->reading('2026-07-01 10:00:00', 17_000);
        $this->markReturned('2026-07-01');

        $this->client->request('GET', \sprintf('/vehicles/%s', $this->vehicle->getId()->toBase32()), server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-slot=alert]', 'Rendu avec');
        self::assertSelectorTextContains('[data-slot=alert]', 'de coût définitif');
    }

    public function testTheCutoffDropsReadingsAndConfirmationsAfterTheReturn(): void
    {
        $service = $this->service();
        $last = $this->reading('2026-06-30 10:00:00', 9_000, flush: false, existing: true);
        $last->confirm(CarbonImmutable::parse('2026-07-01 07:00:00', 'UTC'));
        $this->reading('2026-07-01 08:00:00', 9_500);
        $this->entityManager->flush();

        $service->markReturned($this->vehicle, new \DateTimeImmutable('2026-06-30'));
        $returned = $service->assess($this->vehicle);

        self::assertInstanceOf(LeaseAssessment::class, $returned);
        self::assertSame(LeaseStatus::Returned, $returned->status);
        // The confirmation of 2026-07-01 07:00 is cut at the return (2026-06-30 22:00 UTC): the car still had 9,000 km.
        self::assertSame(9_000_000, $returned->projection->driven->metres());
        self::assertSame('2026-06-30T22:00:00+00:00', $returned->projection->asOf->toIso8601String());

        // Undoing brings the readings back: the car had 9,500 km on 2026-07-01.
        $service->undoReturn($this->vehicle);
        $active = $service->assess($this->vehicle);
        self::assertInstanceOf(LeaseAssessment::class, $active);
        self::assertSame(LeaseStatus::Active, $active->status);
        self::assertSame(9_500_000, $active->projection->driven->metres());
    }

    public function testMarkingWithoutAContractIsRejected(): void
    {
        $this->entityManager->remove($this->contract());
        $this->entityManager->flush();

        $this->expectException(LeaseReturnRejected::class);
        $this->service()->markReturned($this->vehicle, new \DateTimeImmutable('2026-06-30'));
    }

    public function testASecondUndoDoesNothing(): void
    {
        $service = $this->service();
        $service->undoReturn($this->vehicle);

        self::assertFalse($service->isReturned($this->vehicle));
        self::assertNull($service->returnedAt($this->vehicle));
        self::assertNull($service->returnedOn($this->vehicle));
    }

    public function testTheReturnedStateIsReadBack(): void
    {
        $service = $this->service();
        $service->markReturned($this->vehicle, new \DateTimeImmutable('2026-06-30'));

        self::assertTrue($service->isReturned($this->vehicle));
        self::assertSame('2026-06-30T22:00:00+00:00', $service->returnedAt($this->vehicle)?->toIso8601String());
        self::assertSame('2026-06-30', $service->returnedOn($this->vehicle)?->toDateString());
    }

    private function markReturned(string $date, string $locale = 'en'): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', $locale);
        $this->client->request('GET', $this->url());
        $this->client->submitForm('en' === $locale ? 'Confirm the return' : 'Confirmer la restitution', ['lease_return[returnedOn]' => $date]);
    }

    private function reading(string $at, int $km, bool $flush = true, bool $existing = false): OdometerReading
    {
        if ($existing) {
            $found = $this->entityManager->getRepository(OdometerReading::class)->findOneBy(['readAt' => CarbonImmutable::parse($at, 'UTC')]);
            \assert($found instanceof OdometerReading);

            return $found;
        }

        $reading = OdometerReading::manual($this->vehicle, CarbonImmutable::parse($at, 'UTC'), Distance::fromKilometres($km));
        $this->entityManager->persist($reading);

        if ($flush) {
            $this->entityManager->flush();
        }

        return $reading;
    }

    private function contract(): LeaseContract
    {
        $this->entityManager->clear();
        $contract = $this->entityManager->getRepository(LeaseContract::class)->findOneBy([]);
        \assert($contract instanceof LeaseContract);

        return $contract;
    }

    private function service(): LeaseTermsService
    {
        return self::getContainer()->get(LeaseTermsService::class);
    }

    private function url(): string
    {
        return \sprintf('/vehicles/%s/lease', $this->vehicle->getId()->toBase32());
    }
}
