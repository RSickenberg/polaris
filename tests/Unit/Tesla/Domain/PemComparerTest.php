<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\PemComparer;

#[CoversClass(PemComparer::class)]
final class PemComparerTest extends TestCase
{
    private const string KEY = "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE\n-----END PUBLIC KEY-----\n";

    public function testTheSameKeyMatches(): void
    {
        self::assertTrue(new PemComparer()->same(self::KEY, self::KEY));
    }

    public function testLineEndingsAndTrailingNewlinesAreIgnored(): void
    {
        $served = str_replace("\n", "\r\n", rtrim(self::KEY)) . "\r\n\r\n";

        self::assertTrue(new PemComparer()->same(self::KEY, $served));
    }

    public function testAnotherKeyDoesNotMatch(): void
    {
        $other = str_replace('MFkwEwYH', 'MFkwEwYX', self::KEY);

        self::assertFalse(new PemComparer()->same(self::KEY, $other));
    }

    public function testTwoEmptyTextsDoNotMatch(): void
    {
        self::assertFalse(new PemComparer()->same('', " \n"));
    }
}
