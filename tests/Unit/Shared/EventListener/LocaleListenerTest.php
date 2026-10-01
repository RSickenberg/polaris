<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\EventListener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\EventListener\LocaleListener;
use Polaris\Shared\Service\NullPreferredLocaleProvider;
use Polaris\Shared\Service\PreferredLocaleProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(LocaleListener::class)]
final class LocaleListenerTest extends TestCase
{
    public function testUserPreferenceWinsOverTheBrowser(): void
    {
        $request = $this->handle(self::request('en'), self::preference('fr'));

        self::assertSame('fr', $request->getLocale());
    }

    public function testBrowserIsUsedWithoutPreference(): void
    {
        self::assertSame('fr', $this->handle(self::request('fr-CH,fr;q=0.9,en;q=0.8'), new NullPreferredLocaleProvider())->getLocale());
    }

    public function testUnsupportedBrowserLanguageFallsBackToEnglish(): void
    {
        self::assertSame('en', $this->handle(self::request('de-DE'), new NullPreferredLocaleProvider())->getLocale());
    }

    public function testRouteLocaleIsLeftToTheFramework(): void
    {
        $request = self::request('fr');
        $request->attributes->set('_locale', 'en');

        self::assertSame('en', $this->handle($request, self::preference('fr'))->getLocale());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $request = self::request('fr');
        $listener = new LocaleListener(new NullPreferredLocaleProvider(), ['en', 'fr'], 'en');
        $listener(new RequestEvent($this->createStub(KernelInterface::class), $request, HttpKernelInterface::SUB_REQUEST));

        self::assertSame('en', $request->getLocale());
    }

    private function handle(Request $request, PreferredLocaleProvider $preference): Request
    {
        $listener = new LocaleListener($preference, ['en', 'fr'], 'en');
        $listener(new RequestEvent($this->createStub(KernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        return $request;
    }

    private static function request(string $acceptLanguage): Request
    {
        $request = Request::create('/');
        $request->setDefaultLocale('en');
        $request->headers->set('Accept-Language', $acceptLanguage);

        return $request;
    }

    private static function preference(string $locale): PreferredLocaleProvider
    {
        return new readonly class($locale) implements PreferredLocaleProvider {
            public function __construct(private string $locale)
            {
            }

            public function preferredLocale(): string
            {
                return $this->locale;
            }
        };
    }
}
