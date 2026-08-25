<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Point 4.2 — the inline axis, enforced.
 *
 * Design System §5.1 says "Use logical CSS properties, not left/right-only
 * positioning" and Coding Standards §11 says "Use logical layout properties
 * (start/end) instead of left/right assumptions". Both are rules a reviewer has
 * to remember on every pull request, and the failure they prevent is invisible
 * to anyone reading English: `margin-left: 1rem` renders perfectly, passes every
 * type check, builds without a warning, and is simply wrong in Arabic.
 *
 * That is the whole argument for a machine check. §14.2 makes Arabic a
 * first-release language, so half this product's users see the mirrored layout,
 * and nobody testing in English will ever notice the defect.
 *
 * ── Two surfaces, because there are two ways to write the same mistake ──────
 *
 * A physical property can arrive as CSS (`margin-left: 1rem` in a <style>
 * block) or as a utility class (`ml-4` in a template, which Tailwind compiles
 * into exactly that). Checking only one leaves the other open, and in a
 * Tailwind codebase the class is the likelier of the two.
 *
 * ── The block axis is deliberately not checked ──────────────────────────────
 *
 * `top`, `bottom`, `margin-block-*` and `inset-0` do not mirror: RTL reverses
 * the inline axis only. Forbidding them would be cargo-culting a rule past the
 * point where it means anything.
 *
 * ── What this does not reach ────────────────────────────────────────────────
 *
 * Only files this project owns. Laravel's stock pagination view — declared as a
 * Tailwind source in app.css — uses ml-auto, -ml-px, mr-6, pl-4, pr-2.5 and
 * text-right, and those utilities are already in the bundle. Nothing paginates
 * yet; the first §5.2 table will have to publish and correct that view.
 */
final class LogicalPropertiesTest extends TestCase
{
    /**
     * CSS properties that take a side on the inline axis. A property is
     * forbidden when it is one of these or ends with one of the suffixes below,
     * which is what catches `scroll-margin-left` and `border-top-left-radius`
     * without naming every combination.
     *
     * @var list<string>
     */
    private const PHYSICAL_SUFFIXES = ['-left', '-right', '-left-radius', '-right-radius'];

    /** @var list<string> */
    private const PHYSICAL_PROPERTIES = ['left', 'right'];

    /**
     * Properties whose *value* takes a side.
     *
     * @var array<string, list<string>>
     */
    private const PHYSICAL_VALUES = [
        'text-align' => ['left', 'right'],
        'float' => ['left', 'right'],
        'clear' => ['left', 'right'],
    ];

    /**
     * Tailwind utilities that compile to a physical inline-axis property.
     * Anchored, so `text-[var(--color-text)]` is not mistaken for `text-left`
     * and `ps-3` is not mistaken for `pr-3`.
     *
     * @var list<string>
     */
    private const PHYSICAL_UTILITIES = [
        '/^-?(?:ml|mr|pl|pr)-/',
        '/^-?(?:scroll-m[lr]|scroll-p[lr])-/',
        '/^-?(?:left|right)-/',
        '/^border-[lr](?:-|$)/',
        '/^rounded-(?:[lr]|tl|tr|bl|br)(?:-|$)/',
        '/^(?:text|float|clear|origin|object|bg)-(?:left|right)$/',
    ];

    /** Where this project's own styling lives. */
    private const ROOTS = ['resources/js', 'resources/views', 'resources/css'];

    // ────────────────────────────────────────────── surface 1: CSS declarations

    /** @return array<string, array{0: string}> */
    public static function styledFiles(): array
    {
        return self::provider(['vue', 'css']);
    }

    #[DataProvider('styledFiles')]
    public function test_no_stylesheet_declares_a_physical_inline_property(string $relative): void
    {
        $offences = [];

        foreach (self::declarations(self::read(self::root().'/'.$relative)) as [$property, $value]) {
            $lower = strtolower($property);

            foreach (self::PHYSICAL_SUFFIXES as $suffix) {
                if (str_ends_with($lower, $suffix)) {
                    $offences[] = "{$property}: {$value}";
                }
            }

            if (in_array($lower, self::PHYSICAL_PROPERTIES, true)) {
                $offences[] = "{$property}: {$value}";
            }

            $sides = self::PHYSICAL_VALUES[$lower] ?? null;

            if ($sides !== null && in_array(strtolower(trim($value)), $sides, true)) {
                $offences[] = "{$property}: {$value}";
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($offences)),
            "{$relative} takes a physical side. Use the inline axis — margin-inline-start, "
            .'inset-inline-end, border-inline-start, text-align: start (§5.1, Coding Standards §11).',
        );
    }

