<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Polaris\Alert\Service\Dto\RiskLevelChange;

/**
 * Told when the risk level of a vehicle gets worse (Ok to Watch or Over, Watch to Over).
 *
 * Called once per change: the Alert module compares each assessment with the last level it logged, so the same
 * level never reaches it twice. A level that goes down, or a first assessment, is logged without a call.
 *
 * The self-hosted default does nothing. The hosted layer replaces it (service decoration or a new alias).
 */
interface RiskAlertNotifier
{
    /**
     * @return string|null the channel the alert went out on, kept in the alert log; null when nothing was sent
     */
    public function notify(RiskLevelChange $change): ?string;
}
