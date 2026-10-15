<?php

declare(strict_types=1);

namespace Polaris\Account\Form;

use Polaris\Account\Service\NewUser;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A new password, typed twice.
 *
 * @extends AbstractType<string>
 */
final class NewPasswordType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'type' => PasswordType::class,
            'invalid_message' => 'account.password.mismatch',
            'first_options' => [
                'label' => 'form.new_password.label',
                'help' => 'form.new_password.help',
                'help_translation_parameters' => ['%min%' => NewUser::PASSWORD_MIN_LENGTH],
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'second_options' => [
                'label' => 'form.new_password_confirm.label',
                'attr' => ['autocomplete' => 'new-password'],
            ],
        ]);
    }

    public function getParent(): string
    {
        return RepeatedType::class;
    }
}
