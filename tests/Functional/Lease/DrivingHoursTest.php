<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Lease;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Lease\Controller\LeaseSettingsController;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Service\DrivingHoursService;
use Polaris\Lease\Service\Enum\DrivingHoursRejection;
use Polaris\Lease\Service\Exception\DrivingHoursRejected;
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
 * The hours the owner does not drive: the card on the lease settings, and how "today" is counted with them.
 */
#[CoversClass(LeaseSettingsController::class)]
#[CoversClass(DrivingHoursService::class)]
final class DrivingHoursTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::mockTime('2026-03-02 22:00:00 UTC');
        $this->client = self::createClient();
        $user = self::signIn($this->client);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000071'), 'Model 3', $user);
        $this->entityManager->persist($this->vehicle);
        $this->entityManager->persist(new LeaseContract(
            $this->vehicle,
            LeaseTerm::fromCalendarDates(
                CarbonImmutable::parse('2026-01-01', 'UTC'),
                CarbonImmutable::parse('2027-01-01', 'UTC'),
                EndDateConvention::Exclusive,
            ),
            Allowance::ofTotal(Distance::fromKilometres(15_000)),
            Distance::fromMetres(10_000_000),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent(0),
        ));
        $this->entityManager->flush();
    }

    public function testTheHoursAreSavedFromTheSettingsPage(): void
    {
        $crawler = $this->client->request('GET', $this->url());
        self::assertSelectorExists('#lease-driving-hours');

        $this->client->submit($crawler->selectButton('Save my driving hours')->form([
            'driving_hours[from]' => '22:00',
            'driving_hours[until]' => '06:00',
        ]));

        self::assertResponseRedirects($this->url());
        $this->entityManager->clear();
        self::assertSame(
            ['from' => '22:00', 'until' => '06:00'],
            self::getContainer()->get(DrivingHoursService::class)->find($this->vehicle),
        );
    }

    public function testOneTimeAloneIsRefusedAndNothingIsSaved(): void
    {
        $crawler = $this->client->request('GET', $this->url());
        $this->client->submit($crawler->selectButton('Save my driving hours')->form([
            'driving_hours[from]' => '22:00',
            'driving_hours[until]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lease-driving-hours', 'Fill in both times');
        self::assertSame(
            ['from' => null, 'until' => null],
            self::getContainer()->get(DrivingHoursService::class)->find($this->vehicle),
        );
    }

    public function testTwoEqualTimesAreRefused(): void
    {
        $this->expectException(DrivingHoursRejected::class);

        try {
            self::getContainer()->get(DrivingHoursService::class)->save($this->vehicle, '22:00', '22:00');
        } catch (DrivingHoursRejected $rejected) {
            self::assertSame(DrivingHoursRejection::SameTime, $rejected->reason);

            throw $rejected;
        }
    }

    public function testClearingTheHoursRestoresTheLinearOdometer(): void
    {
        $hours = self::getContainer()->get(DrivingHoursService::class);
        $hours->save($this->vehicle, '20:00', '06:00');
        $hours->save($this->vehicle, null, null);

        self::assertSame(['from' => null, 'until' => null], $hours->find($this->vehicle));
    }

    public function testTheOdometerAtMidnightIgnoresTheNight(): void
    {
        // 21:00 on 1 March at 10,000 km (UTC owner), 21:00 on 2 March at 10,060 km.
        $this->reading('2026-03-01 21:00:00', 10_000_000);
        $this->reading('2026-03-02 21:00:00', 10_060_000);
        $midnight = new \DateTimeImmutable('2026-03-02 00:00:00 UTC')->getTimestamp();
        $terms = self::getContainer()->get(LeaseTermsService::class);

        self::assertSame(10_007_500.0, $terms->odometerTimeline($this->vehicle)?->odometerAt($midnight));

        self::getContainer()->get(DrivingHoursService::class)->save($this->vehicle, '20:00', '06:00');

        self::assertSame(10_000_000.0, $terms->odometerTimeline($this->vehicle)?->odometerAt($midnight));
    }

    private function reading(string $at, int $metres): void
    {
        $this->entityManager->persist(OdometerReading::manual(
            $this->vehicle,
            CarbonImmutable::parse($at, 'UTC'),
            Distance::fromMetres($metres),
        ));
        $this->entityManager->flush();
    }

    private function url(): string
    {
        return \sprintf('/vehicles/%s/lease', $this->vehicle->getId()->toBase32());
    }
}
