<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Tests\Support\TranslationFilesChecker;

#[CoversClass(TranslationFilesChecker::class)]
final class TranslationFilesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/polaris-translations-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    /**
     * This is the check CI runs: every key of the English files has a French translation.
     */
    public function testEveryEnglishKeyIsTranslatedToFrench(): void
    {
        self::assertSame([], new TranslationFilesChecker(\dirname(__DIR__, 3) . '/translations')->check('fr'));
    }

    public function testAMissingFrenchKeyIsReported(): void
    {
        $this->write('demo', 'en', ['a.title' => 'Title', 'a.dynamic' => 'Built at run time']);
        $this->write('demo', 'fr', ['a.title' => 'Titre']);

        self::assertSame(['demo.fr.xlf: missing key "a.dynamic"'], new TranslationFilesChecker($this->directory)->check('fr'));
    }

    public function testAMissingFrenchFileIsReported(): void
    {
        $this->write('demo', 'en', ['a.title' => 'Title']);

        self::assertSame(['demo.fr.xlf: the file is missing'], new TranslationFilesChecker($this->directory)->check('fr'));
    }

    public function testAnUnknownKeyAnEmptyTextAndChangedPlaceholdersAreReported(): void
    {
        $this->write('demo', 'en', ['a.empty' => 'Text', 'a.limit' => 'Up to %limit% {count, number}']);
        $this->write('demo', 'fr', ['a.empty' => ' ', 'a.limit' => 'Jusqu a %max% {count, number}', 'a.stale' => 'Ancien']);
        $this->write('orphan', 'fr', ['x' => 'y']);

        self::assertEqualsCanonicalizing([
            'demo.fr.xlf: key "a.stale" does not exist in en',
            'demo.fr.xlf: empty translation for "a.empty"',
            'demo.fr.xlf: "a.limit" does not use the same placeholders as en',
            'orphan.fr.xlf: there is no en counterpart',
        ], new TranslationFilesChecker($this->directory)->check('fr'));
    }

    /**
     * @param array<string, string> $messages
     */
    private function write(string $domain, string $locale, array $messages): void
    {
        $units = '';
        foreach ($messages as $key => $text) {
            $units .= \sprintf('<trans-unit id="%1$s"><source>%1$s</source><target>%2$s</target></trans-unit>', $key, htmlspecialchars($text, \ENT_NOQUOTES));
        }

        file_put_contents(
            \sprintf('%s/%s.%s.xlf', $this->directory, $domain, $locale),
            \sprintf('<?xml version="1.0" encoding="utf-8"?><xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2"><file source-language="en" target-language="%s" datatype="plaintext" original="file.ext"><body>%s</body></file></xliff>', $locale, $units),
        );
    }
}
