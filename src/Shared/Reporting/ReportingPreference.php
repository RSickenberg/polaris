<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

/**
 * What a user chose about error reports.
 */
final readonly class ReportingPreference
{
    public function __construct(
        /** Reports of this user's activity may be sent. */
        public bool $enabled,
        /** Vehicle data (the answer of the car) may be part of a report. Only meaningful when enabled. */
        public bool $details,
    ) {
    }
}
