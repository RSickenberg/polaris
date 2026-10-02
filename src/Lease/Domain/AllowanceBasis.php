<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

/**
 * What the allowance entered by the user stands for: the contract may state a yearly
 * allowance or a total for the whole term. Either way the allowance is stored as a total.
 */
enum AllowanceBasis: string
{
    case PerYear = 'per_year';
    case Total = 'total';
}
