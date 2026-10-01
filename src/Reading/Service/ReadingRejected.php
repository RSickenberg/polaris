<?php

declare(strict_types=1);

namespace Polaris\Reading\Service;

use Polaris\Reading\Domain\ReadingRejection;

/**
 * A reading that is valid on its own but cannot be stored, given the readings already there.
 * The message is an English fallback; callers translate {@see $reason} (its value is the
 * translation key) with {@see $parameters}.
 */
final class ReadingRejected extends \DomainException
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public readonly ReadingRejection $reason,
        public readonly array $parameters = [],
    ) {
        parent::__construct(\sprintf('The reading was rejected: %s.', $reason->name));
    }
}
