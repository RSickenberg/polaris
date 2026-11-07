<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

/**
 * A message that acts for one user (the daily jobs of an owner's midnight). The worker tells the error reporting whose
 * data it is before the handler runs, so that a report is held back when the user opted out.
 */
interface ReportsForUser
{
    /**
     * @return string the ULID (RFC 4122 form) of the user
     */
    public function reportingUserId(): string;
}
