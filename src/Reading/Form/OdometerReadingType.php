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
                'label' => 'Read at (UTC)',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                // The user's time zone arrives with #21; until then the date is entered in UTC.
                'model_timezone' => TimeZone::UTC,
                'view_timezone' => TimeZone::UTC,
            ])
            ->add('odometer', IntegerType::class, ['label' => 'Odometer'])
            ->add('distanceUnit', EnumType::class, [
                'label' => 'Unit',
                'class' => DistanceUnit::class,
                'choice_label' => static fn (DistanceUnit $unit): string => $unit->value,
            ])
            ->add('save', SubmitType::class, ['label' => 'Add reading']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OdometerReadingData::class]);
    }
}
