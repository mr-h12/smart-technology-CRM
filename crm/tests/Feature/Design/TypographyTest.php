<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Point 4.1b — the typography of §4.1, and the numerals D-70 fixes.
 *
 * §4.1 is a table of families, weights and sizes. A table is not enforcement:
 * every claim it makes is one a stylesheet can quietly stop honouring, and the
 * two failures that matter most are silent by construction.
 *
 * The first is a missing face. A browser asked for a weight it has no file for
 * synthesises one — a fake bold, a fake slant — and renders something that
 * looks almost right, with no console error and no failed request. Only a
 * matrix of family × weight catches that, which is what test_the_documented_
 * faces_are_all_declared is.
 *
 * The second is the stack. D-70 fixes Western 0-9 in every locale and delivers
 * them from Inter; if Noto Sans Arabic were listed first, a browser with both
 * families would be free to draw digits from whichever owns the glyph. The
 * subset files make that impossible today — see below — but the order states
 * the intent, and intent that nothing asserts is a comment.
 *
 * ── What holds the numerals up, measured ───────────────────────────────────
 *
 * resources/fonts/README.md records a fontTools reading of these exact files:
 * the Arabic subset carries no Western digits at all, and the Latin subsets
 * carry no Arabic-Indic ones. So 0-9 can only be drawn by Inter, whatever the
 * order says. That measurement is a property of specific bytes, so this file
 * pins those bytes by checksum rather than trusting the filenames — replace a
 * face with a fuller subset and the measurement stops applying, which is
 * exactly the change that would otherwise pass unnoticed.
 *
 * ── What this file cannot do ───────────────────────────────────────────────
 *
 * It reads stylesheets. It does not run a browser, so it cannot prove a glyph
 * reached a screen, that Arabic shaped correctly, or that the swap in
 * font-display was brief. Those need the visual pass in 4.2.
 */
final class TypographyTest extends TestCase
{
    /** §4.1: "English / numbers" to Inter, Arabic to Noto Sans Arabic. Order is D-70's mechanism. */
    private const SANS_STACK = ['Inter', 'Noto Sans Arabic', 'sans-serif'];

    /** §4.1: codes, IDs and audit metadata only. */
    private const MONO_STACK = ['Noto Sans Mono', 'monospace'];

    /**
     * §4.1's weights: 400 body, 500 labels, 600 headings/actions, 700 key totals
     * only. Monospace never carries a heading, so it stops at 500.
     *
     * @var array<string, list<int>>
     */
    private const FACES = [
        'Inter' => [400, 500, 600, 700],
        'Noto Sans Arabic' => [400, 500, 600, 700],
        'Noto Sans Mono' => [400, 500],
    ];

    /**
     * §4.1's scale. Body 14/1.55, compact table 13/1.45, page title 24/1.3/600,
     * section title 18/1.35/600, card title 16/1.4/600.
     *
     * @var array<string, array<string, string>>
     */
    private const SCALE = [
        'body' => ['' => '14px', '--line-height' => '1.55'],
        'table' => ['' => '13px', '--line-height' => '1.45'],
        'page-title' => ['' => '24px', '--line-height' => '1.3', '--font-weight' => '600'],
        'section-title' => ['' => '18px', '--line-height' => '1.35', '--font-weight' => '600'],
        'card-title' => ['' => '16px', '--line-height' => '1.4', '--font-weight' => '600'],
    ];

    /** Everything §4.1 permits, plus the two generics a stack is allowed to end on. */
    private const ALLOWED_FAMILIES = ['Inter', 'Noto Sans Arabic', 'Noto Sans Mono', 'sans-serif', 'monospace'];

    private const STYLESHEETS = ['app.css', 'fonts.css', 'tokens.css'];

    // ──────────────────────────────────────────────── the stack, and its order

    public function test_the_sans_stack_puts_inter_before_noto_sans_arabic(): void
    {
        // D-70. Every locale emits Western 0-9 and the first family in the stack
        // that owns those glyphs draws them, so this order is not cosmetic. It
        // is also the §4.1 split — English and numbers to Inter, Arabic to Noto
        // Sans Arabic — written as one stack the browser resolves per character
        // rather than as two stylesheets that would have to be kept in step.
        self::assertSame(self::SANS_STACK, self::families('--font-sans'));
    }

    public function test_the_sans_stack_ends_on_a_generic(): void
    {
        // The last resort when a face fails to arrive. Without it a failed font
        // request is a page with no text on it.
        $stack = self::families('--font-sans');

        self::assertSame('sans-serif', end($stack));
    }

    public function test_the_mono_stack_is_the_documented_one(): void
    {
        self::assertSame(self::MONO_STACK, self::families('--font-mono'));
    }

    public function test_no_stylesheet_names_a_family_outside_section_4_1(): void
    {
        // An allowlist, not a search for one bad name. §4.1 names three families
        // and §8 forbids a component bypassing the system; a denylist would have
        // to predict which font someone reaches for next.
        foreach (self::STYLESHEETS as $file) {
            foreach (self::familiesNamedIn($file) as $family) {
                self::assertContains(
                    $family,
                    self::ALLOWED_FAMILIES,
                    "{$file} names '{$family}', which Design System §4.1 does not.",
                );
            }
        }
    }

