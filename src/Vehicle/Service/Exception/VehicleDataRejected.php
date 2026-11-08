<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service\Exception;

/**
 * A file that cannot be imported: it is not an export of Polaris, it is of a version this one does not know, or a value
 * in it is invalid. Nothing was imported. The message says what is wrong, in English, for the console and the logs; the
 * page shows {@see $reason} (a key of the `vehicle` translation domain) with the detail.
 */
final class VehicleDataRejected extends \RuntimeException
{
    public const string NOT_JSON = 'data.rejected.not_json';
    public const string NOT_AN_EXPORT = 'data.rejected.not_an_export';
    public const string UNKNOWN_VERSION = 'data.rejected.unknown_version';
    public const string INVALID = 'data.rejected.invalid';

    public function __construct(
        public readonly string $reason,
        string $detail = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct('' === $detail ? $reason : $detail, 0, $previous);
    }
}
