<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service\Dto;

/**
 * The stored rows of one table, as the database holds them: the newest ones, and how many there are in all.
 */
final readonly class DebugTable
{
    /**
     * @param list<string>                   $columns
     * @param list<array<string, DebugCell>> $rows    keyed by column
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $rows,
        public int $total,
    ) {
    }
}
