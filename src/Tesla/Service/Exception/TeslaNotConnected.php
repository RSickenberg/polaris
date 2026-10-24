<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Exception;

/**
 * There is no usable Tesla connection: never made, or Tesla refused the refresh token and the user
 * must connect again.
 */
final class TeslaNotConnected extends \RuntimeException
{
    public static function never(): self
    {
        return new self('Tesla is not connected.');
    }

    public static function expired(): self
    {
        return new self('The Tesla connection has expired: connect again.');
    }
}
