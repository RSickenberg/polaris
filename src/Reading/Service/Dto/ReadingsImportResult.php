<?php

declare(strict_types=1);

namespace Polaris\Reading\Service\Dto;

/**
 * What an import of readings did. Nothing is ever replaced or deleted.
 */
final readonly class ReadingsImportResult
{
    public function __construct(
        public int $added,
        /** Readings already there (the same instant). */
        public int $alreadyThere,
        /** Readings in the future, or that would break the order of the stored readings. */
        public int $rejected,
    ) {
    }
}
