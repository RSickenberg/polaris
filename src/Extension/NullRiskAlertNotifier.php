<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Polaris\Alert\Service\Dto\RiskLevelChange;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The self-hosted default: no alert is sent.
 */
#[AsAlias(RiskAlertNotifier::class)]
final readonly class NullRiskAlertNotifier implements RiskAlertNotifier
{
    public function notify(RiskLevelChange $change): ?string
    {
        return null;
    }
}
