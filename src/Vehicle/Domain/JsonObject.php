<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Domain;

/**
 * A JSON object read with types: each getter says what it expects and fails with the path of the value, so that a
 * file that does not fit is refused with a message that points at the faulty value ("readings[3].odometerMetres").
 */
final readonly class JsonObject
{
    /**
     * @param array<mixed> $data
     */
    private function __construct(
        private array $data,
        private string $path,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the text is not JSON or its top level is not an object
     */
    public static function parse(string $json): self
    {
        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('The file is not valid JSON.', 0, $e);
        }

        if (!self::isObject($data)) {
            throw new \InvalidArgumentException('The file is not a JSON object.');
        }

        return new self($data, '');
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        return \is_string($value) ? $value : throw $this->invalid($key, 'a string');
    }

    public function nullableString(string $key): ?string
    {
        return $this->has($key) ? $this->string($key) : null;
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;

        return \is_int($value) ? $value : throw $this->invalid($key, 'an integer');
    }

    public function float(string $key): float
    {
        $value = $this->data[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : throw $this->invalid($key, 'a number');
    }

    public function nullableFloat(string $key): ?float
    {
        return $this->has($key) ? $this->float($key) : null;
    }

    public function object(string $key): self
    {
        $value = $this->data[$key] ?? null;

        return self::isObject($value) ? new self($value, $this->child($key)) : throw $this->invalid($key, 'an object');
    }

    public function nullableObject(string $key): ?self
    {
        return $this->has($key) ? $this->object($key) : null;
    }

    /**
     * @return list<self>
     */
    public function objects(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->invalid($key, 'a list');
        }

        $objects = [];

        foreach ($value as $index => $item) {
            if (!self::isObject($item)) {
                throw new \InvalidArgumentException(\sprintf('%s[%d] must be an object.', $this->child($key), $index));
            }

            $objects[] = new self($item, \sprintf('%s[%d]', $this->child($key), $index));
        }

        return $objects;
    }

    /**
     * Whether a decoded value is a JSON object: an array with keys (`{}` decodes to an empty array, which is accepted as
     * an object too, since PHP cannot tell it from `[]`).
     *
     * @phpstan-assert-if-true array<mixed> $value
     */
    private static function isObject(mixed $value): bool
    {
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }

    private function child(string $key): string
    {
        return '' === $this->path ? $key : $this->path . '.' . $key;
    }

    private function invalid(string $key, string $expected): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf('%s must be %s.', $this->child($key), $expected));
    }
}
