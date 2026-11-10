<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OptionalBundleTest extends TestCase
{
    private const string BUNDLE = 'Polaris\Hosted\HostedBundle';

    public function testSelfHostedInstallRegistersNoHostedBundle(): void
    {
        self::assertFalse(class_exists(self::BUNDLE), 'This repository ships no hosted bundle.');

        $bundles = require \dirname(__DIR__, 2) . '/config/bundles.php';

        self::assertIsArray($bundles);
        self::assertArrayNotHasKey(self::BUNDLE, $bundles);
        self::assertArrayHasKey(\Symfony\Bundle\FrameworkBundle\FrameworkBundle::class, $bundles);
    }
}
