<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The French texts follow French typography: a no-break space (U+00A0) before ; ! ? and : and inside « », and the
 * typographic apostrophe ’ (a straight one is also the escape character of ICU messages).
 */
#[CoversNothing]
final class FrenchTypographyTest extends TestCase
{
    /**
     * @var array<string, string> what is wrong, by the pattern that finds it
     */
    private const array RULES = [
        '/[ \x{202F}][;!?]/u' => 'a space other than U+00A0 before ; ! or ?',
        '/(?<![\x{00A0}\s])[;!?]/u' => 'no U+00A0 before ; ! or ?',
        '/[ \x{202F}]:(?!:)/u' => 'a space other than U+00A0 before :',
        '/«(?!\x{00A0})/u' => 'no U+00A0 after «',
        '/(?<!\x{00A0})»/u' => 'no U+00A0 before »',
        "/'/u" => 'a straight apostrophe',
    ];

    public function testTheFrenchTextsFollowFrenchTypography(): void
    {
        $problems = [];

        foreach (glob(\dirname(__DIR__, 3) . '/translations/*.fr.xlf') ?: [] as $file) {
            $xml = simplexml_load_string((string) file_get_contents($file));
            self::assertNotFalse($xml, $file);
            $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

            foreach ($xml->xpath('//x:trans-unit') ?: [] as $unit) {
                $target = (string) $unit->target;

                foreach (self::RULES as $pattern => $problem) {
                    if (1 === preg_match($pattern, $target)) {
                        $problems[] = \sprintf('%s "%s": %s', basename($file), (string) $unit['id'], $problem);
                    }
                }
            }
        }

        self::assertSame([], $problems);
    }
}
