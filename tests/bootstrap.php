<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__) . '/.env');

// A random key for each test run: no encryption key, not even a fake one, is committed.
if (!isset($_SERVER['POLARIS_ENCRYPTION_KEY']) || '' === $_SERVER['POLARIS_ENCRYPTION_KEY']) {
    $_SERVER['POLARIS_ENCRYPTION_KEY'] = $_ENV['POLARIS_ENCRYPTION_KEY'] = base64_encode(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
}
