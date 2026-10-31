<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

/**
 * Where the choice of a user about error reports is read. Implemented by the Account module.
 */
interface ReportingPreferences
{
    /**
     * @param string $userId the ULID (RFC 4122 form) of the user
     *
     * @return ReportingPreference|null null when the user does not exist
     */
    public function of(string $userId): ?ReportingPreference;
}
