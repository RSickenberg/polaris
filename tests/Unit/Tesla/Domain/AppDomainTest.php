<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\InvalidAppDomain;

#[CoversClass(AppDomain::class)]
#[CoversClass(InvalidAppDomain::class)]
final class AppDomainTest extends TestCase
{
    public function testItTrimsAndLowerCasesTheHost(): void
    {
        $domain = AppDomain::fromString("  Polaris.Example.COM \n");

        self::assertSame('polaris.example.com', $domain->toString());
        self::assertSame('polaris.example.com', (string) $domain);
    }

    public function testItBuildsTheUrlTeslaFetches(): void
    {
        self::assertSame(
            'https://polaris.example.com/.well-known/appspecific/com.tesla.3p.public-key.pem',
            AppDomain::fromString('polaris.example.com')->publicKeyUrl(),
        );
    }

    #[DataProvider('invalidDomains')]
    public function testItRejectsWhatIsNotABareHost(string $value): void
    {
        $this->expectException(InvalidAppDomain::class);

        AppDomain::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDomains(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'scheme' => ['https://polaris.example.com'];
        yield 'path' => ['polaris.example.com/key'];
        yield 'port' => ['polaris.example.com:8443'];
        yield 'single label' => ['localhost'];
        yield 'space inside' => ['polaris example.com'];
        yield 'leading hyphen' => ['-polaris.example.com'];
        yield 'trailing hyphen' => ['polaris-.example.com'];
        yield 'empty label' => ['polaris..example.com'];
        yield 'underscore' => ['po_laris.example.com'];
        yield 'too long' => [str_repeat('a', 250) . '.com'];
    }
}
