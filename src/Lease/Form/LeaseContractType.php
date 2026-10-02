<?php

declare(strict_types=1);

namespace Polaris\Lease\Form;

use Polaris\Lease\Domain\AllowanceBasis;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Shared\Domain\TimeZone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LeaseContractData>
 */
final class LeaseContractType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('startDate', DateType::class, self::dateOptions('form.start_date.label'))
            ->add('endDate', DateType::class, self::dateOptions('form.end_date.label'))
            ->add('endDateConvention', EnumType::class, [
                'label' => 'form.end_date_convention.label',
                'class' => EndDateConvention::class,
                'choice_label' => static fn (EndDateConvention $convention): string => 'end_date_convention.'.$convention->value,
                'expanded' => true,
            ])
            ->add('distanceUnit', EnumType::class, [
                'label' => 'form.unit.label',
                'class' => DistanceUnit::class,
                'choice_label' => static fn (DistanceUnit $unit): string => 'unit.'.$unit->value,
                'choice_translation_domain' => 'shared',
            ])
            ->add('allowanceBasis', EnumType::class, [
                'label' => 'form.allowance_basis.label',
                'class' => AllowanceBasis::class,
                'choice_label' => static fn (AllowanceBasis $basis): string => 'allowance_basis.'.$basis->value,
            ])
            ->add('allowance', NumberType::class, ['label' => 'form.allowance.label', 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('startOdometer', NumberType::class, ['label' => 'form.start_odometer.label', 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('excessCostPerKm', TextType::class, ['label' => 'form.excess_cost.label', 'attr' => ['inputmode' => 'decimal']])
            ->add('currency', EnumType::class, [
                'label' => 'form.currency.label',
                'class' => Currency::class,
                'choice_label' => static fn (Currency $currency): string => $currency->value,
                'choice_translation_domain' => false,
            ])
            ->add('tolerancePercent', NumberType::class, ['label' => 'form.tolerance.label', 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('save', SubmitType::class, ['label' => 'form.submit']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LeaseContractData::class, 'translation_domain' => 'lease']);
    }

    /**
     * Lease dates are calendar dates: no time zone is involved, the form keeps year, month and day.
     *
     * @return array<string, mixed>
     */
    private static function dateOptions(string $label): array
    {
        return [
            'label' => $label,
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'model_timezone' => TimeZone::UTC,
            'view_timezone' => TimeZone::UTC,
        ];
    }
}
