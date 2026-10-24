<?php

declare(strict_types=1);

namespace Polaris\Shared\Service\Exception;

/**
 * A stored secret cannot be decrypted: wrong key, tampered or unknown format.
 */
final class DecryptionFailed extends \RuntimeException
{
}
