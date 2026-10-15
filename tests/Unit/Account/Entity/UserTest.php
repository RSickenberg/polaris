<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Account\Entity;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Account\Entity\User;
use Symfony\Component\Uid\Ulid;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    public function testGetsAUlidAndTheDefaults(): void
    {
        $user = $this->user();

        self::assertTrue(Ulid::isValid((string) $user->getId()));
        self::assertSame('en', $user->getLocale());
        self::assertSame('UTC', $user->getTimeZone());
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testTheEmailIsTrimmedAndLowerCased(): void
    {
        $user = new User('  Driver@Example.TEST ', 'hash', CarbonImmutable::parse('2026-01-01'));

        self::assertSame('driver@example.test', $user->getEmail());
        self::assertSame('driver@example.test', $user->getUserIdentifier());
    }

    public function testCreatedAtIsUtc(): void
    {
        $user = new User('driver@example.test', 'hash', CarbonImmutable::parse('2026-01-01 12:00', 'Europe/Zurich'));

        self::assertSame('2026-01-01 11:00:00', $user->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame(0, $user->getCreatedAt()->getOffset());
    }

    public function testAnEmptyEmailIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User('   ', 'hash', CarbonImmutable::parse('2026-01-01'));
    }

    public function testTheTimeZoneMustBeAnIanaName(): void
    {
        $user = $this->user();
        $user->changeTimeZone('Europe/Zurich');
        self::assertSame('Europe/Zurich', $user->getTimeZone());

        $this->expectException(\InvalidArgumentException::class);
        $user->changeTimeZone('Mars/Olympus');
    }

    public function testTheLocaleMustBeACode(): void
    {
        $user = $this->user();
        $user->changeLocale('fr');
        self::assertSame('fr', $user->getLocale());

        $this->expectException(\InvalidArgumentException::class);
        $user->changeLocale('<script>');
    }

    public function testTheSessionKeepsAChecksumNotTheHash(): void
    {
        $user = new User('driver@example.test', 'a-secret-looking-hash', CarbonImmutable::parse('2026-01-01'));

        $serialized = serialize($user);

        self::assertStringNotContainsString('a-secret-looking-hash', $serialized);
        self::assertStringContainsString(hash('crc32c', 'a-secret-looking-hash'), $serialized);
    }

    private function user(): User
    {
        return new User('driver@example.test', 'hash', CarbonImmutable::parse('2026-01-01'));
    }
}
