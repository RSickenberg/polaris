<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Lease;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Service\LeaseAssessmentService;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
use Polaris\Shared\Domain\OdometerSample;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(LeaseAssessmentService::class)]
final class LeaseAssessmentServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    public function testTheContainerWiresTheAssessmentService(): void
    {
        self::bootKernel();
        self::mockTime('2026-04-11 12:00');

        $assessment = self::getContainer()->get(LeaseAssessmentService::class)->assess(
            new LeaseContract(
                new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3'),
                new LeaseTerm(new CarbonImmutable('2026-01-01', 'UTC'), new CarbonImmutable('2027-01-01', 'UTC'), EndDateConvention::Exclusive),
                Allowance::ofTotal(Distance::fromKilometres(36_500)),
                Distance::fromKilometres(10_000),
                Money::fromDecimal('0.45', Currency::CHF),
                Tolerance::fromBasisPoints(0),
            ),
            [
                new OdometerSample(new CarbonImmutable('2026-03-12', 'UTC'), Distance::fromKilometres(15_400)),
                new OdometerSample(new CarbonImmutable('2026-04-11', 'UTC'), Distance::fromKilometres(19_000)),
            ],
        );

        // Projected 37,620,000 m against 36,500,000 m.
        self::assertInstanceOf(LeaseAssessment::class, $assessment);
        self::assertSame(RiskLevel::Over, $assessment->riskLevel);
    }
}
