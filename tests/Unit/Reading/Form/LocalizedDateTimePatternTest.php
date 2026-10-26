<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Reading\Form;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Reading\Form\LocalizedDateTimePattern;

#[CoversClass(LocalizedDateTimePattern::class)]
final class LocalizedDateTimePatternTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function locales(): iterable
    {
        yield 'english' => ['en', 'MM/dd/yyyy, hh:mm a', '12/31/2026, 11:59 PM'];
        yield 'french' => ['fr', 'dd/MM/yyyy HH:mm', '31/12/2026 23:59'];
    }

    #[DataProvider('locales')]
    public function testThePatternAndTheExampleFollowTheLocale(string $locale, string $pattern, string $example): void
    {
        self::assertSame($pattern, LocalizedDateTimePattern::for($locale));
        self::assertSame($example, LocalizedDateTimePattern::example($locale));
    }
}
