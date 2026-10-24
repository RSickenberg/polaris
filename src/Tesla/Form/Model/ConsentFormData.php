<?php

declare(strict_types=1);

namespace Polaris\Tesla\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The opt-in consent: the box is never ticked by default and must be ticked to go on.
 *
 * Not readonly: the Form component writes the submitted values into it.
 */
final class ConsentFormData
{
    #[Assert\IsTrue(message: 'tesla.consent.required')]
    public bool $agree = false;
}
