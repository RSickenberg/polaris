<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Enum;

/**
 * The connectivity state Tesla reports for a vehicle. The values `asleep` and `online` come from Tesla's
 * wording; `offline` is confirmed by a real answer, the other two are not documented.
 */
enum VehicleState: string
{
    case Online = 'online';
    case Asleep = 'asleep';
    case Offline = 'offline';
}