    // ─────────────────────────────────────────────── surface 2: utility classes

    /** @return array<string, array{0: string}> */
    public static function markupFiles(): array
    {
        return self::provider(['vue', 'php', 'ts']);
    }

    #[DataProvider('markupFiles')]
    public function test_no_markup_uses_a_physical_utility_class(string $relative): void
    {
        $offences = [];

        foreach (self::classTokens(self::read(self::root().'/'.$relative)) as $token) {
            foreach (self::PHYSICAL_UTILITIES as $pattern) {
                if (preg_match($pattern, $token) === 1) {
                    $offences[] = $token;
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($offences)),
            "{$relative} uses a physical Tailwind utility. The logical forms are ms-/me-, ps-/pe-, "
            .'start-/end-, border-s/border-e, rounded-s/rounded-e and text-start/text-end.',
        );
    }

    // ───────────────────────────────────────── the scan itself must be honest

    public function test_the_scan_covers_the_shell_it_claims_to_cover(): void
    {
        // The same lesson as NoHardCodedTextTest: a scan that quietly stops
        // looking reports success forever. These are the files the shell is
        // made of, so their absence from the scan is the failure.
        $scanned = array_keys(self::provider(['vue', 'css', 'php', 'ts']));

        foreach ([
            'resources/js/App.vue',
            'resources/js/components/AppSidebar.vue',
            'resources/js/components/AppContextBar.vue',
            'resources/js/components/states/EmptyState.vue',
            'resources/js/components/states/ErrorState.vue',
            'resources/js/components/states/LoadingState.vue',
            'resources/js/components/states/PermissionDeniedState.vue',
            'resources/js/pages/auth/LoginView.vue',
            'resources/js/pages/ForbiddenView.vue',
            'resources/js/pages/users/UsersView.vue',
            'resources/js/components/users/UserFormModal.vue',
            'resources/js/components/users/ConfirmDialog.vue',
            'resources/js/components/identity/ImpersonationBanner.vue',
            'resources/js/pages/roles/RolesMatrixView.vue',
            'resources/js/components/roles/PermissionDiffModal.vue',
            'resources/js/pages/profile/AccountSecurityView.vue',
            'resources/js/components/profile/EmailChallengeModal.vue',
            'resources/js/components/users/UserDetailsDrawer.vue',
            'resources/css/app.css',
        ] as $expected) {
            self::assertContains($expected, $scanned, "{$expected} is not being scanned.");
        }
    }

    public function test_every_navigation_item_names_a_registered_route(): void
    {
        // §5.1 forbids showing a link the user cannot use. A route that does not
        // exist is a worse version of the same defect, and vue-router resolves
        // named routes at render time, so a typo surfaces as a runtime warning
        // in a browser nobody is watching.
        // Point 5.1 moved the table out of app.ts into router/index.ts, where
        // the guards live with it. Reading the old file would have left this
        // assertion passing against an empty list forever, which is why it
        // asserts the source is not empty before it asserts anything about it.
        $router = self::read(self::root().'/resources/js/router/index.ts');
        $registered = self::routeNames($router);
        $used = self::routeNames(self::read(self::root().'/resources/js/navigation.ts'));

        self::assertNotEmpty($registered, 'router/index.ts registers no route at all — the scan is looking in the wrong file.');
        self::assertNotEmpty($used, 'navigation.ts names no route at all.');

        foreach ($used as $name) {
            self::assertContains($name, $registered,
                "navigation.ts links to '{$name}', which router/index.ts does not register.");
        }
    }

    public function test_the_sidebar_carries_the_documented_widths(): void
    {
        // §5.1: "Sidebar width: 256px expanded, 72px collapsed." The numbers
        // were right in the markup and wrong on screen — a scoped media query
        // set inline-size: auto and outranked the utility class, so the rail
        // measured 237px in English and 300px in Arabic. Text passes; only a
        // browser saw it. This pins the declarations that produce those widths.
        // Rules only. The first version read the whole file and failed on the
        // comment above that explains the bug — a check that reads its own
        // prose as code, which is the same trap the scanner below avoids.
        $css = self::styleRules(self::read(self::root().'/resources/js/components/AppSidebar.vue'));

        self::assertMatchesRegularExpression('/\.app-sidebar\s*\{[^}]*inline-size:\s*16rem;/s', $css, '§5.1: 256px expanded.');
        self::assertMatchesRegularExpression('/\.app-sidebar--collapsed\s*\{[^}]*inline-size:\s*4\.5rem;/s', $css, '§5.1: 72px collapsed.');
        self::assertStringNotContainsString('inline-size: auto', $css, 'A content-sized rail is not a 256px rail.');
    }

    // ───────────────────────────────────────────────────────────── mechanics

    /**
     * Declarations from a .css file, or from the <style> blocks of a .vue file.
     * A Vue template is not CSS, so scanning the whole file would read `right`
     * out of ordinary prose.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function declarations(string $source): array
    {
        $blocks = [];

        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $source, $matches) === false) {
            throw new RuntimeException('Reading style blocks failed: '.preg_last_error_msg());
        }

        $blocks = $matches[1];

        // A .css file has no <style> wrapper; it is all declarations.
        if ($blocks === [] && ! str_contains($source, '<template')) {
            $blocks = [$source];
        }

        $found = [];

        foreach ($blocks as $block) {
            // Comments first: this file's own explanations name the physical
            // properties it forbids, and a scanner that reads its own prose
            // fails on the sentence describing the rule.
            $stripped = preg_replace('#/\*.*?\*/#s', '', $block);

            if ($stripped === null) {
                throw new RuntimeException('Stripping comments failed: '.preg_last_error_msg());
            }

            if (preg_match_all('/([a-zA-Z-]+)\s*:\s*([^;{}]+)/', $stripped, $pairs, PREG_SET_ORDER) === false) {
                throw new RuntimeException('Reading declarations failed: '.preg_last_error_msg());
            }

            foreach ($pairs as $pair) {
                $found[] = [$pair[1], trim($pair[2])];
            }
        }

