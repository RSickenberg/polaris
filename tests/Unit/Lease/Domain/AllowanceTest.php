<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Shared\Domain\Distance;

#[CoversClass(Allowance::class)]
final class AllowanceTest extends TestCase
{
    /**
     * @return iterable<string, array{Distance, string, string, EndDateConvention, int}>
     */
    public static function yearlyAllowances(): iterable
    {
        yield '15,000 km over 3 years' => [Distance::fromKilometres(15_000), '2026-01-15', '2029-01-15', EndDateConvention::Exclusive, 45_000_000];
        yield 'same total with an inclusive end' => [Distance::fromKilometres(15_000), '2026-01-15', '2029-01-14', EndDateConvention::Inclusive, 45_000_000];
        yield '15,000 km over 7 months' => [Distance::fromKilometres(15_000), '2026-01-01', '2026-08-01', EndDateConvention::Exclusive, 8_750_000];
        yield '10,000 km over 1 month, rounded down' => [Distance::fromKilometres(10_000), '2026-01-01', '2026-02-01', EndDateConvention::Exclusive, 833_333];
        yield '20,000 km over 1 month, rounded up' => [Distance::fromKilometres(20_000), '2026-01-01', '2026-02-01', EndDateConvention::Exclusive, 1_666_667];
        yield 'half a metre, rounded half up' => [Distance::fromMetres(6), '2026-01-01', '2026-02-01', EndDateConvention::Exclusive, 1];
        yield 'leap year does not change the total' => [Distance::fromKilometres(12_000), '2028-01-01', '2029-01-01', EndDateConvention::Exclusive, 12_000_000];
        yield 'term starting on February 29' => [Distance::fromKilometres(12_000), '2028-02-29', '2029-02-28', EndDateConvention::Exclusive, 12_000_000];
        yield 'miles per year' => [Distance::fromMiles(10_000), '2026-01-15', '2029-01-15', EndDateConvention::Exclusive, 48_280_320];
    }

    #[DataProvider('yearlyAllowances')]
    public function testFromYearly(Distance $perYear, string $start, string $end, EndDateConvention $convention, int $totalMetres): void
    {
        $term = new LeaseTerm(CarbonImmutable::parse($start, 'UTC'), CarbonImmutable::parse($end, 'UTC'), $convention);

        self::assertSame($totalMetres, Allowance::fromYearly($perYear, $term)->total()->metres());
    }

    public function testOfTotal(): void
    {
        self::assertSame(45_000_000, Allowance::ofTotal(Distance::fromKilometres(45_000))->total()->metres());
    }

    public function testZeroTotalIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Allowance::ofTotal(Distance::fromMetres(0));
    }

    public function testZeroYearlyAllowanceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Allowance::fromYearly(Distance::fromMetres(0), self::threeYears());
    }

    public function testYearlyAllowanceRoundingToZeroIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Allowance::fromYearly(Distance::fromMetres(5), new LeaseTerm(CarbonImmutable::parse('2026-01-01', 'UTC'), CarbonImmutable::parse('2026-02-01', 'UTC'), EndDateConvention::Exclusive));
    }

    public function testOverflowingYearlyAllowanceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('too large');

        Allowance::fromYearly(Distance::fromMetres(\PHP_INT_MAX), self::threeYears());
    }

    private static function threeYears(): LeaseTerm
    {
        return new LeaseTerm(CarbonImmutable::parse('2026-01-15', 'UTC'), CarbonImmutable::parse('2029-01-15', 'UTC'), EndDateConvention::Exclusive);
    }
}
