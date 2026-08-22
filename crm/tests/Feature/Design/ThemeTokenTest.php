<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Point 4.1a — the semantic token contract and its contrast.
 *
 * Design System §3.2 obliges every component to consume semantic tokens and §8
 * forbids any component from bypassing them, which only means something if the
 * tokens are all present, carry the documented values, and actually pass the
 * contrast §8 requires. All three are asserted here rather than reviewed by eye.
 *
 * Contrast is computed, not judged. §8's first quality gate is "WCAG 2.1 AA
 * contrast for text and controls in all three themes" — a requirement no one can
 * honour by looking at a screen, in three themes, in two directions.
 *
 * ── What this file cannot do, stated rather than implied ───────────────────
 *
 * EXPECTED below is a transcription of §3.3 (with D-70), not a reading of it.
 * docs/ sits outside the application root and is mounted into neither the
 * container nor CI, which mount crm/ alone — verified, not assumed. So this
 * binds tokens.css to the transcription; it cannot notice the specification
 * changing underneath. Mounting docs/ read-only would close that gap and is a
 * change to compose and CI, which is wider than this point.
 */
final class ThemeTokenTest extends TestCase
{
    /**
     * §3.3 plus D-70, transcribed. Key order is the §3.2 contract order.
     *
     * @var array<string, array<string, string>>
     */
    private const EXPECTED = [
        'odoo' => [
            'canvas' => '#F8F8F9', 'surface' => '#FFFFFF', 'surface-raised' => '#FFFFFF', 'surface-muted' => '#F1EEF0',
            'text' => '#2E2C2D', 'text-muted' => '#625C61', 'text-inverse' => '#FFFFFF', 'border' => '#D9D3D7',
            'primary' => '#714B67', 'primary-hover' => '#5C3D54', 'primary-active' => '#472F41', 'primary-text' => '#FFFFFF',
            'focus-ring' => '#2563EB', 'link' => '#5C3D54',
            'success' => '#15803D', 'warning' => '#A65300', 'danger' => '#B42318', 'info' => '#1D4ED8',
            'status-neutral' => '#625C61',
        ],
        'clean-white' => [
            'canvas' => '#FFFFFF', 'surface' => '#FFFFFF', 'surface-raised' => '#FFFFFF', 'surface-muted' => '#F6F8FB',
            'text' => '#172033', 'text-muted' => '#5E6B82', 'text-inverse' => '#FFFFFF', 'border' => '#D8E0EB',
            'primary' => '#1D4ED8', 'primary-hover' => '#1E40AF', 'primary-active' => '#1F3286', 'primary-text' => '#FFFFFF',
            'focus-ring' => '#1D4ED8', 'link' => '#1D4ED8',
            'success' => '#15803D', 'warning' => '#A65300', 'danger' => '#B42318', 'info' => '#1D4ED8',
            'status-neutral' => '#5E6B82',
        ],
        'dark-blue' => [
            'canvas' => '#081A33', 'surface' => '#102B4C', 'surface-raised' => '#16385F', 'surface-muted' => '#0D2442',
            'text' => '#F5F9FF', 'text-muted' => '#B9C8DC', 'text-inverse' => '#08203D', 'border' => '#315579',
            'primary' => '#66B2FF', 'primary-hover' => '#9BCBFF', 'primary-active' => '#D0E4FF', 'primary-text' => '#08203D',
            'focus-ring' => '#A8D6FF', 'link' => '#A8D6FF',
            'success' => '#5DDB90', 'warning' => '#FFCA6A', 'danger' => '#FF9B91', 'info' => '#7CC4FF',
            'status-neutral' => '#B9C8DC',
        ],
    ];

    /** The CSS selector each theme is expected to live behind (§3.1: Odoo is the default). */
    private const SELECTORS = [
        'odoo' => ':root',
        'clean-white' => "[data-theme='clean-white']",
        'dark-blue' => "[data-theme='dark-blue']",
    ];

    /** §4.2 and §6.6 give these distinct roles. They are elevation, not colour, so contrast does not apply. */
    private const SHADOWS = ['shadow-1', 'shadow-2'];

    /** Foregrounds that render as text (§6.4: status is "icon + text/chip", so a status colour is a text colour). */
    private const TEXT_FOREGROUNDS = ['text', 'text-muted', 'status-neutral', 'link', 'success', 'warning', 'danger', 'info'];

