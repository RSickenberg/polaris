<?php

declare(strict_types=1);

$finder = new PhpCsFixer\Finder()
    ->in(__DIR__)
    ->exclude([
        'assets/vendor',
        'var',
    ])
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
        // Rewritten by the importmap commands.
        'importmap.php',
    ])
    ->append([
        __DIR__ . '/bin/console',
    ])
;

return new PhpCsFixer\Config()
    ->registerCustomFixers(new PhpCsFixerCustomFixers\Fixers())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP8x5Migration' => true,
        '@PHP8x5Migration:risky' => true,
        'concat_space' => ['spacing' => 'one'],
        'no_redundant_readonly_property' => true,
        'php_unit_data_provider_return_type' => true,
        'declare_strict_types' => ['strategy' => 'enforce'],
        PhpCsFixerCustomFixers\Fixer\FunctionParameterSeparationFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\MultilineCommentOpeningClosingAloneFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoImportFromGlobalNamespaceFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoUselessParenthesisFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\SingleSpaceBeforeStatementFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\SingleSpaceAfterStatementFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\StringableInterfaceFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\PhpUnitAssertArgumentsOrderFixer::name() => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/var/cache/php-cs-fixer/.php-cs-fixer.cache')
;
