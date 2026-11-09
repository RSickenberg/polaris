<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Reading;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Reading\Domain\ReadingRejection;

#[CoversClass(ReadingRejection::class)]
final class ReadingRejectionTest extends TestCase
{
    public function testADuplicateOrFutureInstantIsAboutTheDateAndTheRestAboutTheValue(): void
    {
        self::assertTrue(ReadingRejection::DuplicateInstant->concernsTheDate());
        self::assertTrue(ReadingRejection::InFuture->concernsTheDate());
        self::assertFalse(ReadingRejection::LowerThanPrevious->concernsTheDate());
        self::assertFalse(ReadingRejection::HigherThanNext->concernsTheDate());
    }
}
