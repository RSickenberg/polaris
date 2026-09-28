<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;

#[CoversClass(LeaseTerm::class)]
#[CoversClass(EndDateConvention::class)]
final class LeaseTermTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, EndDateConvention, int, string}>
     */
    public static function wholeTerms(): iterable
    {
        yield '36 months, exclusive end' => ['2026-01-15', '2029-01-15', EndDateConvention::Exclusive, 36, '2029-01-15'];
        yield '36 months, inclusive end' => ['2026-01-15', '2029-01-14', EndDateConvention::Inclusive, 36, '2029-01-15'];
        yield 'one month' => ['2026-03-01', '2026-04-01', EndDateConvention::Exclusive, 1, '2026-04-01'];
        yield 'inclusive end on the last day of a month' => ['2026-03-01', '2026-03-31', EndDateConvention::Inclusive, 1, '2026-04-01'];
        yield 'inclusive end on December 31' => ['2026-01-01', '2026-12-31', EndDateConvention::Inclusive, 12, '2027-01-01'];
        yield 'month end clamped to February' => ['2026-01-31', '2026-02-28', EndDateConvention::Exclusive, 1, '2026-02-28'];
        yield 'month end clamped to February 29 in a leap year' => ['2028-01-31', '2028-02-29', EndDateConvention::Exclusive, 1, '2028-02-29'];
        yield 'from February 29 of a leap year' => ['2028-02-29', '2031-02-28', EndDateConvention::Exclusive, 36, '2031-02-28'];
        yield '48 months across a leap year' => ['2027-06-10', '2031-06-10', EndDateConvention::Exclusive, 48, '2031-06-10'];
    }

    #[DataProvider('wholeTerms')]
    public function testWholeTerm(string $start, string $end, EndDateConvention $convention, int $months, string $exclusiveEnd): void
    {
        $term = new LeaseTerm(self::date($start), self::date($end), $convention);

        self::assertSame($months, $term->months());
        self::assertSame($exclusiveEnd, $term->exclusiveEnd()->toDateString());
        self::assertSame($start, $term->start()->toDateString());
        self::assertSame($end, $term->end()->toDateString());
        self::assertSame($convention, $term->endDateConvention());
    }

    /**
     * @return iterable<string, array{string, string, EndDateConvention}>
     */
    public static function partialTerms(): iterable
    {
        yield 'one day short' => ['2026-01-15', '2029-01-14', EndDateConvention::Exclusive];
        yield 'one day over' => ['2026-01-15', '2029-01-15', EndDateConvention::Inclusive];
        yield 'partial month' => ['2026-01-15', '2026-03-01', EndDateConvention::Exclusive];
        yield 'same day' => ['2026-01-15', '2026-01-15', EndDateConvention::Exclusive];
        yield 'end before start' => ['2026-01-15', '2025-01-15', EndDateConvention::Exclusive];
        yield 'less than a month' => ['2026-01-15', '2026-02-10', EndDateConvention::Exclusive];
        yield 'February 28 is not a month end in a leap year' => ['2028-01-31', '2028-02-28', EndDateConvention::Exclusive];
    }

    #[DataProvider('partialTerms')]
    public function testPartialTermIsRejected(string $start, string $end, EndDateConvention $convention): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('whole number of months');

        new LeaseTerm(self::date($start), self::date($end), $convention);
    }

    public function testDateWithATimeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('midnight UTC');

        new LeaseTerm(CarbonImmutable::parse('2026-01-15 10:00', 'UTC'), self::date('2029-01-15'), EndDateConvention::Exclusive);
    }

    public function testDateInAnotherTimeZoneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('midnight UTC');

        new LeaseTerm(self::date('2026-01-15'), CarbonImmutable::parse('2029-01-15', 'Europe/Zurich'), EndDateConvention::Exclusive);
    }

    public function testFromCalendarDatesKeepsTheDateTheUserPicked(): void
    {
        // Midnight in Zurich is still January 14 in UTC: the calendar date must win.
        $term = LeaseTerm::fromCalendarDates(
            new \DateTimeImmutable('2026-01-15 00:00', new \DateTimeZone('Europe/Zurich')),
            new \DateTimeImmutable('2029-01-14 23:30', new \DateTimeZone('America/Los_Angeles')),
            EndDateConvention::Inclusive,
        );

        self::assertSame('2026-01-15', $term->start()->toDateString());
        self::assertSame('2029-01-14', $term->end()->toDateString());
        self::assertSame('UTC', $term->start()->getTimezone()->getName());
        self::assertSame(0, $term->end()->getOffset());
        self::assertSame(36, $term->months());
    }

    private static function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC');
    }
}
