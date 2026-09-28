<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

/**
 * How the end date of a lease contract reads, as the user chose it to match their contract.
 */
enum EndDateConvention: string
{
    /**
     * The end date is the return date: the lease runs up to, not including, that day.
     * A 36-month lease from 2026-01-15 ends on 2029-01-15.
     */
    case Exclusive = 'exclusive';

    /**
     * The end date is the last day of the lease.
     * A 36-month lease from 2026-01-15 ends on 2029-01-14.
     */
    case Inclusive = 'inclusive';
}
