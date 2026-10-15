<?php

declare(strict_types=1);

namespace Polaris\Extension;

enum PublicKeyHostingMode: string
{
    case Polaris = 'polaris';
    case External = 'external';
}
