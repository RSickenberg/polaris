<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Enum;

enum PublicKeyCheckStatus: string
{
    case Match = 'match';
    case Mismatch = 'mismatch';
    case NotFound = 'not_found';
    case Redirected = 'redirected';
    case Unreachable = 'unreachable';
    case NotConfigured = 'not_configured';
}
