<?php

declare(strict_types=1);

namespace Polaris\Reading\Form;

use Polaris\Reading\Form\Model\OdometerReadingData;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Shared\Domain\TimeZone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<OdometerReadingData>
 */
final class OdometerReadingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('readAt', DateTimeType::class, [
                'label' => 'form.read_at.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                // The user's time zone arrives with #21; until then the date is entered in UTC.
                'model_timezone' => TimeZone::UTC,
                'view_timezone' => TimeZone::UTC,
            ])
            ->add('odometer', IntegerType::class, ['label' => 'form.odometer.label'])
            ->add('distanceUnit', EnumType::class, [
                'label' => 'form.unit.label',
                'class' => DistanceUnit::class,
                'choice_label' => static fn (DistanceUnit $unit): string => 'unit.' . $unit->value,
                'choice_translation_domain' => 'shared',
            ])
            ->add('save', SubmitType::class, ['label' => 'form.submit']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OdometerReadingData::class, 'translation_domain' => 'reading']);
    }
}
