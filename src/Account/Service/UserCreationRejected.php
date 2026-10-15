<?php

declare(strict_types=1);

namespace Polaris\Account\Service;

/**
 * The user cannot be created.
 */
final class UserCreationRejected extends \RuntimeException
{
    public static function alreadyExists(): self
    {
        return new self('A user already exists: a self-hosted Polaris has a single user.');
    }
}
