<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Account;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every page requires a signed-in user. The test walks the router, so a route added later is
 * covered without touching it: it ships protected, or it is added to the allow-list on purpose.
 */
#[CoversNothing]
final class AccessControlTest extends WebTestCase
{
    /**
     * The routes that are public on purpose: the login page, and the language switcher, which the
     * login page uses.
     */
    private const array PUBLIC_ROUTES = ['account_login', 'shared_locale', 'account_forgot_password', 'account_forgot_password_sent', 'account_reset_password', 'tesla_public_key'];

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
            if (\in_array($name, self::PUBLIC_ROUTES, true) || $this->isTooling($path)) {
                continue;
            }

            $url = preg_replace('/\{[^}]+\}/', '01JZZZZZZZZZZZZZZZZZZZZZZZ', $path) ?? $path;
            $method = $route->getMethods()[0] ?? 'GET';
            $client->request($method, $url);

            self::assertResponseRedirects('http://localhost/login', null, \sprintf('Route "%s" (%s %s) is reachable without signing in.', $name, $method, $path));
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
