<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Service;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseAssessor;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\NoData;
use Polaris\Lease\Domain\ProjectionCalculator;
use Polaris\Lease\Domain\RecentPaceWeight;
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
use Symfony\Component\Clock\MockClock;

#[CoversClass(LeaseAssessmentService::class)]
final class LeaseAssessmentServiceTest extends TestCase
{
    public function testAssessesTheContractTermsAgainstTheSamples(): void
    {
        $service = self::service();
        $contract = new LeaseContract(
            new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3'),
            new LeaseTerm(new CarbonImmutable('2026-01-01', 'UTC'), new CarbonImmutable('2027-01-01', 'UTC'), EndDateConvention::Exclusive),
            Allowance::ofTotal(Distance::fromKilometres(36_500)),
            Distance::fromKilometres(10_000),
            Money::fromDecimal('0.45', Currency::CHF),
            Tolerance::fromBasisPoints(0),
        );

        // Day 100, driven 11,000,000: projected 38,560,000, excess 2,060,000 m x 0.45 = 92,700 centimes.
        $assessment = $service->assess($contract, [
            new OdometerSample(new CarbonImmutable('2026-03-12', 'UTC'), Distance::fromMetres(18_000_000)),
            new OdometerSample(new CarbonImmutable('2026-04-11', 'UTC'), Distance::fromMetres(21_000_000)),
        ]);

        self::assertInstanceOf(LeaseAssessment::class, $assessment);
        self::assertSame(RiskLevel::Over, $assessment->riskLevel);
        self::assertSame(92_700, $assessment->projectedExcessCost->minorAmount());
        self::assertInstanceOf(NoData::class, $service->assess($contract, []));
    }

    private static function service(): LeaseAssessmentService
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-04-11 12:00', new \DateTimeZone('UTC')));

        return new LeaseAssessmentService(new LeaseAssessor(new ProjectionCalculator($clock, RecentPaceWeight::fromBasisPoints(6_000)), $clock));
    }
}
