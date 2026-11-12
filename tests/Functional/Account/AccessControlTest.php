<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Account;

use PHPUnit\Framework\Attributes\CoversNothing;
use Polaris\Extension\PublicRequestMatcher;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every page requires a signed-in user. The test walks the router, so a route added later is
 * covered without touching it: it ships protected, or it is added to the allow-list on purpose.
 *
 * The pages an edition opens through `Extension\PublicRequestMatcher` are its own choice, and its own tests check
 * them: the walk asks the matcher of the container and leaves out what it opens (nothing with the self-hosted
 * default, which `ClosedPublicRequestMatcherTest` checks). Setting the default in the container would not help here:
 * the firewall's access map gets its own instance of the matcher, which `set()` does not replace.
 */
#[CoversNothing]
final class AccessControlTest extends WebTestCase
{
    /**
     * The routes that are public on purpose: the login page, and the language switcher, which the
     * login page uses.
     */
    private const array PUBLIC_ROUTES = [
        'account_login',
        'shared_locale',
        'account_forgot_password',
        'account_forgot_password_sent',
        'account_reset_password',
        'tesla_public_key',
    ];

    /**
     * Paths public by their own rule in `security.yaml`, whichever route serves them: the root (`^/$`), where an
     * edition can serve its own page with a route of higher priority than `dashboard_root`.
     */
    private const array PUBLIC_PATHS = ['/'];

    /**
     * Development tooling: open in the dev and test environments only (parameter
     * polaris.security.open_paths), and not routed in production.
     */
    private const array TOOLING_PATH_PREFIXES = ['/_profiler', '/_wdt'];

    public function testEveryRouteRequiresAuthenticationUnlessItIsPublic(): void
    {
        $client = self::createClient();
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();

        $checked = [];
        foreach ($routes as $name => $route) {
            $path = $route->getPath();
            $public = \in_array($name, self::PUBLIC_ROUTES, true) || \in_array($path, self::PUBLIC_PATHS, true);
            if ($public || $this->isTooling($path) || $this->isOpenedByTheEdition($name, $route)) {
                continue;
            }

            $url = preg_replace('/\{[^}]+\}/', '01JZZZZZZZZZZZZZZZZZZZZZZZ', $path) ?? $path;
            $method = $route->getMethods()[0] ?? 'GET';
            $client->request($method, $url);

            self::assertResponseRedirects(
                'http://localhost/login',
                null,
                \sprintf('Route "%s" (%s %s) is reachable without signing in.', $name, $method, $path),
            );
            $checked[] = $name;
        }

        // The guard must not pass because the loop found nothing.
        self::assertContains('dashboard_home', $checked);
        self::assertContains('dashboard_show', $checked);
        self::assertContains('reading_history', $checked);
        self::assertContains('lease_settings', $checked);
    }

    public function testThePublicRoutesAreReachable(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/locale/fr', ['redirect' => '/login']);
        self::assertResponseRedirects('/login');
    }

    public function testTheRootIsPublicAndRedirectsToTheDashboardAndThenToLogin(): void
    {
        $client = self::createClient();

        $client->request('GET', '/');
        self::assertFalse($client->getResponse()->isRedirect('http://localhost/login'), 'The root is public.');

        // An edition that serves its own page on / (a route of higher priority) stops here: the redirects below are
        // the ones of polaris' own root.
        if ('dashboard_root' !== self::getContainer()->get(RouterInterface::class)->match('/')['_route']) {
            return;
        }

        self::assertResponseRedirects('/dashboard');

        $client->request('GET', '/dashboard');
        self::assertResponseRedirects('http://localhost/login');
    }

    /**
     * Asks the edition's matcher with the route name the router gives a request for this route (the canonical name of
     * a localized route, `landing` for `landing.en`).
     */
    private function isOpenedByTheEdition(string $name, Route $route): bool
    {
        $request = Request::create($route->getPath());
        $request->attributes->set('_route', $route->getDefault('_canonical_route') ?? $name);

        return self::getContainer()->get(PublicRequestMatcher::class)->matches($request);
    }

    private function isTooling(string $path): bool
    {
        foreach (self::TOOLING_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
