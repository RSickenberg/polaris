<?php

declare(strict_types=1);

namespace Polaris\Lease\Form;

use Polaris\Lease\Form\Model\DrivingHoursData;
use Polaris\Shared\Domain\TimeZone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<DrivingHoursData>
 */
final class DrivingHoursType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // A wall-clock time of the owner's time zone: no conversion, the domain rebuilds each day from it.
        $time = [
            'widget' => 'single_text',
            'input' => 'string',
            'input_format' => 'H:i',
            'required' => false,
            'model_timezone' => TimeZone::UTC,
            'view_timezone' => TimeZone::UTC,
        ];

        $builder
            ->add('from', TimeType::class, $time + [
                'label' => 'form.hours_from.label',
                'help' => 'form.hours_from.help',
            ])
            ->add('until', TimeType::class, $time + [
                'label' => 'form.hours_until.label',
                'help' => 'form.hours_until.help',
            ])
            ->add('save', SubmitType::class, ['label' => 'form.hours_submit']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DrivingHoursData::class, 'translation_domain' => 'lease']);
    }
}
