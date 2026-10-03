<?php

declare(strict_types=1);

use Polaris\Kernel;
use Symfony\Component\Dotenv\Dotenv;

// Loaded by phpstan/phpstan-doctrine (phpstan.dist.neon) to read the entity mappings.
require dirname(__DIR__) . '/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel('test', false);
$kernel->boot();

return $kernel->getContainer()->get('doctrine.orm.entity_manager');
