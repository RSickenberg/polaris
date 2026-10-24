<?php

declare(strict_types=1);

namespace Polaris\Tesla\Form;

use Polaris\Tesla\Form\Model\ConsentFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ConsentFormData>
 */
final class ConsentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('agree', CheckboxType::class, [
                'label' => 'form.consent.label',
                'required' => false,
            ])
            ->add('save', SubmitType::class, ['label' => 'form.consent.submit']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ConsentFormData::class,
            'translation_domain' => 'tesla',
        ]);
    }
}
