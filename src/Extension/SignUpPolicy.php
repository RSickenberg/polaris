<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Polaris\Account\Service\Dto\NewUser;
use Polaris\Account\Service\Exception\UserCreationRejected;

/**
 * Decides whether a user may be created.
 *
 * The self-hosted default allows a single user. The hosted layer replaces it (service
 * decoration or a new alias) to apply its own policy: plans, invitations, quotas.
 */
interface SignUpPolicy
{
    /**
     * @throws UserCreationRejected when the user may not be created
     */
    public function assertCanSignUp(NewUser $newUser): void;
}
