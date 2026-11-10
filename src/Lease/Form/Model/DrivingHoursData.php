<?php

declare(strict_types=1);

namespace Polaris\Lease\Form\Model;

/**
 * The usual stop time and start time of driving, local "HH:MM". Both empty means "not set".
 * {@see \Polaris\Lease\Service\DrivingHoursService::save()} checks them.
 *
 * Not readonly: the Form component writes the submitted values into it.
 */
final class DrivingHoursData
{
    public ?string $from = null;

    public ?string $until = null;

    /** Whether "Today" is compared with what was expected for the whole day, not prorated. */
    public bool $todayWholeDay = false;
}
