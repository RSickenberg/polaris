<?php

declare(strict_types=1);

namespace Polaris\Account\Domain;

/**
 * The letters shown in the avatar of a user, taken from the part of the email before the "@":
 * the first letter of its first two words ("jane.doe@..." is "JD"), or its first letter.
 */
final class Initials
{
    private function __construct()
    {
    }

    public static function fromEmail(string $email): string
    {
        $localPart = explode('@', $email, 2)[0];
        $words = preg_split('/[^\p{L}\p{N}]+/u', $localPart, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        $initials = '';
        foreach (\array_slice($words, 0, 2) as $word) {
            $initials .= mb_substr($word, 0, 1);
        }

        return '' === $initials ? '?' : mb_strtoupper($initials);
    }
}
