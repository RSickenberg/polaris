<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Form;

use Polaris\Vehicle\Form\Model\VehicleImportFormData;
use Polaris\Vehicle\Service\Dto\ImportCandidate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One box per vehicle of the Tesla account that Polaris does not have yet. The choices are the listing Tesla
 * just returned, so a posted VIN that is not in it is refused by the form.
 *
 * @extends AbstractType<VehicleImportFormData>
 */
final class VehicleImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<ImportCandidate> $candidates */
        $candidates = $options['candidates'];
        $choices = [];

        foreach ($candidates as $candidate) {
            $choices[\sprintf('%s (%s)', $candidate->name(), $candidate->summary->vin->value())] = $candidate->summary->vin->value();
        }

        $builder
            ->add('vins', ChoiceType::class, [
                'label' => 'form.vehicles.label',
                'choices' => $choices,
                'multiple' => true,
                'expanded' => true,
                'choice_translation_domain' => false,
            ])
            ->add('save', SubmitType::class, ['label' => 'form.submit']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('candidates')
            ->setAllowedTypes('candidates', 'array')
            ->setDefaults([
                'data_class' => VehicleImportFormData::class,
                'translation_domain' => 'vehicle',
            ]);
    }
}