    private const BACKGROUNDS = ['canvas', 'surface', 'surface-raised', 'surface-muted'];

    private const AA_TEXT = 4.5;

    private const AA_INTERACTIVE = 3.0;

    /**
     * One pair in the documented palette does not reach 4.5:1, and it is pinned
     * here at its measured value rather than quietly dropped from the matrix.
     *
     * Odoo's success #15803D on its surface-muted #F1EEF0 measures 4.35:1. Both
     * values predate D-70 and neither was changed by it; closing the gap means
     * darkening success, lightening surface-muted, or ruling that a status chip
     * never sits on a muted surface — an owner decision, recorded in D-70.
     *
     * Pinning is not an exemption. The pair still has a floor, it simply has
     * today's floor, so the gap cannot widen unnoticed while it waits.
     *
     * @var array<string, float>
     */
    private const PINNED = [
        'odoo:success:surface-muted' => 4.35,
    ];

    // ───────────────────────────────────── the contract: complete, exact, closed

    public function test_the_stylesheet_defines_exactly_the_documented_colour_tokens(): void
    {
        foreach (self::EXPECTED as $theme => $expected) {
            $actual = array_filter(
                self::declarations($theme),
                static fn (string $name): bool => ! in_array($name, self::SHADOWS, true),
                ARRAY_FILTER_USE_KEY,
            );

            // assertSame on the whole map at once, so a missing token, an extra
            // token and a wrong value are all one assertion and all report the
            // name. Three separate loops would have let a typo'd name pass as
            // "not missing" while the real one was absent.
            ksort($expected);
            ksort($actual);

            self::assertSame($expected, $actual, "Theme '{$theme}' does not match §3.3 and D-70.");
        }
    }

    public function test_every_theme_defines_both_elevation_tokens(): void
    {
        foreach (array_keys(self::EXPECTED) as $theme) {
            $declared = self::declarations($theme);

            foreach (self::SHADOWS as $shadow) {
                self::assertArrayHasKey($shadow, $declared, "Theme '{$theme}' is missing --{$shadow} (§4.2, §6.6, D-70).");
                self::assertStringContainsString('rgb(', $declared[$shadow]);
            }
        }
    }

    public function test_the_default_theme_needs_no_attribute(): void
    {
        // §3.1: "before sign-in, use the product default Odoo-inspired". A
        // document that has never chosen a theme must already be correct, which
        // is what lets the pre-paint script in 4.3 do nothing in the common case.
        self::assertSame(':root', self::SELECTORS['odoo']);
        self::assertNotSame([], self::declarations('odoo'));
    }

    public function test_no_component_stylesheet_hard_codes_a_colour(): void
    {
        // §8: "No component hard-codes a color, radius, spacing, shadow, or
        // direction that bypasses tokens." tokens.css is where the hex values
        // are allowed to be; app.css is a component stylesheet like any other.
        $app = self::read(base_path('resources/css/app.css'));

        $found = preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $app, $matches);

        if ($found === false) {
            throw new RuntimeException('Scanning app.css failed: '.preg_last_error_msg());
        }

