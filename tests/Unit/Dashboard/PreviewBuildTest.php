<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Dashboard\Service\PreviewBuild;

#[CoversClass(PreviewBuild::class)]
final class PreviewBuildTest extends TestCase
{
    #[DataProvider('versions')]
    public function testOnlyALocalBuildAndANightlyArePreviews(string $version, bool $preview): void
    {
        self::assertSame($preview, new PreviewBuild($version)->isPreview());
    }

    /**
     * @return iterable<string, array{version: string, preview: bool}>
     */
    public static function versions(): iterable
    {
        yield 'local build' => ['version' => 'dev', 'preview' => true];
        yield 'nightly image' => ['version' => 'nightly-3a7480d', 'preview' => true];
        yield 'release' => ['version' => '0.4.0', 'preview' => false];
        yield 'pre-release' => ['version' => '1.0.0-rc.1', 'preview' => false];
        yield 'empty' => ['version' => '', 'preview' => false];
    }
}
