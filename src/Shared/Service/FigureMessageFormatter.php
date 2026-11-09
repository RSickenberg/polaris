<?php

declare(strict_types=1);

namespace Polaris\Shared\Service;

use Polaris\Shared\Domain\NumberConvention;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Translation\Formatter\IntlFormatterInterface;
use Symfony\Component\Translation\Formatter\MessageFormatterInterface;

/**
 * Formats the figures of ICU messages (`{limit, number}`, `{cost}` and the like) by {@see NumberConvention}, like
 * the Twig filters of {@see \Polaris\Shared\Twig\FigureExtension}: in French, fr_CH and 12’345. The translation
 * files stay per language (fr), only the formatting locale changes.
 */
#[AsDecorator('translator.formatter')]
final readonly class FigureMessageFormatter implements MessageFormatterInterface, IntlFormatterInterface
{
    public function __construct(
        #[AutowireDecorated]
        private MessageFormatterInterface&IntlFormatterInterface $inner,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function format(string $message, string $locale, array $parameters = []): string
    {
        return $this->inner->format($message, $locale, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function formatIntl(string $message, string $locale, array $parameters = []): string
    {
        $convention = NumberConvention::of($locale);

        return $convention->group($this->inner->formatIntl($message, $convention->locale, $parameters));
    }
}
