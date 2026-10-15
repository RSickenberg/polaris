<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service;

/**
 * What a user may do with a vehicle, for `#[IsGranted]` and the voter of the Vehicle module.
 * Only the owner may do anything with it.
 */
enum VehiclePermission: string
{
    case Manage = 'vehicle_manage';
}
