<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Exception;

use Polaris\Tesla\Domain\FleetRegion;

/**
 * The Tesla app settings are refused. The message is English, for the console (`polaris:tesla:configure`): the
 * settings page validates the same rules with its form and shows a generic error if the service still refuses.
 */
final class TeslaSettingsRejected extends \RuntimeException
{
    public static function keyPairMissing(): self
    {
        return new self('There is no Tesla key pair yet. Generate it first with polaris:tesla:keygen.');
    }

    public static function missing(string $what): self
    {
        return new self(\sprintf('The %s is required.', $what));
    }

    public static function wakeCapOutOfRange(int $max): self
    {
        return new self(\sprintf('The daily wake cap must be between 0 and %d.', $max));
    }

    public static function regionNotSupported(FleetRegion $region): self
    {
        return new self(\sprintf(
            'The region "%s" is not supported: China needs a separate Tesla account and developer app, and Polaris '
                . 'does not handle it yet.',
            $region->value,
        ));
    }
}
