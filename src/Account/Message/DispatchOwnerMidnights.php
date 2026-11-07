<?php

declare(strict_types=1);

namespace Polaris\Account\Message;

/**
 * The every-minute tick that looks for owners whose local midnight has just passed: see
 * {@see \Polaris\Account\Service\OwnerMidnightDispatcher}.
 */
final readonly class DispatchOwnerMidnights
{
}
