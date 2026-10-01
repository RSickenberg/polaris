<?php

declare(strict_types=1);

namespace Polaris\Shared\EventListener;

use Polaris\Shared\Domain\LocaleNegotiator;
use Polaris\Shared\Service\PreferredLocaleProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets the locale of the main request. It runs after the framework stored the default locale
 * (priority 100) and before the translator, Twig and Intl follow the request locale (16).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final readonly class LocaleListener
{
    private LocaleNegotiator $negotiator;

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private PreferredLocaleProvider $preferredLocale,
        #[Autowire('%kernel.enabled_locales%')]
        array $enabledLocales,
        #[Autowire('%kernel.default_locale%')]
        string $defaultLocale,
    ) {
        $this->negotiator = new LocaleNegotiator($enabledLocales, $defaultLocale);
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // A route that sets _locale is handled by the framework listener.
        if (!$event->isMainRequest() || $request->attributes->has('_locale')) {
            return;
        }

        $request->setLocale($this->negotiator->negotiate(
            $this->preferredLocale->preferredLocale(),
            $request->headers->get('Accept-Language'),
        ));
    }
}
