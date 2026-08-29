<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use RuntimeException;
use Tests\TestCase;

/**
 * The shell now opens in the configured default language whatever the browser
 * asks for (SpaShellTest). That takes away the one thing an English reader used
 * to rely on to stay in English across a reload — their own Accept-Language —
 * so the choice made in the context bar has to be remembered instead.
 *
 * Remembered *before the document paints*, for the reason Design System §3.1
 * gives about the theme and ThemeFlashTest measured: everything else in this
 * SPA lives in the bundle, and @vite loads it as a module script, which runs
 * after the document has painted. A language applied there paints the first
 * frame in the wrong direction, and a whole RTL layout arriving LTR is not a
 * subtle flash.
 *
 * ── What this file cannot do ───────────────────────────────────────────────
 *
 * It reads rendered HTML and source. It cannot observe a paint, and it cannot
 * run the script: that the browser actually honours a stored 'en' was checked
 * by hand and is reported with the point, not asserted here.
 */
final class LocalePreferenceTest extends TestCase
{
    private const STORAGE_KEY = 'crm.locale';

    public function test_the_remembered_language_is_applied_before_the_bundle_is_requested(): void
    {
        $html = $this->shell();

        $script = strpos($html, self::STORAGE_KEY);
        $bundle = strpos($html, '/build/');

        self::assertIsInt($script, 'The rendered shell never reads a remembered language.');
        self::assertIsInt($bundle, '@vite emitted no asset, so the ordering cannot be checked at all.');
        self::assertLessThan($bundle, $script,
            'A language read after the bundle is a language applied after the first paint.');

        $headEnd = strpos($html, '</head>');
        self::assertIsInt($headEnd);
        self::assertLessThan($headEnd, $script, 'Applied from <body>, the body has already begun painting.');
    }

    public function test_the_script_sets_both_the_language_and_the_direction(): void
    {
        // Half of this would be worse than none: a document that says lang="en"
        // and still lays out RTL is wrong in a way neither the reader nor the
        // stylesheet can correct.
        $tag = self::scriptTag($this->shell());

        // Both spelled as the assignment and not as the bare word: 'dir' also
        // occurs in this script's own prose, so the first version of this
        // assertion passed with the direction line deleted. Found by deleting
        // it, not by reading it.
        self::assertStringContainsString('documentElement.lang', $tag);
        self::assertStringContainsString('documentElement.dir', $tag);
    }

    public function test_the_script_validates_what_it_reads_from_storage(): void
    {
        // localStorage is writable by anything on this origin, and this script's
        // whole job is to move a value from there onto the document element.
        // Comparing against the two supported codes is what keeps that from
        // being an attribute-write primitive.
        $tag = self::scriptTag($this->shell());

        // Spelled as comparisons rather than as bare occurrences: both codes
        // appear in this script for other reasons, so 'contains ar' is true of
        // a script that trusts whatever storage held.
        self::assertMatchesRegularExpression("/===\s*'ar'/", $tag);
        self::assertMatchesRegularExpression("/===\s*'en'/", $tag);
    }

    public function test_the_blade_script_and_the_typescript_module_agree(): void
    {
        // Two files, one contract, and a mismatch is silent: the language
        // switches, the preference is written, and the next reload ignores it.
        $module = self::read(base_path('resources/js/i18n.ts'));

        self::assertMatchesRegularExpression(
            "/LOCALE_STORAGE_KEY\s*=\s*'".preg_quote(self::STORAGE_KEY, '/')."'/",
            $module,
            'i18n.ts remembers the language under a different key than the pre-paint script reads.',
        );
    }

    // ───────────────────────────────────────────────────────────── mechanics

    private function shell(): string
    {
        $html = $this->get('/')->getContent();

        if (! is_string($html)) {
            throw new RuntimeException('The shell rendered nothing.');
        }

        return $html;
    }

    /** The one <script> element that carries the remembered language. */
    private static function scriptTag(string $html): string
    {
        if (preg_match_all('/<script\b[^>]*>.*?<\/script>/s', $html, $matches) === false) {
            throw new RuntimeException('Reading script tags failed: '.preg_last_error_msg());
        }

        foreach ($matches[0] as $tag) {
            if (str_contains($tag, self::STORAGE_KEY)) {
                return $tag;
            }
        }

        throw new RuntimeException('No script in the rendered shell reads the language preference.');
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Could not read {$path}.");
        }

        return $contents;
    }
}
