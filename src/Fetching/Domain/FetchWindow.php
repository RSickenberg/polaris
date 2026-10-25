<?php

declare(strict_types=1);

namespace Polaris\Fetching\Domain;

use Carbon\CarbonImmutable;

/**
 * The retry rule of a scheduled fetch: when the car is not online at the slot, its state is checked again
 * every hour until 3 hours after the slot, then the slot is skipped. That is 4 state checks and at most
 * one read per slot, which the cost table of the Architecture Doc assumes.
 */
final readonly class FetchWindow
{
    public const int RETRY_INTERVAL_SECONDS = 3600;

    /**
     * State checks per slot: the one at the slot, then one per hour up to 3 hours later.
     */
    public const int MAX_ATTEMPTS = 4;

    /**
     * Whether a slot can still start at $now: it has begun and its window has not closed.
     */
    public static function isOpen(CarbonImmutable $slot, CarbonImmutable $now): bool
    {
        $closesAt = $slot->addSeconds((self::MAX_ATTEMPTS - 1) * self::RETRY_INTERVAL_SECONDS);

        return $slot->lessThanOrEqualTo($now) && $now->lessThan($closesAt);
    }

    /**
     * Seconds to wait before the check after $attempt (0 for the first), so that the checks stay on the hours
     * after the slot (slot, slot + 1 h, slot + 2 h, slot + 3 h) however late the earlier ones ran.
     */
    public static function retryDelaySeconds(CarbonImmutable $slot, int $attempt, CarbonImmutable $now): int
    {
        $next = $slot->addSeconds(($attempt + 1) * self::RETRY_INTERVAL_SECONDS);

        return max(0, $next->getTimestamp() - $now->getTimestamp());
    }

    /**
     * Whether the check that just found the car not online is the last one of the window.
     */
    public static function isLastAttempt(int $attempt): bool
    {
        return $attempt + 1 >= self::MAX_ATTEMPTS;
    }
}
