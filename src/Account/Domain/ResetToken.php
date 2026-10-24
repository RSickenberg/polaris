<?php

declare(strict_types=1);

namespace Polaris\Account\Domain;

use Random\RandomException;

/**
 * The secret of a password reset link: a selector, which finds the request in the database, and a
 * verifier, of which only a hash is stored, so a database leak gives no usable link. The link
 * carries both, glued together.
 */
final readonly class ResetToken
{
    private const int SELECTOR_LENGTH = 20;
    private const int VERIFIER_LENGTH = 43;

    private function __construct(
        private string $selector,
        #[\SensitiveParameter]
        private string $verifier,
    ) {
    }

    /**
     * @throws RandomException
     */
    public static function generate(): self
    {
        return new self(
            substr(self::encode(random_bytes(15)), 0, self::SELECTOR_LENGTH),
            substr(self::encode(random_bytes(32)), 0, self::VERIFIER_LENGTH),
        );
    }

    /**
     * The token of a link, or null when the text cannot be one.
     */
    public static function parse(#[\SensitiveParameter] string $text): ?self
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{' . (self::SELECTOR_LENGTH + self::VERIFIER_LENGTH) . '}$/', $text)) {
            return null;
        }

        return new self(substr($text, 0, self::SELECTOR_LENGTH), substr($text, self::SELECTOR_LENGTH));
    }

    public function selector(): string
    {
        return $this->selector;
    }

    /**
     * What goes in the link.
     */
    public function toString(): string
    {
        return $this->selector . $this->verifier;
    }

    /**
     * What is stored.
     */
    public function hashedVerifier(): string
    {
        return hash('sha256', $this->verifier);
    }

    public function matches(string $hashedVerifier): bool
    {
        return hash_equals($hashedVerifier, $this->hashedVerifier());
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
