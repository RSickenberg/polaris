<?php

declare(strict_types=1);

namespace Polaris\Account\Form\Model;

use Polaris\Account\Entity\User;

/**
 * The error reporting part of the settings: what the user lets Sentry receive, when the owner of the instance turned it on.
 */
final class ErrorReportingData
{
    public bool $enabled = false;

    public bool $details = false;

    public static function fromUser(User $user): self
    {
        $data = new self();
        $data->enabled = $user->sendsErrorReports();
        $data->details = $user->sendsErrorReportDetails();

        return $data;
    }
}
