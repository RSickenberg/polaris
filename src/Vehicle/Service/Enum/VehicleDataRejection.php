<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service\Enum;

/**
 * Why a data file cannot be imported. The value is the key of the message in the `vehicle` translation domain.
 */
enum VehicleDataRejection: string
{
    case NotJson = 'data.rejected.not_json';
    case NotAnExport = 'data.rejected.not_an_export';
    case UnknownVersion = 'data.rejected.unknown_version';

    /**
     * A value of the file is wrong; the message names it (`%detail%`).
     */
    case Invalid = 'data.rejected.invalid';
}
