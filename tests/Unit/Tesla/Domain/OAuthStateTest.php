<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\OAuthState;

#[CoversClass(OAuthState::class)]
final class OAuthStateTest extends TestCase
{
    public function testAStateIsRandomAndLong(): void
    {
        $first = OAuthState::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertNotSame($first, OAuthState::generate());
    }

    public function testOnlyTheSameStateMatches(): void
    {
        self::assertTrue(OAuthState::matches('abc', 'abc'));
        self::assertFalse(OAuthState::matches('abc', 'abd'));
        self::assertFalse(OAuthState::matches('abc', null));
        self::assertFalse(OAuthState::matches(null, 'abc'));
        self::assertFalse(OAuthState::matches(null, null));
        self::assertFalse(OAuthState::matches('', ''));
    }
}
