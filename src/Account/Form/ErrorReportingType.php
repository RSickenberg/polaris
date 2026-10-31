<?php

declare(strict_types=1);

namespace Polaris\Account\Form;

use Polaris\Account\Form\Model\ErrorReportingData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ErrorReportingData>
 */
final class ErrorReportingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'form.error_reporting.label',
                'help' => 'form.error_reporting.help',
                'required' => false,
            ])
            ->add('details', CheckboxType::class, [
                'label' => 'form.error_reporting_details.label',
                'help' => 'form.error_reporting_details.help',
                'required' => false,
            ])
            ->add('save', SubmitType::class, ['label' => 'form.reporting_save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ErrorReportingData::class, 'translation_domain' => 'account']);
    }
}
