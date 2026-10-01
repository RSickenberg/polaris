<?php

declare(strict_types=1);

namespace Polaris\Shared\Domain;

/**
 * Time zone names modules need to refer to (ADR 0003).
 */
final class TimeZone
{
    /**
     * The zone every instant is stored, computed and exchanged in. Polaris\Kernel forces the
     * PHP default to the same name; it cannot use this class, as no module may be a dependency
     * of the Kernel, so a test keeps both equal.
     */
    public const string UTC = 'UTC';

    private function __construct()
    {
    }
}