        self::assertSame([], $matches[0], 'app.css must consume tokens, not hex values (§3.2, §8).');
    }

    // ─────────────────────────────────────────────────── contrast, computed

    /** @return array<string, array{0: string}> */
    public static function themes(): array
    {
        return ['odoo' => ['odoo'], 'clean white' => ['clean-white'], 'dark blue' => ['dark-blue']];
    }

    #[DataProvider('themes')]
    public function test_text_meets_wcag_aa_on_every_surface(string $theme): void
    {
        // Read the stylesheet, not the transcription. Contrast has to be a
        // property of what ships; asserting it against EXPECTED would only
        // prove the documented palette is sound while a wrong hex in
        // tokens.css sailed past this gate to be caught somewhere else.
        $t = self::declarations($theme);

        foreach (self::TEXT_FOREGROUNDS as $fg) {
            foreach (self::BACKGROUNDS as $bg) {
                self::assertContrast($theme, $fg, $bg, $t[$fg], $t[$bg], self::AA_TEXT);
            }
        }
    }

    #[DataProvider('themes')]
    public function test_text_on_a_filled_primary_meets_wcag_aa(string $theme): void
    {
        // Read the stylesheet, not the transcription. Contrast has to be a
        // property of what ships; asserting it against EXPECTED would only
        // prove the documented palette is sound while a wrong hex in
        // tokens.css sailed past this gate to be caught somewhere else.
        $t = self::declarations($theme);

        // §6.2 gives every button default, hover, active, focus, disabled and
        // loading states. The label has to stay readable through all of them,
        // and the active state is D-70's derivation — so this is the assertion
        // that the derivation is not merely plausible.
        foreach (['primary', 'primary-hover', 'primary-active'] as $bg) {
            self::assertContrast($theme, 'primary-text', $bg, $t['primary-text'], $t[$bg], self::AA_TEXT);
        }

        self::assertContrast($theme, 'text-inverse', 'text', $t['text-inverse'], $t['text'], self::AA_TEXT);
    }

    #[DataProvider('themes')]
    public function test_focus_and_primary_meet_the_non_text_threshold(string $theme): void
    {
        // Read the stylesheet, not the transcription. Contrast has to be a
        // property of what ships; asserting it against EXPECTED would only
        // prove the documented palette is sound while a wrong hex in
        // tokens.css sailed past this gate to be caught somewhere else.
        $t = self::declarations($theme);

        // §6.1: "Every interactive element has visible keyboard focus using
        // focus-ring." A focus ring that does not reach 3:1 against the surface
        // behind it is not visible, whatever the stylesheet claims.
        foreach (['canvas', 'surface', 'surface-raised', 'surface-muted'] as $bg) {
            self::assertContrast($theme, 'focus-ring', $bg, $t['focus-ring'], $t[$bg], self::AA_INTERACTIVE);
        }

        foreach (['canvas', 'surface'] as $bg) {
            self::assertContrast($theme, 'primary', $bg, $t['primary'], $t[$bg], self::AA_INTERACTIVE);
        }
    }

    // ───────────────────────────────────────────────────────────── mechanics

    private static function assertContrast(string $theme, string $fg, string $bg, string $fgHex, string $bgHex, float $floor): void
    {
        $ratio = self::ratio($fgHex, $bgHex);
        $pin = self::PINNED["{$theme}:{$fg}:{$bg}"] ?? null;
        $required = $pin ?? $floor;

        self::assertGreaterThanOrEqual(
            $required,
            round($ratio, 2),
            sprintf(
                '%s: %s (%s) on %s (%s) is %.2f:1, below the required %.2f:1%s.',
                $theme, $fg, $fgHex, $bg, $bgHex, $ratio, $required,
                $pin === null ? ' (Design System §8, WCAG 2.1 AA)' : ' (pinned by D-70 — this gap must not widen)',
            ),
        );
    }

    /** WCAG 2.1, relative luminance and the (L1 + 0.05) / (L2 + 0.05) contrast ratio. */
    private static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return $la > $lb
            ? ($la + 0.05) / ($lb + 0.05)
            : ($lb + 0.05) / ($la + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6) {
            throw new RuntimeException("Not a six-digit hex colour: {$hex}");
        }

        $channels = [];

        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * The custom properties declared in one theme's block, with the --color-
     * prefix removed so the keys read as the §3.2 contract does.
     *
     * @return array<string, string>
     */
    private static function declarations(string $theme): array
    {
        $css = self::read(base_path('resources/css/tokens.css'));
        $selector = self::SELECTORS[$theme];

        $blockStart = strpos($css, $selector.' {');

        if ($blockStart === false) {
            throw new RuntimeException("tokens.css has no block for {$selector}.");
        }

        $open = strpos($css, '{', $blockStart);
        $close = strpos($css, '}', $blockStart);

        if ($open === false || $close === false) {
            throw new RuntimeException("The {$selector} block in tokens.css is not closed.");
        }

        $body = substr($css, $open + 1, $close - $open - 1);

        if (preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/', $body, $matches) === false) {
            throw new RuntimeException('Reading declarations failed: '.preg_last_error_msg());
        }

        $declared = [];

        foreach ($matches[1] as $index => $name) {
            $value = trim($matches[2][$index] ?? '');
            $declared[str_starts_with($name, 'color-') ? substr($name, 6) : $name] = $value;
        }

        return $declared;
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
