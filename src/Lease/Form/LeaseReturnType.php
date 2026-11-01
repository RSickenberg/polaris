<?php

declare(strict_types=1);

namespace Polaris\Lease\Form;

use Polaris\Lease\Form\Model\LeaseReturnData;
use Polaris\Shared\Domain\TimeZone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LeaseReturnData>
 */
final class LeaseReturnType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // A calendar date, like the lease dates: no time zone is involved, the service turns it into an instant.
        $builder
            ->add('returnedOn', DateType::class, [
                'label' => 'form.returned_on.label',
                'help' => 'form.returned_on.help',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => TimeZone::UTC,
                'view_timezone' => TimeZone::UTC,
            ])
            ->add('save', SubmitType::class, ['label' => 'form.return_submit']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LeaseReturnData::class, 'translation_domain' => 'lease']);
    }
}
