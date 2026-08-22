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
        'warm-editorial' => [
            'canvas' => '#FAF8F5', 'surface' => '#FFFFFF', 'surface-raised' => '#FFFFFF', 'surface-muted' => '#F5F1EC',
            'text' => '#292524', 'text-muted' => '#57534E', 'text-inverse' => '#FFFFFF', 'border' => '#E7E5E4',
            'border-strong' => '#78716C',
            'primary' => '#C2410C', 'primary-hover' => '#9A3412', 'primary-active' => '#7C2D12', 'primary-text' => '#FFFFFF',
            'focus-ring' => '#C2410C', 'link' => '#9A3412',
            'success' => '#166534', 'warning' => '#9A4700', 'danger' => '#B42318', 'info' => '#1D4ED8',
            'status-neutral' => '#57534E',
        ],
        'clean-monochrome' => [
            'canvas' => '#F4F4F5', 'surface' => '#FFFFFF', 'surface-raised' => '#FFFFFF', 'surface-muted' => '#FAFAFA',
            'text' => '#18181B', 'text-muted' => '#52525B', 'text-inverse' => '#FFFFFF', 'border' => '#E4E4E7',
            'border-strong' => '#71717A',
            'primary' => '#2563EB', 'primary-hover' => '#1D4ED8', 'primary-active' => '#1E3A8A', 'primary-text' => '#FFFFFF',
            'focus-ring' => '#2563EB', 'link' => '#1D4ED8',
            'success' => '#166534', 'warning' => '#9A4700', 'danger' => '#B42318', 'info' => '#1D4ED8',
            'status-neutral' => '#52525B',
        ],
        'midnight-obsidian' => [
            'canvas' => '#0B0F19', 'surface' => '#111827', 'surface-raised' => '#1B2437', 'surface-muted' => '#0F1524',
            'text' => '#E0E7FF', 'text-muted' => '#AFBAD4', 'text-inverse' => '#0B0F19', 'border' => '#1F2937',
            'border-strong' => '#6B7280',
            'primary' => '#818CF8', 'primary-hover' => '#A5B4FC', 'primary-active' => '#C7D2FE', 'primary-text' => '#0B0F19',
            'focus-ring' => '#A5B4FC', 'link' => '#A5B4FC',
            'success' => '#5DDB90', 'warning' => '#FFCA6A', 'danger' => '#FF9B91', 'info' => '#7CC4FF',
            'status-neutral' => '#AFBAD4',
        ],
    ];

    /** The CSS selector each theme lives behind (§3.1: Warm Editorial is the default). */
    private const SELECTORS = [
        'warm-editorial' => ':root',
        'clean-monochrome' => "[data-theme='clean-monochrome']",
        'midnight-obsidian' => "[data-theme='midnight-obsidian']",
    ];

    /** §4.2 and §6.6 give these distinct roles. They are elevation, not colour, so contrast does not apply. */
    private const SHADOWS = ['shadow-1', 'shadow-2'];

    /** Foregrounds that render as text (§6.4: status is "icon + text/chip", so a status colour is a text colour). */
    private const TEXT_FOREGROUNDS = ['text', 'text-muted', 'status-neutral', 'link', 'success', 'warning', 'danger', 'info'];

    private const BACKGROUNDS = ['canvas', 'surface', 'surface-raised', 'surface-muted'];

    private const AA_TEXT = 4.5;

    private const AA_INTERACTIVE = 3.0;

    /**
     * Empty, and that is the news.
     *
     * The retired palette carried one pair under 4.5:1 — Odoo's success
     * #15803D on its surface-muted #F1EEF0, measuring 4.35:1 — pinned here
     * while it waited on an owner decision recorded in D-70. D-73 replaced
     * both values: success is #166534 in each light theme, which reads 6.34:1
     * on Warm Editorial's muted surface and 7.13:1 on its surface. The gap is
     * closed rather than inherited.
     *
     * The array stays because the mechanism should outlive the exception. A
     * pinned pair keeps a floor at today's measured value, so a known gap
     * cannot widen unnoticed; deleting the machinery would mean the next one
     * has to be argued for from scratch.
     *
     * A method rather than a constant, and not for style: PHPStan reads an
     * empty constant array as `array{}`, so the lookup below became "offset
     * does not exist" and the null check "always null" — level-10 errors that
     * would have to be suppressed the moment a pin was added back. An explicit
     * `array<string, float>` return keeps the mechanism typed for the case it
     * exists to serve. The same narrowing bit the audit register in Point 6.5.
     *
     * @return array<string, float>
     */
    private static function pinned(): array
    {
        return [];
    }

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
        // §3.1 as D-73 rewrote it: the product default before sign-in is Warm
        // Editorial. A document that has never chosen a theme must already be
        // correct, which is what lets the pre-paint script do nothing in the
        // common case.
        self::assertSame(':root', self::SELECTORS['warm-editorial']);
        self::assertNotSame([], self::declarations('warm-editorial'));
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
        return [
            'warm editorial' => ['warm-editorial'],
            'clean monochrome' => ['clean-monochrome'],
            'midnight obsidian' => ['midnight-obsidian'],
        ];
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

        // D-73's twenty-second token. SC 1.4.11 puts a 3:1 floor under the
        // boundary that *identifies* a control — an input outline, a checkbox
        // edge — and puts none under a table rule. --color-border is the rule;
        // this is the outline, and only this one is held to a ratio.
        foreach (self::BACKGROUNDS as $bg) {
            self::assertContrast($theme, 'border-strong', $bg, $t['border-strong'], $t[$bg], self::AA_INTERACTIVE);
        }
    }

    /**
     * The divider is deliberately *not* held to 3:1, and that has to be said
     * out loud rather than left as an absent assertion.
     *
     * The brief that produced D-73 asked for 3:1 on every border. Measured, the
     * three specified divider colours land at 1.15:1 to 1.30:1, and raising
     * them to 3:1 turns every rule in a data-dense table into a cage. SC 1.4.11
     * does not ask for it: a decorative separator carries no information a
     * user needs to identify a control. So the palette keeps its dividers and
     * gains --color-border-strong for the boundaries that do carry meaning.
     *
     * This asserts the two are actually different, which is the whole reason
     * for having both — a theme that set them equal would silently be choosing
     * one of the two failures.
     */
    #[DataProvider('themes')]
    public function test_the_divider_and_the_control_boundary_are_distinct(string $theme): void
    {
        $t = self::declarations($theme);

        self::assertNotSame($t['border'], $t['border-strong'],
            "Theme '{$theme}' uses one colour for both the divider and the control boundary.");

        // And the strong one is the darker of the pair against its own surface,
        // not merely a different hex.
        self::assertGreaterThan(
            self::ratio($t['border'], $t['surface']),
            self::ratio($t['border-strong'], $t['surface']),
            "Theme '{$theme}' has a control boundary weaker than its divider.",
        );
    }

    // ───────────────────────────────────────────────────────────── mechanics

    private static function assertContrast(string $theme, string $fg, string $bg, string $fgHex, string $bgHex, float $floor): void
    {
        $ratio = self::ratio($fgHex, $bgHex);
        $pin = self::pinned()["{$theme}:{$fg}:{$bg}"] ?? null;
        $required = $pin ?? $floor;

        self::assertGreaterThanOrEqual(
            $required,
            round($ratio, 2),
            sprintf(
                '%s: %s (%s) on %s (%s) is %.2f:1, below the required %.2f:1%s.',
                $theme, $fg, $fgHex, $bg, $bgHex, $ratio, $required,
                $pin === null ? ' (Design System §8, WCAG 2.1 AA)' : ' (pinned — this known gap must not widen)',
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
