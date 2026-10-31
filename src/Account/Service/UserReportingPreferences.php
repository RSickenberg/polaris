<?php

declare(strict_types=1);

namespace Polaris\Account\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Polaris\Shared\Reporting\ReportingPreference;
use Polaris\Shared\Reporting\ReportingPreferences;
use Symfony\Component\Uid\Ulid;

/**
 * Reads the error reporting choice of a user straight from the database, at the moment a report is about to be sent: an
 * opt-out counts from the next report, and a closed entity manager (after a failed flush) cannot hold it back.
 */
final readonly class UserReportingPreferences implements ReportingPreferences
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @throws DbalException
     */
    public function of(string $userId): ?ReportingPreference
    {
        try {
            $id = Ulid::fromString($userId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT error_reporting, error_reporting_details FROM app_user WHERE id = :id',
            ['id' => $id],
            ['id' => 'ulid'],
        );

        if (false === $row) {
            return null;
        }

        $enabled = (bool) $row['error_reporting'];

        return new ReportingPreference($enabled, $enabled && (bool) $row['error_reporting_details']);
    }
}
