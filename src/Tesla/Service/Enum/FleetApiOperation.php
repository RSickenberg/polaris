<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Enum;

enum FleetApiOperation: string
{
    case Token = 'token';
    case Register = 'register';
    case VerifyKey = 'verify_key';
    case CodeExchange = 'code_exchange';
    case Refresh = 'refresh';
    case UserRegion = 'user_region';
    case ListVehicles = 'list_vehicles';
    case VehicleState = 'vehicle_state';
    case VehicleData = 'vehicle_data';
    case WakeUp = 'wake_up';

    public function describe(): string
    {
        return match ($this) {
            self::Token => 'Getting a partner token',
            self::Register => 'Registering the domain',
            self::VerifyKey => 'Reading back the registered public key',
            self::CodeExchange => 'Exchanging the authorization code',
            self::Refresh => 'Refreshing the access token',
            self::UserRegion => 'Reading the region of the Tesla account',
            self::ListVehicles => 'Listing the vehicles',
            self::VehicleState => 'Reading the state of the vehicle',
            self::VehicleData => 'Reading the vehicle data',
            self::WakeUp => 'Waking the vehicle',
        };
    }
}
