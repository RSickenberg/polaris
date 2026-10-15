<?php

declare(strict_types=1);

namespace Polaris\Shared\Controller;

use Polaris\Shared\Service\CookiePreferredLocaleProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The language switcher: remembers the chosen locale in a cookie, then goes back to the page.
 * Only the cookie changes, so a plain link is enough (no CSRF token, no state to protect).
 */
final class LocaleController extends AbstractController
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
    ) {
    }

    #[Route('/locale/{locale}', name: 'shared_locale', methods: ['GET'])]
    public function switch(Request $request, string $locale): RedirectResponse
    {
        if (!\in_array($locale, $this->enabledLocales, true)) {
            throw new NotFoundHttpException();
        }

        $response = new RedirectResponse($this->localPath($request->query->getString('redirect')));
        $response->headers->setCookie(Cookie::create(
            name: CookiePreferredLocaleProvider::COOKIE,
            value: $locale,
            expire: $this->clock->now()->modify('+1 year'),
            secure: $request->isSecure(),
        ));

        return $response;
    }

    /**
     * Only a path of this site is a valid target: anything else (another host, "//host",
     * a backslash trick) goes to the root, so the switcher cannot be used as an open redirect.
     */
    private function localPath(string $target): string
    {
        return 1 === preg_match('#^/(?![/\\\\])[^\r\n]*$#', $target) ? $target : '/';
    }
}