    public function test_the_skeleton_typeface_is_gone(): void
    {
        // The Laravel skeleton shipped Instrument Sans, fetched from bunny.net
        // at build time. §1 puts this system on company premises, and §4.1 names
        // three other families. Both the stylesheets and the build config are
        // checked: a leftover in either is a font that can still reach a screen.
        $files = ['vite.config.ts'];

        foreach (self::STYLESHEETS as $stylesheet) {
            $files[] = 'resources/css/'.$stylesheet;
        }

        foreach ($files as $file) {
            self::assertStringNotContainsStringIgnoringCase(
                'Instrument',
                self::read(base_path($file)),
                "{$file} still mentions the skeleton's typeface.",
            );
        }
    }

    // ─────────────────────────────────────────────────── the faces themselves

    public function test_the_documented_faces_are_all_declared(): void
    {
        $declared = [];

        foreach (self::faces() as $face) {
            $declared[$face['font-family']][] = (int) $face['font-weight'];
        }

        foreach ($declared as $family => $weights) {
            sort($weights);
            $declared[$family] = $weights;
        }

        $expected = self::FACES;
        ksort($expected);
        ksort($declared);

        // One assertion over the whole matrix: a missing weight, a surplus one
        // and a misspelled family all report by name. A browser asked for a
        // weight it has no file for synthesises it silently, so "600 is missing"
        // is a defect nothing else in this project would notice.
        self::assertSame($expected, $declared, 'The declared faces do not match §4.1.');
    }

    public function test_every_face_swaps_rather_than_hiding_text(): void
    {
        // font-display's default is `auto`, which most browsers treat as
        // `block`: up to three seconds of invisible text. §7's flows are data
        // entry, and a blank form is not a state this product may have.
        foreach (self::faces() as $face) {
            self::assertArrayHasKey('font-display', $face, "A face for {$face['font-family']} has no font-display.");
            self::assertSame('swap', $face['font-display']);
        }
    }

    public function test_no_face_is_italic(): void
    {
        // §4.1 names no italic, and Arabic has no italic tradition. Declaring a
        // family with no italic lets the browser synthesise a slant, which looks
        // worse than not offering one — so none is offered.
        foreach (self::faces() as $face) {
            self::assertSame('normal', $face['font-style']);
        }
    }

