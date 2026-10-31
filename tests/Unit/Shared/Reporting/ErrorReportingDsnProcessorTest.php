<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Reporting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Reporting\ErrorReportingDsnProcessor;
use Polaris\Shared\Reporting\ErrorReportingMode;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

#[CoversClass(ErrorReportingDsnProcessor::class)]
#[CoversClass(ErrorReportingMode::class)]
final class ErrorReportingDsnProcessorTest extends TestCase
{
    private const string MAINTAINER = 'https://maintainer@o1.ingest.sentry.io/1';
    private const string CUSTOM = 'https://owner@o2.ingest.sentry.io/2';

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function switches(): iterable
    {
        yield 'unset' => [[], ''];
        yield 'empty' => [['POLARIS_ERROR_REPORTING' => ''], ''];
        yield 'off' => [['POLARIS_ERROR_REPORTING' => 'off', 'SENTRY_DSN' => self::CUSTOM], ''];
        yield 'unknown word' => [['POLARIS_ERROR_REPORTING' => 'yes', 'SENTRY_DSN' => self::CUSTOM], ''];
        yield 'maintainer' => [['POLARIS_ERROR_REPORTING' => 'maintainer', 'SENTRY_DSN' => self::CUSTOM], self::MAINTAINER];
        yield 'maintainer, any case' => [['POLARIS_ERROR_REPORTING' => ' Maintainer '], self::MAINTAINER];
        yield 'custom' => [['POLARIS_ERROR_REPORTING' => 'custom', 'SENTRY_DSN' => self::CUSTOM], self::CUSTOM];
        yield 'custom without a DSN' => [['POLARIS_ERROR_REPORTING' => 'custom'], ''];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('switches')]
    public function testTheSwitchPicksTheDsn(array $env, string $expected): void
    {
        $processor = new ErrorReportingDsnProcessor(self::MAINTAINER);

        $actual = $processor->getEnv('polaris_dsn', 'POLARIS_ERROR_REPORTING', static fn (string $name): string => $env[$name] ?? throw new EnvNotFoundException($name));

        self::assertSame($expected, $actual);
    }

    public function testTheMaintainerOptionSendsNothingWhileTheShippedDsnIsEmpty(): void
    {
        $processor = new ErrorReportingDsnProcessor('');

        self::assertSame('', $processor->getEnv('polaris_dsn', 'POLARIS_ERROR_REPORTING', static fn (): string => 'maintainer'));
    }
}
