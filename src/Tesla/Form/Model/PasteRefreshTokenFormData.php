<?php

declare(strict_types=1);

namespace Polaris\Tesla\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A refresh token obtained outside Polaris, pasted by the user.
 *
 * Not readonly: the Form component writes the submitted values into it.
 */
final class PasteRefreshTokenFormData
{
    #[Assert\NotBlank(message: 'tesla.refresh_token.required')]
    #[Assert\Length(max: 4096)]
    public ?string $refreshToken = null;
}
