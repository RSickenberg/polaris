<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Domain;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\OdometerSample;

#[CoversClass(OdometerSample::class)]
final class OdometerSampleTest extends TestCase
{
    public function testHoldsTheInstantAndTheOdometer(): void
    {
        $readAt = new CarbonImmutable('2026-04-11 08:30:15', 'UTC');
        $sample = new OdometerSample($readAt, Distance::fromMetres(19_000_000));

        self::assertSame($readAt, $sample->readAt());
        self::assertSame(19_000_000, $sample->odometer()->metres());
    }

    public function testNonUtcInstantIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OdometerSample(new CarbonImmutable('2026-04-11 08:30', 'Europe/Zurich'), Distance::fromMetres(0));
    }
}