    public function test_every_declared_face_points_at_a_real_woff2(): void
    {
        foreach (self::faces() as $face) {
            $url = $face['src'];
            $path = realpath(base_path('resources/css/').'/'.$url);

            self::assertIsString($path, "{$face['font-family']} {$face['font-weight']} points at {$url}, which does not exist.");

            // A typo'd filename is a 404 the page recovers from by falling back
            // to a system font — the exact failure this whole file exists to
            // prevent, and one no CSS parser would flag.
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException("Could not open {$path}.");
            }

            $magic = fread($handle, 4);
            fclose($handle);

            self::assertSame('wOF2', $magic, "{$url} is not a woff2 file.");
        }
    }

    public function test_the_shipped_faces_are_the_bytes_whose_coverage_was_measured(): void
    {
        // D-70's numerals rest on a fontTools reading of specific files: the
        // Arabic subset carries no Western digits, so 0-9 can only come from
        // Inter. That is a fact about bytes, not about filenames. The same
        // package publishes a `latin` subset of Noto Sans Arabic which does
        // carry 0-9 — swapping it in keeps every filename plausible and
        // silently makes stack order the only thing preventing two digit shapes
        // on one screen. This pins the bytes so that swap cannot be quiet.
        $manifest = self::checksums();

        self::assertCount(10, $manifest, 'resources/fonts/README.md should list all ten faces.');

        foreach ($manifest as $name => $expected) {
            $path = base_path('resources/fonts/'.$name);

            self::assertFileExists($path);
            self::assertSame($expected, hash_file('sha256', $path), "{$name} is not the file whose coverage was measured.");
        }
    }

    // ──────────────────────────────────────────────────── the scale, and numerals

    /** @return array<string, array{0: string}> */
    public static function scaleNames(): array
    {
        $cases = [];

        foreach (array_keys(self::SCALE) as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    #[DataProvider('scaleNames')]
    public function test_the_documented_scale_is_defined(string $name): void
    {
        $css = self::read(base_path('resources/css/app.css'));

        foreach (self::SCALE[$name] as $suffix => $value) {
            $property = "--text-{$name}{$suffix}";

            self::assertMatchesRegularExpression(
                '/'.preg_quote($property, '/').':\s*'.preg_quote($value, '/').';/',
                $css,
                "{$property} is not {$value} (Design System §4.1).",
            );
        }
    }

    public function test_the_scale_survives_the_build(): void
    {
        // Found by building it, not by reading the docs. Tailwind v4 tree-shakes
        // theme variables nothing references, and only --text-body was
        // referenced — so --text-page-title and the rest compiled away entirely,
        // leaving any future `var(--text-page-title)` resolving to nothing.
        // `@theme static` is what keeps them in the output.
        self::assertMatchesRegularExpression(
            '/@theme\s+static\s*\{/',
            self::read(base_path('resources/css/app.css')),
            'Without `static`, Tailwind drops the scale variables no rule happens to use.',
        );
    }

    public function test_numerals_are_tabular_at_the_root(): void
    {
        // §4.1: tabular numerals for money, counts, codes and dates — which is
        // most of a CRM. Set once at the root rather than per component: a rule
        // that must be remembered on every table cell is one that will be
        // forgotten on one, and proportional digits make a money column ragged.
        self::assertMatchesRegularExpression(
            '/html\s*\{[^}]*font-variant-numeric:\s*tabular-nums;/',
            self::read(base_path('resources/css/app.css')),
            'tabular-nums is not applied at the root (§4.1).',
        );
    }

    public function test_the_root_uses_the_documented_stack_and_the_body_the_documented_size(): void
    {
        $css = self::read(base_path('resources/css/app.css'));

        self::assertMatchesRegularExpression('/html\s*\{[^}]*font-family:\s*var\(--font-sans\);/', $css);

        // The base size sits on body, not html, on purpose: Tailwind's spacing
        // scale is rem-based, so moving the root to 14px would shrink every
        // padding and gap in §4.2 by an eighth without touching §4.2.
        self::assertMatchesRegularExpression('/body\s*\{[^}]*font-size:\s*var\(--text-body\);/', $css);
        self::assertMatchesRegularExpression('/body\s*\{[^}]*line-height:\s*var\(--text-body--line-height\);/', $css);
    }

    // ───────────────────────────────────────────────────────────── mechanics

    /**
     * One font stack, as an ordered list of family names with quotes stripped.
     *
     * @return list<string>
     */
    private static function families(string $property): array
    {
        $css = self::read(base_path('resources/css/app.css'));

        if (preg_match('/'.preg_quote($property, '/').':\s*([^;]+);/', $css, $matches) !== 1) {
            throw new RuntimeException("app.css does not declare {$property}.");
        }

        // explode returns a list and array_map preserves its keys, so there is
        // nothing for array_values to do here — level 10 says so, and a dead
        // defensive call is a claim about the data that is not true.
        return array_map(
            static fn (string $name): string => trim(trim($name), "'\""),
            explode(',', $matches[1]),
        );
    }

    /**
     * Every family named anywhere in one stylesheet — in a font-family
     * declaration, in an @font-face, or in a --font-* custom property.
     *
     * @return list<string>
     */
    private static function familiesNamedIn(string $file): array
    {
        $css = self::read(base_path('resources/css/'.$file));

        if (preg_match_all('/(?:^|[;{\s])(?:--font-[a-z-]+|font-family)\s*:\s*([^;]+);/mi', $css, $matches) === false) {
            throw new RuntimeException('Scanning '.$file.' failed: '.preg_last_error_msg());
        }

        $found = [];

        foreach ($matches[1] as $stack) {
            foreach (explode(',', $stack) as $name) {
                $name = trim(trim($name), "'\"");

                // var(--font-sans) is a reference to a stack checked elsewhere,
                // not a family name of its own.
                if ($name !== '' && ! str_starts_with($name, 'var(')) {
                    $found[] = $name;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Every @font-face rule in fonts.css, as a map of its declarations.
     *
     * @return list<array<string, string>>
     */
    private static function faces(): array
    {
        $css = self::read(base_path('resources/css/fonts.css'));

        if (preg_match_all('/@font-face\s*\{([^}]*)\}/', $css, $blocks) === false) {
            throw new RuntimeException('Scanning fonts.css failed: '.preg_last_error_msg());
        }

        $faces = [];

        foreach ($blocks[1] as $block) {
            if (preg_match_all('/([a-z-]+)\s*:\s*([^;]+);/i', $block, $declarations) === false) {
                throw new RuntimeException('Reading an @font-face block failed: '.preg_last_error_msg());
            }

            $face = [];

            foreach ($declarations[1] as $index => $property) {
                $value = trim($declarations[2][$index]);

                // src carries `url('…') format('woff2')`; only the path is of
                // interest, and it is the thing that can be wrong.
                if ($property === 'src' && preg_match("/url\(['\"]?([^'\")]+)['\"]?\)/", $value, $url) === 1) {
                    $value = $url[1];
                }

                $face[strtolower($property)] = trim($value, "'\"");
            }

            $faces[] = $face;
        }

        if ($faces === []) {
            throw new RuntimeException('fonts.css declares no @font-face at all.');
        }

        return $faces;
    }

    /**
     * The checksum manifest in resources/fonts/README.md.
     *
     * @return array<string, string>
     */
    private static function checksums(): array
    {
        $readme = self::read(base_path('resources/fonts/README.md'));

        if (preg_match_all('/^([0-9a-f]{64})\s+(\S+\.woff2)$/m', $readme, $matches, PREG_SET_ORDER) === false) {
            throw new RuntimeException('Reading the checksum manifest failed: '.preg_last_error_msg());
        }

        $manifest = [];

        foreach ($matches as $line) {
            $manifest[$line[2]] = $line[1];
        }

        return $manifest;
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
