<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Exception;

/**
 * The car cannot be woken because the `vehicle_cmds` scope is missing or was revoked. Tesla's 401 and 403 on
 * wake_up end up here.
 */
final class WakeScopeMissing extends \RuntimeException
{
    public static function notGranted(?\Throwable $previous = null): self
    {
        return new self('The vehicle_cmds scope is not granted: connect to Tesla again to allow waking.', 0, $previous);
    }
}
