<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Domain\LocaleNegotiator;

#[CoversClass(LocaleNegotiator::class)]
final class LocaleNegotiatorTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function cases(): iterable
    {
        yield 'preference wins over the header' => ['fr', 'en-US,en;q=0.9', 'fr'];
        yield 'preference wins when the header is empty' => ['en', null, 'en'];
        yield 'unsupported preference falls through to the header' => ['de', 'fr', 'fr'];
        yield 'preference is case and separator tolerant' => ['FR', null, 'fr'];

        yield 'header with a single language' => [null, 'fr', 'fr'];
        yield 'regional variant matches its language' => [null, 'fr-CH', 'fr'];
        yield 'quality values decide, not the order' => [null, 'en;q=0.5, fr;q=0.9', 'fr'];
        yield 'same quality keeps the header order' => [null, 'fr, en', 'fr'];
        yield 'browser style header' => [null, 'fr-CH, fr;q=0.9, en;q=0.8, de;q=0.7, *;q=0.5', 'fr'];
        yield 'unsupported languages are skipped' => [null, 'de, es;q=0.9, fr;q=0.5', 'fr'];
        yield 'q=0 means not acceptable' => [null, 'fr;q=0, en;q=0.4', 'en'];
        yield 'unreadable quality is ignored' => [null, 'fr;q=abc, en;q=0.2', 'en'];

        yield 'no header' => [null, null, 'en'];
        yield 'empty header' => [null, '', 'en'];
        yield 'unsupported language falls back to the default' => [null, 'de-DE,de;q=0.9', 'en'];
        yield 'wildcard alone falls back to the default' => [null, '*', 'en'];
        yield 'garbage falls back to the default' => [null, ';;,,q=1', 'en'];
    }

    #[DataProvider('cases')]
    public function testNegotiate(?string $preferred, ?string $acceptLanguage, string $expected): void
    {
        self::assertSame($expected, new LocaleNegotiator(['en', 'fr'], 'en')->negotiate($preferred, $acceptLanguage));
    }

    public function testDefaultIsUsedWhenNothingMatches(): void
    {
        self::assertSame('fr', new LocaleNegotiator(['en', 'fr'], 'fr')->negotiate(null, 'de'));
    }
}
