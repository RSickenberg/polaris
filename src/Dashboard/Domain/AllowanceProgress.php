<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Domain;

use Polaris\Shared\Domain\Distance;

/**
 * How much of the whole lease allowance has been driven, and how much was earned so far,
 * as whole percentages for the progress bar.
 *
 * The allowance is linear over the term, so the share earned to date is also the share of
 * the term that has elapsed. Percentages are rounded half up, in integers.
 */
final readonly class AllowanceProgress
{
    private const int MAX_PERCENT = 100;

    private function __construct(
        /** The real share driven: above 100 once the allowance is exceeded. */
        public int $usedPercent,
        /** The share earned to date, at most 100. */
        public int $earnedPercent,
    ) {
    }

    /**
     * @param Distance $total the whole allowance of the lease; with none, both shares are 0
     */
    public static function of(Distance $driven, Distance $allowedToDate, Distance $total): self
    {
        return new self(
            self::percent($driven, $total),
            min(self::MAX_PERCENT, self::percent($allowedToDate, $total)),
        );
    }

    /** The bar fills up to the whole allowance and no further. */
    public function barPercent(): int
    {
        return min(self::MAX_PERCENT, $this->usedPercent);
    }

    public function isOver(): bool
    {
        return $this->usedPercent > self::MAX_PERCENT;
    }

    private static function percent(Distance $part, Distance $total): int
    {
        if (0 === $total->metres()) {
            return 0;
        }

        // part / total x 100 rounded half up: (2 x 100 x part + total) / (2 x total).
        return intdiv(2 * self::MAX_PERCENT * $part->metres() + $total->metres(), 2 * $total->metres());
    }
}
