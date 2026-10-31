<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

/**
 * What the owner of the instance chose with POLARIS_ERROR_REPORTING. Anything unknown is Off.
 */
enum ErrorReportingMode: string
{
    case Off = 'off';
    case Maintainer = 'maintainer';
    case Custom = 'custom';

    public static function fromSwitch(string $value): self
    {
        return self::tryFrom(mb_strtolower(trim($value))) ?? self::Off;
    }
}
