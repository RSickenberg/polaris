<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

final class KeyAlreadyExists extends \RuntimeException
{
    public static function forApp(): self
    {
        return new self('A key pair already exists. Replacing it means registering the app with Tesla again; pass force to replace it.');
    }
}
