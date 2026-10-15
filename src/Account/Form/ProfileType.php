<?php

declare(strict_types=1);

namespace Polaris\Account\Form;

use Polaris\Account\Form\Model\ProfileData;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ProfileData>
 */
final class ProfileType extends AbstractType
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'form.email.label',
                'attr' => ['autocomplete' => 'username'],
            ])
            ->add('locale', ChoiceType::class, [
                'label' => 'form.locale.label',
                'choices' => array_combine($this->enabledLocales, $this->enabledLocales),
                'choice_label' => static fn (string $locale): string => 'locale.' . $locale,
                'choice_translation_domain' => 'shared',
            ])
            ->add('timeZone', TimezoneType::class, [
                'label' => 'form.time_zone.label',
                'help' => 'form.time_zone.help',
                'choice_translation_domain' => false,
            ])
            ->add('save', SubmitType::class, ['label' => 'form.save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProfileData::class, 'translation_domain' => 'account']);
    }
}
