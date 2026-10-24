<?php

declare(strict_types=1);

namespace Polaris\Tesla\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A form with only a button and the CSRF token, for an action that takes no input (start the
 * authorization, disconnect). The label is a key of the `tesla` domain.
 *
 * @extends AbstractType<null>
 */
final class ActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('submit', SubmitType::class, ['label' => $options['submit_label']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('submit_label')
            ->setAllowedTypes('submit_label', 'string')
            ->setDefaults(['translation_domain' => 'tesla']);
    }
}
