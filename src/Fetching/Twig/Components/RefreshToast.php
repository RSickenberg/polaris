<?php

declare(strict_types=1);

namespace Polaris\Fetching\Twig\Components;

use Carbon\CarbonImmutable;
use Polaris\Shared\Service\Enum\ToastVariant;

/**
 * The toast {@see OdometerRefreshButton} announces. The template translates the key (domain `fetching`) and, with
 * {@see self::$at}, passes it as the `time` and `zone` of the message, in the user's time zone.
 */
final readonly class RefreshToast
{
    /**
     * @param string               $id identifies the toast: one per click and result, so that a poll that shows
     *                                 the same result does not announce it again
     * @param CarbonImmutable|null $at the instant the message names (the next allowed refresh or wake)
     */
    public function __construct(
        public string $id,
        public ToastVariant $variant,
        public string $key,
        public ?CarbonImmutable $at = null,
    ) {
    }
}
