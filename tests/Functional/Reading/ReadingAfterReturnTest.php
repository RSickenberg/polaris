<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Reading;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Controller\OdometerReadingController;
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
 * Manual entry always works: a reading typed after the return is stored, with a warning that it is not counted.
 */
#[CoversClass(OdometerReadingController::class)]
final class ReadingAfterReturnTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private KernelBrowser $client;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::mockTime('2026-07-10 12:00:00 UTC');
        $this->client = self::createClient();
        $user = self::signIn($this->client);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000063'), 'Model 3', $user);
        $entityManager->persist($this->vehicle);
        $entityManager->persist(new LeaseContract(
            $this->vehicle,
            LeaseTerm::fromCalendarDates(CarbonImmutable::parse('2026-01-01', 'UTC'), CarbonImmutable::parse('2027-01-01', 'UTC'), EndDateConvention::Exclusive),
            Allowance::ofTotal(Distance::fromKilometres(15_000)),
            Distance::fromKilometres(0),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent(0),
        ));
        $entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-06-30 10:00:00', 'UTC'), Distance::fromKilometres(9_000)));
        $entityManager->flush();
        self::getContainer()->get(LeaseTermsService::class)->markReturned($this->vehicle, new \DateTimeImmutable('2026-06-30'));
    }

    public function testAReadingAfterTheReturnIsKeptWithAWarning(): void
    {
        $this->submit('2026-07-05T10:00', '9500');

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-flash=warning]', 'the car was returned on June 30, 2026');
        self::assertSelectorTextContains('[data-flash=warning]', 'not counted');
        self::assertCount(2, self::getContainer()->get(EntityManagerInterface::class)->getRepository(OdometerReading::class)->findAll());
    }

    public function testAReadingBeforeTheReturnIsAnOrdinaryOne(): void
    {
        $this->submit('2026-06-30T12:00', '9100');

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorNotExists('[data-flash=warning]');
        self::assertSelectorExists('[data-flash=success]');
    }

    private function submit(string $readAt, string $odometer): void
    {
        $this->client->request('GET', \sprintf('/vehicles/%s/readings', $this->vehicle->getId()->toBase32()));
        $this->client->submitForm('Add reading', [
            'odometer_reading[readAt]' => $readAt,
            'odometer_reading[odometer]' => $odometer,
            'odometer_reading[distanceUnit]' => 'km',
        ]);
    }
}
