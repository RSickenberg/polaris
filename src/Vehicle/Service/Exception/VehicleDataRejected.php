<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service\Exception;

use Polaris\Vehicle\Service\Enum\VehicleDataRejection;

/**
 * A file that cannot be imported: it is not an export of Polaris, it is of a version this one does not know, or a
 * value in it is invalid. Nothing was imported. The message says what is wrong, in English, for the console and the
 * logs; the page shows {@see $reason} (its value is a key of the `vehicle` translation domain) with {@see $detail}.
 */
final class VehicleDataRejected extends \RuntimeException
{
    /**
     * @param string $detail what is wrong, in English (for example the path of a wrong value); empty when the reason
     *                       says it all
     */
    public function __construct(
        public readonly VehicleDataRejection $reason,
        public readonly string $detail = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct('' === $detail ? $reason->value : $detail, 0, $previous);
    }
}
