<?php

declare(strict_types=1);

namespace Polaris\Account\Form;

use Polaris\Account\Form\Model\ForgotPasswordData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ForgotPasswordData>
 */
final class ForgotPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['label' => 'form.email.label', 'attr' => ['autocomplete' => 'username', 'autofocus' => true]])
            ->add('send', SubmitType::class, ['label' => 'form.send_link']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ForgotPasswordData::class, 'translation_domain' => 'account']);
    }
}
