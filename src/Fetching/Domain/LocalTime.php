<?php

declare(strict_types=1);

namespace Polaris\Fetching\Domain;

/**
 * A wall-clock time of day in the user's time zone ("21:00"), without a date or a zone: it becomes a
 * UTC instant each day through {@see SlotCalculator}, so daylight saving changes are followed.
 */
final readonly class LocalTime implements \Stringable
{
    private function __construct(
        public int $hour,
        public int $minute,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the text is not a 24-hour "HH:MM" time
     */
    public static function fromString(string $time): self
    {
        if (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches)) {
            throw new \InvalidArgumentException(\sprintf('A fetch slot must be a 24-hour time written HH:MM, got "%s".', $time));
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    /**
     * The slot used when none is configured.
     */
    public static function default(): self
    {
        return new self(21, 0);
    }

    public function equals(self $other): bool
    {
        return $this->hour === $other->hour && $this->minute === $other->minute;
    }

    public function __toString(): string
    {
        return \sprintf('%02d:%02d', $this->hour, $this->minute);
    }
}
