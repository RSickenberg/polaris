<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\CallbackUrlParser;
use Polaris\Tesla\Domain\InvalidCallbackUrl;

#[CoversClass(CallbackUrlParser::class)]
final class CallbackUrlParserTest extends TestCase
{
    public function testReadsTheCodeAndTheState(): void
    {
        $callback = CallbackUrlParser::parse(' https://polaris.local/tesla/callback?code=fake-code&state=fake-state&issuer=https%3A%2F%2Fauth.tesla.com ');

        self::assertSame('fake-code', $callback->code);
        self::assertSame('fake-state', $callback->state);
        self::assertNull($callback->error);
    }

    public function testReadsAnOAuthError(): void
    {
        $callback = CallbackUrlParser::parse('http://localhost:8000/tesla/callback?error=access_denied&state=fake-state');

        self::assertNull($callback->code);
        self::assertSame('access_denied', $callback->error);
        self::assertSame('fake-state', $callback->state);
    }

    public function testIgnoresArrayValues(): void
    {
        $callback = CallbackUrlParser::parse('http://localhost/cb?code[]=a&state=s');

        self::assertNull($callback->code);
        self::assertSame('s', $callback->state);
    }

    #[DataProvider('withoutQuery')]
    public function testRefusesAUrlWithoutAQueryString(string $url): void
    {
        $this->expectException(InvalidCallbackUrl::class);

        CallbackUrlParser::parse($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function withoutQuery(): iterable
    {
        yield 'empty' => [''];
        yield 'no query' => ['https://polaris.local/tesla/callback'];
        yield 'empty query' => ['https://polaris.local/tesla/callback?'];
        yield 'not a url' => ['just some text'];
    }
}
