<?php

declare(strict_types=1);

namespace Polaris\Fetching\Domain;

use Carbon\CarbonImmutable;

/**
 * The timing rules of a wake: the state of a woken car is polled every 10 s for up to 90 s, and a wake counts
 * against the daily cap for 24 hours after wake_up was sent (a rolling window, not a calendar day).
 */
final readonly class WakeWindow
{
    public const int POLL_INTERVAL_SECONDS = 10;
    public const int TIMEOUT_SECONDS = 90;

    /**
     * How long a wake counts against the cap.
     */
    public const int CAP_WINDOW_SECONDS = 86400;

    /**
     * How long after a refresh found the car asleep the user can still confirm a wake for it.
     */
    public const int CONFIRM_SECONDS = 600;

    /**
     * A wake is pending from wake_up until the deadline plus this margin (the last poll may run a little late),
     * and no other fetch of the vehicle runs meanwhile.
     */
    public const int PENDING_GRACE_SECONDS = 30;

    public static function deadline(CarbonImmutable $wokeAt): CarbonImmutable
    {
        return $wokeAt->addSeconds(self::TIMEOUT_SECONDS);
    }

    public static function isOver(int $deadline, CarbonImmutable $now): bool
    {
        return $now->getTimestamp() >= $deadline;
    }

    /**
     * The instant from which an earlier wake no longer counts.
     */
    public static function capStart(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subSeconds(self::CAP_WINDOW_SECONDS);
    }

    /**
     * The instant before which a wake sent then is not pending any more.
     */
    public static function pendingSince(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subSeconds(self::TIMEOUT_SECONDS + self::PENDING_GRACE_SECONDS);
    }

    /**
     * @param list<CarbonImmutable> $wokenAt the wakes of the last 24 hours, most recent first
     */
    public static function capStatus(array $wokenAt, int $cap): WakeCapStatus
    {
        $used = \count($wokenAt);

        if ($cap <= 0 || $used < $cap) {
            return new WakeCapStatus($cap, $used, null);
        }

        // The wake that frees the next slot is the cap-th most recent one: 24 hours after it, only cap - 1 remain.
        return new WakeCapStatus($cap, $used, $wokenAt[$cap - 1]->addSeconds(self::CAP_WINDOW_SECONDS));
    }
}
