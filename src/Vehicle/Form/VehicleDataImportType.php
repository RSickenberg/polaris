<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Form;

use Polaris\Vehicle\Form\Model\VehicleDataImportFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The file of an export to import into a vehicle.
 *
 * @extends AbstractType<VehicleDataImportFormData>
 */
final class VehicleDataImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'form.data.file.label',
                'attr' => ['accept' => '.json,application/json'],
            ])
            ->add('save', SubmitType::class, [
                'label' => 'form.data.submit',
                'attr' => ['data-icon' => 'lucide:upload'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VehicleDataImportFormData::class,
            'translation_domain' => 'vehicle',
        ]);
    }
}
