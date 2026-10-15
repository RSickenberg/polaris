<?php

declare(strict_types=1);

namespace Polaris\Account\Form;

use Polaris\Account\Form\Model\PasswordChangeData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PasswordChangeData>
 */
final class PasswordChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'form.current_password.label',
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('newPassword', NewPasswordType::class)
            ->add('save', SubmitType::class, ['label' => 'form.password_save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PasswordChangeData::class, 'translation_domain' => 'account']);
    }
}
