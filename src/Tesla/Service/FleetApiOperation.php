<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

enum FleetApiOperation: string
{
    case Token = 'token';
    case Register = 'register';
    case VerifyKey = 'verify_key';

    public function describe(): string
    {
        return match ($this) {
            self::Token => 'Getting a partner token',
            self::Register => 'Registering the domain',
            self::VerifyKey => 'Reading back the registered public key',
        };
    }
}
