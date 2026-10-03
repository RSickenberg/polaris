<?php

declare(strict_types=1);

use Polaris\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => Kernel::fromContext($context);
