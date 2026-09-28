<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Lease;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\ProjectionCalculator;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\OdometerSample;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(ProjectionCalculator::class)]
final class ProjectionCalculatorServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    public function testTheContainerWiresTheConfiguredWeightAndTheClock(): void
    {
        self::bootKernel();
        self::assertSame(6000, self::getContainer()->getParameter('polaris.lease.recent_pace_weight'));

        self::mockTime('2026-04-11 12:00');
        $calculator = self::getContainer()->get(ProjectionCalculator::class);

        // Recent (19,000,000 - 15,400,000) / 30 = 120,000 m/day, overall 9,000,000 / 100 = 90,000 m/day:
        // 0.6 x 120,000 + 0.4 x 90,000 = 108,000 m/day.
        $projection = $calculator->calculate(
            new LeaseTerm(new CarbonImmutable('2026-01-01', 'UTC'), new CarbonImmutable('2027-01-01', 'UTC'), EndDateConvention::Exclusive),
            Allowance::ofTotal(Distance::fromKilometres(36_500)),
            Distance::fromKilometres(10_000),
            [
                new OdometerSample(new CarbonImmutable('2026-03-12', 'UTC'), Distance::fromKilometres(15_400)),
                new OdometerSample(new CarbonImmutable('2026-04-11', 'UTC'), Distance::fromKilometres(19_000)),
            ],
        );

        self::assertNotNull($projection);
        self::assertSame(108_000.0, $projection->blendedPace->metresPerDay());
    }
}
