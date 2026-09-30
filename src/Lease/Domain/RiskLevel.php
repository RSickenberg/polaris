<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

/**
 * How the projected distance at lease end compares with the allowance A.
 *
 * With a tolerance of t (a safety margin below the allowance; 0 by default, since Swiss
 * leases have none): Ok up to A x (1 - t), Watch above that up to A, Over above A.
 * With no tolerance the Watch range is empty.
 */
enum RiskLevel: string
{
    case Ok = 'ok';
    case Watch = 'watch';
    case Over = 'over';
}
