<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use RuntimeException;
use Tests\TestCase;

/**
 * Point 4.3 — the theme has to be on the document before the document paints.
 *
 * Design System §3.1: "The selected theme must apply before the main
 * application shell paints, avoiding a visible flash of another theme."
 *
 * That sentence rules out the obvious implementation. Everything else in this
 * SPA lives in the bundle, and @vite loads the bundle as a module script, which
 * is deferred by specification — it runs after the document has parsed and
 * painted. A theme applied there is applied one frame late, and one frame of
 * the wrong theme is exactly the flash §3.1 names.
 *
 * So the fix is structural: an inline script above @vite, answering from
 * storage without a round trip.
 *
 * Measured, because the arguments here are easy to get wrong. On a warm LAN
 * even the bundle-applied version showed no flash — the 202 kB of JavaScript
 * arrived before the browser painted at all, which is exactly why this defect
 * survives review on a developer's machine. Throttled to 400 kbps with the
 * cache emptied, the same page painted at 2552ms with no data-theme and a
 * light canvas, then turned dark at 5748ms: 3.2 seconds of the wrong theme.
 * With the inline script it painted dark at 2564ms.
 *
 * That measurement is also what corrected this file. `defer` and `type=module`
 * were assumed to reintroduce the flash; both were tried and neither did,
 * because neither has to fetch anything. The property that matters is the
 * absence of a network fetch, and the tests below say so.
 *
 * ── What this file cannot do ───────────────────────────────────────────────
 *
 * It renders HTML and reads source. It cannot observe a paint. The absence of
 * a flash was measured separately in headless Chromium, by recording
 * data-theme and the computed background at first-contentful-paint; those
 * numbers are in the point 4.3 report, not here.
 *
 * ── What is knowingly not satisfied ────────────────────────────────────────
 *
 * §3.1 and §9.1 both put the preference on the *user profile*, persisting for
 * the account into the next session. There is no account until Module 1, so
 * this stores per browser. The flash requirement is met; the account
 * requirement is not, and Module 1 owes it.
 */
final class ThemeFlashTest extends TestCase
{
    /** The two themes that are not the §3.1 default and therefore need an attribute. */
    private const ATTRIBUTE_THEMES = ['clean-monochrome', 'midnight-obsidian'];

    private const STORAGE_KEY = 'crm.theme';

    public function test_the_theme_script_runs_before_the_bundle_is_requested(): void
    {
        $html = $this->shell();

        $script = strpos($html, self::STORAGE_KEY);
        $bundle = strpos($html, '/build/');

        self::assertIsInt($script, 'The rendered shell contains no pre-paint theme script.');
        self::assertIsInt($bundle, '@vite emitted no asset, so the ordering cannot be checked at all.');

        // Not a style preference. Below the bundle, a render-blocking asset can
        // paint the document before the preference is read.
        self::assertLessThan(
            $bundle,
            $script,
            'The theme script must come before @vite: after it, the shell can paint in the wrong theme (§3.1).',
        );
    }

    public function test_the_theme_script_is_in_the_head(): void
    {
        $html = $this->shell();

        $headEnd = strpos($html, '</head>');
        $script = strpos($html, self::STORAGE_KEY);

        self::assertIsInt($headEnd);
        self::assertIsInt($script);
        self::assertLessThan($headEnd, $script, 'A theme applied from <body> is applied after the body has begun painting.');
    }

    public function test_the_theme_script_needs_no_network(): void
    {
        // This is the assertion that carries the requirement, and its reason is
        // measured rather than argued. With the theme applied from the Vue
        // bundle instead — the obvious implementation — first paint on a
        // throttled connection came at 2552ms with no data-theme at all and a
        // canvas of rgb(248, 248, 249): the light default, for a user who had
        // chosen Dark Blue. The same page with this inline script painted at
        // 2564ms already dark. The difference is not when a script runs; it is
        // whether the answer needed a round trip.
        $tag = self::scriptTag($this->shell());

        self::assertStringNotContainsString(' src=', $tag, 'A fetched script cannot beat the paint it is racing.');
    }

    public function test_the_theme_script_carries_no_deferring_attribute(): void
    {
        // Hygiene, and labelled as hygiene rather than dressed up as the rule.
        // Both were tried: `defer` and `type="module"` on this inline script
        // still painted dark at 2536ms and 2528ms under the same throttle,
        // because neither has to fetch anything and the render-blocking
        // stylesheet was the slower of the two. `defer` and `async` are ignored
        // outright on a classic inline script.
        //
        // They stay forbidden because they state an intent this script must not
        // have, and because that intent is one edit away from becoming a src.
        $tag = self::scriptTag($this->shell());

        self::assertStringNotContainsString('defer', $tag);
        self::assertStringNotContainsString('async', $tag);
        self::assertStringNotContainsString('type="module"', $tag);
    }

    public function test_the_script_validates_what_it_reads_from_storage(): void
    {
        // localStorage is writable by anything on this origin, and this script's
        // entire job is to move a value from there into the document. Comparing
        // against a fixed list is what keeps that from being a write primitive.
        $tag = self::scriptTag($this->shell());

        foreach (self::ATTRIBUTE_THEMES as $theme) {
            self::assertStringContainsString("'{$theme}'", $tag, "The script does not check for '{$theme}'.");
        }

        self::assertMatchesRegularExpression(
            '/if\s*\([^)]*===\s*\'[a-z-]+\'/',
            $tag,
            'The script must compare the stored value against known themes, not pass it through.',
        );
    }

    public function test_the_default_theme_sets_no_attribute(): void
    {
        // §3.1 as D-73 rewrote it makes Warm Editorial the product default and
        // tokens.css puts it on :root. Writing data-theme="warm-editorial"
        // would match nothing in the stylesheet — the theme would still be
        // right, for the wrong reason, until someone added a selector for it.
        $tag = self::scriptTag($this->shell());

        self::assertStringNotContainsString("'warm-editorial'", $tag);
    }

    public function test_the_blade_script_and_the_typescript_store_agree(): void
    {
        // Two files, one contract, and a mismatch is silent: the theme switches,
        // the preference is written, and the next reload ignores it. Nothing
        // errors and nothing looks wrong in either file on its own.
        $store = self::read(base_path('resources/js/theme.ts'));

        self::assertMatchesRegularExpression(
            "/STORAGE_KEY\s*=\s*'".preg_quote(self::STORAGE_KEY, '/')."'/",
            $store,
            'theme.ts stores under a different key than the pre-paint script reads.',
        );

        if (preg_match("/THEMES\s*=\s*\[([^\]]*)\]/", $store, $matches) !== 1) {
            throw new RuntimeException('theme.ts declares no THEMES list.');
        }

        preg_match_all("/'([a-z-]+)'/", $matches[1], $names);

        $expected = array_merge(['warm-editorial'], self::ATTRIBUTE_THEMES);
        sort($expected);
        $actual = $names[1];
        sort($actual);

        self::assertSame($expected, $actual, 'theme.ts and the pre-paint script disagree about which themes exist.');
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

    /** The one <script> element that carries the theme logic. */
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

        throw new RuntimeException('No script in the rendered shell reads the theme preference.');
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
