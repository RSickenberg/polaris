<?php

declare(strict_types=1);

namespace Polaris\Lease\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The day the user gave the car back, a calendar date. {@see \Polaris\Lease\Service\LeaseTermsService::markReturned()}
 * checks it against the lease start and today.
 *
 * Not readonly: the Form component writes the submitted value into it.
 */
final class LeaseReturnData
{
    #[Assert\NotNull]
    public ?\DateTimeImmutable $returnedOn = null;
}
