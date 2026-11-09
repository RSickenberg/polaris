<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service\Dto;

/**
 * One value of the debug page, already written as text. A structured value (a JSON payload) is
 * multi-line: the page shows it in a block that can be opened.
 */
final readonly class DebugCell
{
    public function __construct(
        public string $text,
        public bool $multiline = false,
        public bool $null = false,
    ) {
    }
}
