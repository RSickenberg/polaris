<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service\Enum;

/**
 * What an import did with the lease of the file. The values are the keys the page's message selects on.
 */
enum LeaseImportOutcome: string
{
    /** The file had a lease and the vehicle had none: it was created. */
    case Created = 'created';

    /** The file had a lease but the vehicle already has one: the existing one was kept. */
    case Kept = 'kept';

    /** The file has no lease. */
    case None = 'none';
}
