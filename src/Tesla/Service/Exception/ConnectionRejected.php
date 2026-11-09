<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Exception;

use Polaris\Tesla\Service\Enum\ConnectionRejection;

/**
 * Connecting Polaris to a Tesla account was refused; nothing was stored. The message is an English fallback;
 * callers translate {@see $reason}.
 */
final class ConnectionRejected extends \RuntimeException
{
    public function __construct(
        public readonly ConnectionRejection $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('The connection to Tesla was refused: %s.', $reason->name), 0, $previous);
    }
}
