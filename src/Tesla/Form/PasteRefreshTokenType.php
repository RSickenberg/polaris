<?php

declare(strict_types=1);

namespace Polaris\Tesla\Form;

use Polaris\Tesla\Form\Model\PasteRefreshTokenFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PasteRefreshTokenFormData>
 */
final class PasteRefreshTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('refreshToken', PasswordType::class, [
                'label' => 'form.refresh_token.label',
                'help' => 'form.refresh_token.help',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('save', SubmitType::class, ['label' => 'form.refresh_token.submit']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PasteRefreshTokenFormData::class,
            'translation_domain' => 'tesla',
        ]);
    }
}
