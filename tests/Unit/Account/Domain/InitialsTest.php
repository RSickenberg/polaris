<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Account\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Account\Domain\Initials;

#[CoversClass(Initials::class)]
final class InitialsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emails(): iterable
    {
        yield 'one word' => ['dev@example.test', 'D'];
        yield 'two words with a dot' => ['jane.doe@example.test', 'JD'];
        yield 'underscore and dash' => ['jane_marie-doe@example.test', 'JM'];
        yield 'plus tag' => ['jane+polaris@example.test', 'JP'];
        yield 'accent' => ['élodie@example.test', 'É'];
        yield 'digits' => ['42@example.test', '4'];
        yield 'nothing usable' => ['...@example.test', '?'];
        yield 'empty' => ['', '?'];
    }

    #[DataProvider('emails')]
    public function testInitials(string $email, string $expected): void
    {
        self::assertSame($expected, Initials::fromEmail($email));
    }
}
