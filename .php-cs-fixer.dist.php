<?php

declare(strict_types=1);

$finder = new PhpCsFixer\Finder()
    ->in(__DIR__)
    ->exclude('var')
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
    ->append([
        __DIR__.'/bin/console',
    ])
;

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP8x5Migration' => true,
        '@PHP8x5Migration:risky' => true,
        'concat_space' => ['spacing' => 'one'],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/cache/php-cs-fixer/.php-cs-fixer.cache')
;
