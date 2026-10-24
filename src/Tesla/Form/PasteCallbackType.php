<?php

declare(strict_types=1);

namespace Polaris\Tesla\Form;

use Polaris\Tesla\Form\Model\PasteCallbackFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PasteCallbackFormData>
 */
final class PasteCallbackType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('url', TextType::class, [
                'label' => 'form.callback_url.label',
                'help' => 'form.callback_url.help',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'inputmode' => 'url'],
            ])
            ->add('save', SubmitType::class, ['label' => 'form.callback_url.submit']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PasteCallbackFormData::class,
            'translation_domain' => 'tesla',
        ]);
    }
}
