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

    public function describe(): string
    {
        return match ($this) {
            self::Token => 'Getting a partner token',
            self::Register => 'Registering the domain',
            self::VerifyKey => 'Reading back the registered public key',
            self::CodeExchange => 'Exchanging the authorization code',
            self::Refresh => 'Refreshing the access token',
            self::UserRegion => 'Reading the region of the Tesla account',
        };
    }
}