        return $found;
    }

    /**
     * Every class token written in a file, with variant prefixes removed so
     * `lg:hover:ml-4` is judged as `ml-4`.
     *
     * @return list<string>
     */
    private static function classTokens(string $source): array
    {
        if (preg_match_all('/(?:^|\s)(?::?[a-z-]*class)\s*=\s*"([^"]*)"/mi', $source, $matches) === false) {
            throw new RuntimeException('Reading class attributes failed: '.preg_last_error_msg());
        }

        $tokens = [];

        foreach ($matches[1] as $attribute) {
            foreach (preg_split('/[\s,\'"\[\]]+/', $attribute) ?: [] as $raw) {
                if ($raw === '') {
                    continue;
                }

                // Strip variants (lg:, hover:, focus-visible:) but leave any
                // arbitrary value bracket alone — it can hold a colon of its own.
                $bracket = strpos($raw, '[');
                $head = $bracket === false ? $raw : substr($raw, 0, $bracket);
                $lastColon = strrpos($head, ':');
                $token = $lastColon === false ? $raw : substr($raw, $lastColon + 1);

                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    /**
     * Route names written as `name: 'x'`.
     *
     * @return list<string>
     */
    private static function routeNames(string $source): array
    {
        if (preg_match_all("/\bname:\s*'([a-z0-9-]+)'/i", $source, $matches) === false) {
            throw new RuntimeException('Reading route names failed: '.preg_last_error_msg());
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Every file under the project's own styling roots with one of the given
     * extensions, keyed by its path relative to the application root.
     *
     * @param  list<string>  $extensions
     * @return array<string, array{0: string}>
     */
    private static function provider(array $extensions): array
    {
        $cases = [];

        foreach (self::ROOTS as $root) {
            $absolute = self::root().'/'.$root;

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! in_array($file->getExtension(), $extensions, true)) {
                    continue;
                }

                $relative = $root.'/'.ltrim(str_replace($absolute, '', $file->getPathname()), '/');
                $cases[$relative] = [$relative];
            }
        }

        ksort($cases);

        return $cases;
    }

    /** The <style> blocks of a single-file component, with comments removed. */
    private static function styleRules(string $source): string
    {
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $source, $matches) === false) {
            throw new RuntimeException('Reading style blocks failed: '.preg_last_error_msg());
        }

        $stripped = preg_replace('#/\*.*?\*/#s', '', implode("\n", $matches[1]));

        if ($stripped === null) {
            throw new RuntimeException('Stripping comments failed: '.preg_last_error_msg());
        }

        return $stripped;
    }

    /**
     * The application root, derived from this file rather than from the
     * container. A PHPUnit data provider runs before Laravel boots, so
     * base_path() is not available where the file list is built — the first
     * version of this test called it there and every provider was reported
     * invalid rather than failing on a physical property.
     */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
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
