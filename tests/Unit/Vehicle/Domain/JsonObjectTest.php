<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Vehicle\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Vehicle\Domain\JsonObject;

#[CoversClass(JsonObject::class)]
final class JsonObjectTest extends TestCase
{
    public function testTypedValuesAreRead(): void
    {
        $object = JsonObject::parse('{"name": "Model 3", "count": 3, "pace": 12, "ratio": 0.5, "nested": {"a": "b"}, "empty": null}');

        self::assertSame('Model 3', $object->string('name'));
        self::assertSame(3, $object->int('count'));
        self::assertSame(12.0, $object->float('pace'));
        self::assertSame(0.5, $object->float('ratio'));
        self::assertSame('b', $object->object('nested')->string('a'));
        self::assertFalse($object->has('empty'), 'A null is as good as absent.');
        self::assertNull($object->nullableString('empty'));
        self::assertNull($object->nullableFloat('missing'));
        self::assertNull($object->nullableObject('missing'));
    }

    public function testAListOfObjectsKeepsItsOrder(): void
    {
        $items = JsonObject::parse('{"items": [{"n": 1}, {"n": 2}]}')->objects('items');

        self::assertSame([1, 2], array_map(static fn (JsonObject $item): int => $item->int('n'), $items));
        self::assertSame([], JsonObject::parse('{"items": []}')->objects('items'));
    }

    /**
     * @return iterable<string, array{string, \Closure(JsonObject): mixed, string}>
     */
    public static function invalid(): iterable
    {
        yield 'a string that is a number' => ['{"a": 1}', static fn (JsonObject $o): string => $o->string('a'), 'a must be a string.'];
        yield 'an integer that is text' => ['{"a": "1"}', static fn (JsonObject $o): int => $o->int('a'), 'a must be an integer.'];
        yield 'an integer that is a float' => ['{"a": 1.5}', static fn (JsonObject $o): int => $o->int('a'), 'a must be an integer.'];
        yield 'a number that is text' => ['{"a": "x"}', static fn (JsonObject $o): float => $o->float('a'), 'a must be a number.'];
        yield 'a missing value' => ['{}', static fn (JsonObject $o): string => $o->string('a'), 'a must be a string.'];
        yield 'an object that is a list' => ['{"a": [1]}', static fn (JsonObject $o): JsonObject => $o->object('a'), 'a must be an object.'];
        yield 'a list that is an object' => ['{"a": {"b": 1}}', static fn (JsonObject $o): array => $o->objects('a'), 'a must be a list.'];
        yield 'an item that is not an object' => ['{"a": [{"b": 1}, 2]}', static fn (JsonObject $o): array => $o->objects('a'), 'a[1] must be an object.'];
        yield 'a nested value' => ['{"a": {"b": [{"c": "x"}]}}', static fn (JsonObject $o): int => $o->object('a')->objects('b')[0]->int('c'), 'a.b[0].c must be an integer.'];
    }

    /**
     * @param \Closure(JsonObject): mixed $read
     */
    #[DataProvider('invalid')]
    public function testAValueOfTheWrongTypeNamesItsPath(string $json, \Closure $read, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $read(JsonObject::parse($json));
    }

    public function testTextThatIsNotJsonIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not valid JSON');

        JsonObject::parse('{"a": ');
    }

    public function testAnEmptyObjectIsAnObject(): void
    {
        self::assertFalse(JsonObject::parse('{}')->has('anything'));
        self::assertSame([], JsonObject::parse('{"items": []}')->objects('items'));
    }

    public function testATopLevelThatIsNotAnObjectIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a JSON object');

        JsonObject::parse('[1, 2]');
    }
}
