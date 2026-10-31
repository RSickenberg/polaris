<?php

declare(strict_types=1);

namespace Polaris\Tests\Support;

use Polaris\Alert\Service\Dto\RiskLevelChange;
use Polaris\Extension\RiskAlertNotifier;

/**
 * A risk alert notifier that keeps what it was told, and answers with a channel (or none).
 */
final class RecordingRiskAlertNotifier implements RiskAlertNotifier
{
    /** @var list<RiskLevelChange> */
    public array $changes = [];

    public function __construct(private readonly ?string $channel = 'test')
    {
    }

    public function notify(RiskLevelChange $change): ?string
    {
        $this->changes[] = $change;

        return $this->channel;
    }
}
