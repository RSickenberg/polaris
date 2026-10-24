<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Security;

use Polaris\Account\Entity\User;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\Enum\VehiclePermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A vehicle belongs to its owner: nobody else sees or changes it.
 *
 * @extends Voter<string, Vehicle>
 */
final class VehicleVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return VehiclePermission::Manage->value === $attribute && $subject instanceof Vehicle;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $subject->getOwner()->getId()->equals($user->getId());
    }
}
