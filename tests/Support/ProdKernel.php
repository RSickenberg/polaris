<?php

declare(strict_types=1);

namespace Polaris\Tests\Support;

use Polaris\Kernel;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The production kernel, as the Sentry bundle exists only there, with the Sentry hub public and the arguments of the
 * Messenger listener recorded, so that the tests can read what the real configuration did.
 */
final class ProdKernel extends Kernel
{
    /** @var array<int|string, mixed> */
    public static array $messengerListenerArguments = [];

    /**
     * A cache of its own, built once per test run: the arguments are recorded while the container is compiled.
     */
    public static function cacheDirectory(): string
    {
        return \dirname(__DIR__, 2) . '/var/cache/prod_test_' . getmypid();
    }

    public function getCacheDir(): string
    {
        return self::cacheDirectory();
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(HubInterface::class)->setPublic(true);

                if ($container->hasDefinition(MessengerListener::class)) {
                    ProdKernel::$messengerListenerArguments = $container->getDefinition(MessengerListener::class)->getArguments();
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
    }
}
