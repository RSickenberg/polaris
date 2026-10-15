<?php

declare(strict_types=1);

namespace Polaris\Tests\Support;

use Carbon\CarbonImmutable;
use Polaris\Account\Entity\User;

/**
 * A user to own a vehicle in a unit test: it is never persisted.
 */
final class Owner
{
    public static function user(): User
    {
        return new User('owner@example.test', 'not-a-real-hash', CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'));
    }
}
