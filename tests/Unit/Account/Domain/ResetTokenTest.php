<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Account\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Account\Domain\ResetToken;

#[CoversClass(ResetToken::class)]
final class ResetTokenTest extends TestCase
{
    public function testTheLinkTextRoundTrips(): void
    {
        $token = ResetToken::generate();

        $parsed = ResetToken::parse($token->toString());

        self::assertNotNull($parsed);
        self::assertSame($token->selector(), $parsed->selector());
        self::assertSame($token->hashedVerifier(), $parsed->hashedVerifier());
        self::assertSame(63, \strlen($token->toString()));
        self::assertSame(20, \strlen($token->selector()));
    }

    public function testTokensDiffer(): void
    {
        self::assertNotSame(ResetToken::generate()->toString(), ResetToken::generate()->toString());
    }

    public function testOnlyTheHashOfTheVerifierIsExposed(): void
    {
        $token = ResetToken::generate();

        self::assertSame(64, \strlen($token->hashedVerifier()));
        self::assertStringNotContainsString(substr($token->toString(), 20), $token->hashedVerifier());
    }

    public function testItMatchesItsOwnHashOnly(): void
    {
        $token = ResetToken::generate();

        self::assertTrue($token->matches($token->hashedVerifier()));
        self::assertFalse($token->matches(ResetToken::generate()->hashedVerifier()));
        self::assertFalse($token->matches(''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['abc'];
        yield 'too long' => [str_repeat('a', 64)];
        yield 'bad character' => [str_repeat('a', 62) . '!'];
        yield 'space' => [str_repeat('a', 62) . ' '];
    }

    #[DataProvider('notTokens')]
    public function testSomethingElseIsNotAToken(string $text): void
    {
        self::assertNull(ResetToken::parse($text));
    }
}
