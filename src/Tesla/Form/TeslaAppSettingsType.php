<?php

declare(strict_types=1);

namespace Polaris\Tesla\Form;

use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Form\Model\TeslaAppSettingsFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<TeslaAppSettingsFormData>
 */
final class TeslaAppSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $secretStored = $options['secret_stored'];

        $builder
            ->add('clientId', TextType::class, [
                'label' => 'form.client_id.label',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('clientSecret', PasswordType::class, [
                'label' => 'form.client_secret.label',
                'help' => $secretStored ? 'form.client_secret.help_stored' : 'form.client_secret.help_new',
                'required' => !$secretStored,
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('domain', TextType::class, [
                'label' => 'form.domain.label',
                'help' => 'form.domain.help',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'inputmode' => 'url'],
            ])
            ->add('regions', EnumType::class, [
                'label' => 'form.regions.label',
                'help' => 'form.regions.help',
                'class' => FleetRegion::class,
                'multiple' => true,
                'expanded' => true,
                'choice_label' => static fn (FleetRegion $region): string => 'region.' . $region->value,
                // China is listed so that the choice is visible, but it cannot be enabled.
                'choice_attr' => static fn (FleetRegion $region): array => $region->isSupported() ? [] : ['disabled' => 'disabled'],
            ])
            ->add('save', SubmitType::class, ['label' => 'form.submit']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => TeslaAppSettingsFormData::class,
                'translation_domain' => 'tesla',
                'secret_stored' => false,
            ])
            ->setAllowedTypes('secret_stored', 'bool');
    }
}
