<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Enum;

enum RegistrationStatus: string
{
    case Registered = 'registered';
    case Failed = 'failed';
    case Refused = 'refused';
}
