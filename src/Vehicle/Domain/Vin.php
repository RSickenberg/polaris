<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Domain;

/**
 * A vehicle identification number (ISO 3779): 17 characters, digits and capital
 * letters except I, O and Q.
 *
 * The North American check digit is not verified: it is not mandatory elsewhere.
 */
final readonly class Vin
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = strtoupper(trim($value));

        if (1 !== preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $normalized)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid VIN: expected 17 digits or capital letters other than I, O and Q.', $value));
        }

        $this->value = $normalized;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
