<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Vehicle;

use PHPUnit\Framework\Attributes\CoversNothing;
use Polaris\Vehicle\Form\VehicleDataImportType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

/**
 * The upload form on its own, without a database: how the form theme draws the file field and the button.
 */
#[CoversNothing]
final class VehicleDataImportFormTest extends KernelTestCase
{
    public function testTheFileFieldIsAThemedInputAndTheButtonHasItsIcon(): void
    {
        self::bootKernel();
        $form = self::getContainer()->get(FormFactoryInterface::class)->create(
            VehicleDataImportType::class,
            null,
            ['csrf_protection' => false],
        );

        $html = self::getContainer()->get(Environment::class)
            ->createTemplate('{{ form(form) }}')
            ->render(['form' => $form->createView()]);
        $crawler = new Crawler($html);

        $input = $crawler->filter('input[type=file]');
        self::assertCount(1, $input);
        self::assertSame('input', $input->attr('data-slot'), 'The kit Input draws the file button.');
        self::assertStringContainsString('file:', (string) $input->attr('class'));
        $button = $crawler->filter('button[type=submit]');
        self::assertCount(1, $button);
        self::assertCount(1, $button->filter('svg'), 'The icon comes before the label.');
        self::assertNull($button->attr('data-icon'), 'The icon option is not an attribute of the button.');
    }
}
