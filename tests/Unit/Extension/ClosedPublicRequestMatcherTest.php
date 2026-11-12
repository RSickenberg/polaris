<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Extension\ClosedPublicRequestMatcher;
use Symfony\Component\HttpFoundation\Request;

/**
 * The self-hosted default opens nothing: every page stays behind the catch-all of the access control.
 */
#[CoversClass(ClosedPublicRequestMatcher::class)]
final class ClosedPublicRequestMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{path: string, route: ?string}>
     */
    public static function requests(): iterable
    {
        yield 'the root' => ['path' => '/', 'route' => 'dashboard_root'];
        yield 'a page of the app' => ['path' => '/dashboard', 'route' => 'dashboard_home'];
        yield 'a path no route serves' => ['path' => '/en', 'route' => null];
    }

    #[DataProvider('requests')]
    public function testItMatchesNoRequest(string $path, ?string $route): void
    {
        $request = Request::create($path);
        if (null !== $route) {
            $request->attributes->set('_route', $route);
        }

        self::assertFalse(new ClosedPublicRequestMatcher()->matches($request));
    }
}
