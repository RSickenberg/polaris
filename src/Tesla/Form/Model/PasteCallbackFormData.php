<?php

declare(strict_types=1);

namespace Polaris\Tesla\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The full callback URL that the browser could not open, pasted by the user.
 *
 * Not readonly: the Form component writes the submitted values into it.
 */
final class PasteCallbackFormData
{
    #[Assert\NotBlank(message: 'tesla.callback_url.required')]
    #[Assert\Length(max: 4096)]
    public ?string $url = null;
}
