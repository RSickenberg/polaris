<?php

declare(strict_types=1);

namespace Polaris\Alert\Entity;

enum AlertType: string
{
    /** The level was recorded as the known one, nobody was told. */
    case Baseline = 'baseline';

    /** The level got worse and the extension was told. */
    case RiskIncreased = 'risk_increased';
}
