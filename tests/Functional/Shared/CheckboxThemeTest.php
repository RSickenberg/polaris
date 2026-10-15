<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Shared;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

/**
 * The global form theme renders checkboxes, alone or as an expanded choice, with the kit Checkbox.
 */
#[CoversNothing]
final class CheckboxThemeTest extends KernelTestCase
{
    public function testAnExpandedMultipleChoiceIsOneKitCheckboxPerChoice(): void
    {
        $crawler = $this->render(['regions' => ['b']]);

        $boxes = $crawler->filter('[data-slot=checkbox] input[type=checkbox][name="form[regions][]"]');
        self::assertCount(3, $boxes);
        self::assertSame(['form[regions][]'], array_values(array_unique($boxes->each(static fn (Crawler $box): string => (string) $box->attr('name')))));
        self::assertSame(['a', 'b', 'c'], $boxes->each(static fn (Crawler $box): string => (string) $box->attr('value')));
        self::assertCount(1, $crawler->filter('input[name="form[regions][]"][checked]'));
        self::assertSame('b', $crawler->filter('input[name="form[regions][]"][checked]')->attr('value'));
        // Only a choice that is ticked counts: browsers would demand every box if each carried `required`.
        self::assertCount(0, $crawler->filter('input[name="form[regions][]"][required]'));
        self::assertCount(1, $crawler->filter('input[type=checkbox][disabled]'));
        self::assertSame('c', $crawler->filter('input[type=checkbox][disabled]')->attr('value'));
    }

    public function testEveryChoiceLabelIsTiedToItsBoxWithoutAnAsterisk(): void
    {
        $crawler = $this->render([]);

        foreach ($crawler->filter('[data-slot=checkbox] input')->each(static fn (Crawler $box): string => (string) $box->attr('id')) as $id) {
            $label = $crawler->filter(\sprintf('label[for="%s"]', $id));
            self::assertCount(1, $label);
            self::assertCount(0, $label->filter('.text-destructive'));
        }
        self::assertSame('Region A', trim($crawler->filter('label[for=form_regions_0]')->text()));
    }

    public function testASingleCheckboxKeepsItsState(): void
    {
        $crawler = $this->render(['remember' => true]);

        $box = $crawler->filter('[data-slot=checkbox] input[name="form[remember]"]');
        self::assertCount(1, $box);
        self::assertNotNull($box->attr('checked'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data): Crawler
    {
        $form = self::getContainer()->get(FormFactoryInterface::class)
            ->createBuilder(FormType::class, $data, ['csrf_protection' => false])
            ->add('regions', ChoiceType::class, [
                'choices' => ['Region A' => 'a', 'Region B' => 'b', 'Region C' => 'c'],
                'choice_attr' => static fn (string $value): array => 'c' === $value ? ['disabled' => 'disabled'] : [],
                'multiple' => true,
                'expanded' => true,
                'translation_domain' => false,
            ])
            ->add('remember', CheckboxType::class, ['required' => false, 'translation_domain' => false])
            ->getForm();

        $html = self::getContainer()->get(Environment::class)
            ->createTemplate('{{ form_widget(form) }}')
            ->render(['form' => $form->createView()]);

        return new Crawler('<div>' . $html . '</div>');
    }
}
