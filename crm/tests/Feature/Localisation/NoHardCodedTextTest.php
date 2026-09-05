<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tests\TestCase;

/**
 * Point 3.3 — a check that FAILS when a user-facing string is written into the
 * source instead of a lang file.
 *
 * Coding Standards §11: "Do not hard-code colors, spacing, radii, shadows,
 * direction, or user-facing strings in feature code." MVP_Build_Plan §2,
 * Module 0: "i18n layer from day one — no hard-coded strings". CLAUDE.md: "All
 * user-facing text lives in lang files. No string literals in Blade,
 * controllers, or components."
 *
 * Nothing enforced that. vue-tsc checks types, pint checks formatting, phpstan
 * checks types, and SpaShellTest checks the rendered output of `/` — which is
 * `<div id="app"></div>`, because the SPA mounts client-side. Every string in
 * every .vue file was invisible to all four.
 *
 * ── Why /u appears on every pattern ────────────────────────────────────────
 *
 * Without it this guard sees English and is blind to Arabic, which in an
 * Arabic-first system is worse than no guard at all. م (U+0645) encodes as
 * D9 85, and PCRE in byte mode reads that trailing 0x85 as NEL — a line break
 * for \R. The Arabic is torn into invalid UTF-8 fragments and \p{L} then
 * returns false rather than matching, silently. Found by running it, not by
 * reading it, and every negative case below exists in both languages so it
 * cannot come back.
 */
final class NoHardCodedTextTest extends TestCase
{
    /**
     * Attributes a user reads. A ":" or "v-bind:" prefix means the value is an
     * expression, not a literal, so the lookbehind excludes it — and excludes
     * `data-title` and friends at the same time.
     */
    private const VISIBLE_ATTRIBUTES = [
        'title', 'placeholder', 'alt', 'aria-label',
        'aria-placeholder', 'aria-roledescription', 'aria-valuetext', 'label',
    ];

    /** Calls whose string arguments are already translated and must not be flagged. */
    private const TRANSLATOR_CALLS = ['__', 'trans', 'trans_choice', 'Lang::get', '\\Lang::get'];

    /** PHP helpers that put a message in front of a user. */
    private const ABORT_CALLS = ['abort', 'abort_if', 'abort_unless'];

    // ─────────────────────────────────────────── layer 1: the real source tree

    public function test_no_vue_template_carries_a_hard_coded_string(): void
    {
        $files = self::sourceFiles(base_path('resources/js'), 'vue');

        // Assert what was found, not only what it said. The first version of
        // this scan used glob(a) + glob(b); PHP's array union merges by key, so
        // [0 => Ping.vue] + [0 => App.vue] silently dropped App.vue and the
        // run still reported success. A count is what makes under-scanning
        // visible.
        self::assertNotEmpty($files, 'No .vue files were scanned — the scan is not looking where the source is.');
        self::assertSame(
            [
                'AccountSecurityView.vue', 'App.vue', 'AppContextBar.vue', 'AppSidebar.vue',
                // Module 4 Point 4.4 — §7.3's catalog add/edit form. Added on
                // the same terms: the scan below was run against it first and
                // passed. Its labels are looked up by the *field name* —
                // `catalog.column.{field}` — so the bare strings in the source
                // are column keys and stored `kind` codes, never a sentence.
                'CatalogItemFormModal.vue',
                // Module 4 Point 4.3 — §8's Catalog screen. Added on the same
                // terms as every entry here: the scan below was run against it
                // first and passed. §7.3's two tabs, its per-tab column names
                // and the "no company" group heading are all dictionary keys;
                // the only bare strings in the template are the stored `kind`
                // codes and the column *keys* used to look a label up.
                'CatalogView.vue',
                'ConfirmDialog.vue',
                // Module 2 Point 5.2 — §13 screen 5. Added after the scan below
                // passed on it, on the same terms as the entry below.
                'CurrenciesView.vue',
                // Module 3 Points 4.4 and 4.3 — the detail page and the
                // add/edit form, on the same terms: added only after the scan
                // below passed on each.
                'CustomerDetailView.vue',
                'CustomerFormModal.vue',
                'CustomerImportModal.vue',
                'CustomersView.vue',
                'EmailChallengeModal.vue', 'EmptyState.vue', 'ErrorState.vue',
                'ForbiddenView.vue',
                'ImpersonationBanner.vue', 'LoadingState.vue', 'LoginView.vue',
                // Module 2 Point 5.4 — `DB-05`'s managed lists, on the same
                // terms: added after the scan below passed on it.
                'ManagedListsView.vue',
                'PermissionDeniedState.vue', 'PermissionDiffModal.vue', 'Ping.vue',
                'RoleFormModal.vue', 'RolesMatrixView.vue',
                // Module 4 Point 4.2 — §7.1's supplier add/edit form. Added on
                // the same terms as every entry here: the scan below was run
                // against it first and passed. Its two closed sets are the same
                // stored codes the list screen holds, and every label beside
                // them — including the form-level refusals, which are keys and
                // not the server's own sentence — comes from `suppliers.form.*`
                // in both dictionaries.
                'SupplierFormModal.vue',
                // Module 4 Point 4.0 — §7.1's supplier rating chip. Added on
                // the same terms as every entry here: the scan below was run
                // against it first and passed. The file is the one place in the
                // SPA that had a real temptation to hold a literal — Design
                // System §6.4's "never color alone" means the chip must render
                // a word — and the word comes from `suppliers.rating.*` in both
                // dictionaries rather than from the template.
                // Module 6 Point 6.2 — §8's Supplier Quotations screen. Added
                // on the same terms as every entry here: the scan below was run
                // against it first and passed. Its only bare strings are the
                // sort indicators, which are `aria-hidden` glyphs, and the
                // em dash standing in for an absent date.
                'SupplierQuotationsView.vue',
                'SupplierRatingChip.vue',
                // Module 4 Point 4.1 — §8's Suppliers screen. Added on the same
                // terms: the scan below was run against it first and passed.
                // Its two closed sets — §7.1's four colours and two types —
                // are stored codes in the template and localised words in the
                // dictionaries, which is why the codes appear in the source and
                // no sentence does.
                'SuppliersView.vue',
                // Module 2 Point 5.3 — §13 screen 6, on the same terms.
                'SystemLimitsView.vue',
                // Module 2 Point 5.1 — §13 screen 4. Added to this list only
                // after the scan below passed on it: the list is an inventory,
                // not a suppression, and the message above says so.
                'SystemSettingsView.vue',
                'UserDetailsDrawer.vue', 'UserFormModal.vue', 'UsersView.vue',
            ],
            self::basenames($files),
            'The set of scanned Vue files changed. Confirm the new file is covered rather than adjusting this list blindly.',
        );

        foreach ($files as $path) {
            self::assertSame([], self::scanVue(self::read($path)), self::report($path));
        }
    }

    public function test_no_blade_view_carries_a_hard_coded_string(): void
    {
        $files = self::sourceFiles(base_path('resources/views'), 'php');

        self::assertNotEmpty($files, 'No Blade views were scanned.');
        self::assertSame(['welcome.blade.php'], self::basenames($files));

        foreach ($files as $path) {
            self::assertSame([], self::scanBlade(self::read($path)), self::report($path));
        }
    }

    public function test_no_php_source_puts_a_literal_in_front_of_a_user(): void
    {
        $files = array_merge(
            self::sourceFiles(base_path('app'), 'php'),
            self::sourceFiles(base_path('routes'), 'php'),
        );

        self::assertNotEmpty($files, 'No PHP sources were scanned.');

        foreach ($files as $path) {
            self::assertSame([], self::scanPhp(self::read($path)), self::report($path));
        }
    }

    // ──────────────────────────── layer 2: negative — it must catch a violation

    /** @return array<string, array{0: string}> */
    public static function hardCodedTemplates(): array
    {
        return [
            'english text node' => ['<template><h1>Dashboard</h1></template>'],
            'arabic text node' => ['<template><h1>لوحة التحكم</h1></template>'],
            // Contains م (U+0645) — the letter whose trailing byte is 0x85 and
            // the exact case a missing /u lets through.
            'arabic text with meem' => ['<template><p>مرحبا</p></template>'],
            'english beside an interpolation' => ['<template><p>Total: {{ n }}</p></template>'],
            'arabic beside an interpolation' => ['<template><p>الإجمالي: {{ n }}</p></template>'],
            'english placeholder' => ['<template><input placeholder="Search customers"></template>'],
            'arabic placeholder' => ['<template><input placeholder="ابحث عن العملاء"></template>'],
            'english aria-label' => ['<template><nav aria-label="Main menu"></nav></template>'],
            'arabic aria-label' => ['<template><nav aria-label="القائمة الرئيسية"></nav></template>'],
            'english title attribute' => ['<template><a title="Open the deal">{{ t(\'a\') }}</a></template>'],
            'english alt text' => ['<template><img alt="Company logo" src="a.png"></template>'],
            'arabic aria-roledescription' => ['<template><div aria-roledescription="شريط تمرير"></div></template>'],
            'text inside a nested template' => ['<template><div><template v-if="x">Saved</template></div></template>'],
        ];
    }

    #[DataProvider('hardCodedTemplates')]
    public function test_a_hard_coded_vue_string_is_caught(string $markup): void
    {
        self::assertNotSame([], self::scanVue($markup), "This markup must be reported: {$markup}");
    }

    /** @return array<string, array{0: string}> */
    public static function hardCodedBlade(): array
    {
        return [
            'english text' => ['<html><body><p>Access denied</p></body></html>'],
            'arabic text' => ['<html><body><p>تم الحفظ</p></body></html>'],
            'arabic text with meem' => ['<div>غير مسموح</div>'],
            'english placeholder' => ['<input placeholder="Enter your email">'],
            'arabic placeholder' => ['<input placeholder="أدخل بريدك">'],
            'text beside a directive' => ['@if ($x) Saved successfully @endif'],
        ];
    }

    #[DataProvider('hardCodedBlade')]
    public function test_a_hard_coded_blade_string_is_caught(string $markup): void
    {
        self::assertNotSame([], self::scanBlade($markup), "This Blade must be reported: {$markup}");
    }

    /** @return array<string, array{0: string}> */
    public static function hardCodedPhp(): array
    {
        return [
            'abort with an english message' => ["<?php abort(403, 'You are not allowed here');"],
            'abort with an arabic message' => ["<?php abort(403, 'غير مسموح لك بالدخول');"],
            'abort_if' => ["<?php abort_if(\$denied, 403, 'Forbidden');"],
            'abort_unless' => ["<?php abort_unless(\$ok, 404, 'Deal not found');"],
            'abort_unless in arabic' => ["<?php abort_unless(\$ok, 404, 'الصفقة غير موجودة');"],
            'a message key in an array' => ["<?php return ['message' => 'The quotation was approved'];"],
            'an arabic message key' => ["<?php return ['message' => 'تمت الموافقة على عرض السعر'];"],
            'a double-quoted message key' => ['<?php return ["message" => "Saved"];'],
        ];
    }

    #[DataProvider('hardCodedPhp')]
    public function test_a_hard_coded_php_message_is_caught(string $php): void
    {
        self::assertNotSame([], self::scanPhp($php), "This PHP must be reported: {$php}");
    }

    // ───────────────────────────── layer 3: it must not cry wolf on valid code

    /** @return array<string, array{0: string}> */
    public static function acceptableTemplates(): array
    {
        return [
            'interpolated translation' => ['<template><h1>{{ t(\'ping.title\') }}</h1></template>'],
            'a conditional inside an interpolation' => ['<template><b>{{ x === \'ar\' ? t(\'a\') : t(\'b\') }}</b></template>'],
            'a bound aria-label' => ['<template><nav :aria-label="t(\'language.switch\')"></nav></template>'],
            'v-bind in its long form' => ['<template><i v-bind:title="t(\'a\')"></i></template>'],
            'an english html comment' => ['<template><!-- Explaining this at length --><p>{{ t(\'a\') }}</p></template>'],
            'an arabic html comment' => ['<template><!-- شرح مطوّل بالعربية --><p>{{ t(\'a\') }}</p></template>'],
            'attributes a user never reads' => ['<template><input type="text" name="email" class="rounded" data-locale="ar"></template>'],
            'a data- attribute that ends in a visible name' => ['<template><span data-title="x">{{ t(\'a\') }}</span></template>'],
            'punctuation and digits' => ['<template><span>—</span><span>2026</span><span>·</span></template>'],
            // Decision (b), made executable rather than left in a comment: the
            // message thrown by api.ts is a diagnostic shown beside the
            // translated one, and an interpolation is never a literal.
            'the diagnostic error interpolation from Ping.vue' => ['<template><p>{{ t(\'state.error\') }}</p><p><small>{{ error }}</small></p></template>'],
        ];
    }

    #[DataProvider('acceptableTemplates')]
    public function test_valid_vue_markup_is_not_reported(string $markup): void
    {
        self::assertSame([], self::scanVue($markup), "False alarm on valid markup: {$markup}");
    }

    /** @return array<string, array{0: string}> */
    public static function acceptablePhp(): array
    {
        return [
            'abort with a translated message' => ["<?php abort(403, __('auth.forbidden'));"],
            'abort_if with a translated message' => ["<?php abort_if(\$x, 403, trans('auth.forbidden'));"],
            'a translated message key' => ["<?php return ['message' => __('quotations.approved')];"],
            'a message taken from an exception' => ["<?php return ['message' => \$e->getMessage()];"],
            'abort with no message at all' => ['<?php abort(404);'],
            // Developer-facing exception text is not user-facing text. Both of
            // these exist in app/ today and must stay legal.
            'a developer-facing exception' => ["<?php throw new RuntimeException('Refusing to migrate against the development database.');"],
            'an argument guard' => ["<?php throw new InvalidArgumentException('scopeIndex() requires at least one column.');"],
            'a route or config string' => ["<?php Route::get('/ping', fn () => 'ok');"],
        ];
    }

    #[DataProvider('acceptablePhp')]
    public function test_valid_php_is_not_reported(string $php): void
    {
        self::assertSame([], self::scanPhp($php), "False alarm on valid PHP: {$php}");
    }

    // ───────────────────────────────────────────────────────── the scanner

    /** @return list<string> */
    private static function scanVue(string $source): array
    {
        // The outermost <template>. Greedy on purpose: <template v-if> nests,
        // and a lazy match would stop at the first inner close.
        $captured = self::capture('/<template[^>]*>(.*)<\/template>/su', $source);

        if ($captured === null) {
            return [];
        }

        $inner = $captured[1] ?? '';

        return array_merge(self::textViolations($inner), self::attributeViolations($inner));
    }

    /** @return list<string> */
    private static function scanBlade(string $source): array
    {
        return array_merge(self::textViolations($source), self::attributeViolations($source));
    }

    /**
     * Only the places a literal reaches a user. A blanket search for quoted
     * strings would report every route path, config key and developer-facing
     * exception in the codebase and be ignored within a week.
     *
     * @return list<string>
     */
    private static function scanPhp(string $source): array
    {
        $source = self::stripTranslatorCalls($source);
        $violations = [];

        $calls = implode('|', array_map('preg_quote', self::ABORT_CALLS));

        // The argument list, allowing one level of nesting inside it.
        $found = self::captureAll('/\b(?:'.$calls.')\s*\((?:[^()]|\([^()]*\))*\)/su', $source);

        foreach ($found[0] ?? [] as $call) {
            foreach (self::quotedLiterals($call) as $literal) {
                $violations[] = 'abort message: '.$literal;
            }
        }

        $found = self::captureAll('/([\'"])message\1\s*=>\s*([\'"])(.*?)\2/su', $source);

        foreach ($found[3] ?? [] as $literal) {
            if (self::hasLetters($literal)) {
                $violations[] = "message key: {$literal}";
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private static function quotedLiterals(string $php): array
    {
        $literals = [];

        $found = self::captureAll('/([\'"])((?:\\\\.|(?!\1).)*)\1/su', $php);

        foreach ($found[2] ?? [] as $literal) {
            if (self::hasLetters($literal)) {
                $literals[] = $literal;
            }
        }

        return $literals;
    }

    private static function stripTranslatorCalls(string $php): string
    {
        $names = implode('|', array_map('preg_quote', self::TRANSLATOR_CALLS));

        return self::replace('/\b(?:'.$names.')\s*\((?:[^()]|\([^()]*\))*\)/su', $php);
    }

    /**
     * Whatever a reader would see once markup, comments, directives and
     * interpolations are gone.
     *
     * @return list<string>
     */
    private static function textViolations(string $markup): array
    {
        $stripped = $markup;

        foreach ([
            '/\{\{--.*?--\}\}/su',                        // Blade comment — first, it can wrap anything
            '/<!--.*?-->/su',
            '/<(script|style)\b[^>]*>.*?<\/\1>/isu',
            '/\{\{.*?\}\}/su',                            // Vue and Blade echo
            '/\{!!.*?!!\}/su',                            // Blade raw echo
            '/@[a-zA-Z]+\s*\((?:[^()]|\([^()]*\))*\)/su', // @vite([...]), @lang(...)
            '/@[a-zA-Z]+/u',                              // @endif and friends
            // Every tag. The alternation is load-bearing: `[^>]*` stops at the
            // first `>` **inside an attribute value**, so
            // `v-if="a.total_pages > 1"` left the rest of the tag standing and
            // the scanner reported `class="…"` and `data-testid="…"` as
            // user-facing prose. Measured in Point 5.2, on real markup. Quoted
            // runs are consumed whole so a comparison operator in a binding
            // cannot end a tag early.
            '/<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/su',
        ] as $pattern) {
            $stripped = self::replace($pattern, $stripped);
        }

        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = preg_split('/\R/u', $stripped);

        if ($lines === false) {
            throw new RuntimeException('Splitting the stripped markup failed: '.preg_last_error_msg());
        }

        $violations = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '' && self::hasLetters($line)) {
                $violations[] = $line;
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private static function attributeViolations(string $markup): array
    {
        $names = implode('|', array_map('preg_quote', self::VISIBLE_ATTRIBUTES));
        $violations = [];

        $found = self::captureAll('/(?<![:\w-])('.$names.')\s*=\s*"([^"]*)"/iu', $markup);

        foreach ($found[2] ?? [] as $index => $value) {
            if (self::hasLetters($value)) {
                $violations[] = ($found[1][$index] ?? '').'="'.$value.'"';
            }
        }

        return $violations;
    }

    private static function hasLetters(string $value): bool
    {
        return self::capture('/\p{L}/u', $value) !== null;
    }

    // ─────────────────────────────────────────────────── plumbing, kept honest

    /**
     * preg_* signal failure with false/null and an error code, which is exactly
     * how the Arabic defect stayed invisible: preg_match returned false on
     * invalid UTF-8 and the caller read it as "no match". Nothing here is
     * allowed to fail quietly.
     *
     * Both wrappers return their captures rather than filling a by-ref
     * argument. That is not a style preference: a by-ref out-parameter leaves
     * static analysis unable to tell a populated match from an untouched one,
     * so every offset read becomes "might not exist" and the file stops being
     * analysable at level 10. Coding Standards §5 forbids lowering the level
     * to accommodate a file.
     *
     * @return array<string>|null null when the pattern did not match
     */
    private static function capture(string $pattern, string $subject): ?array
    {
        $result = preg_match($pattern, $subject, $captured);

        if ($result === false) {
            throw new RuntimeException("Pattern {$pattern} failed: ".preg_last_error_msg());
        }

        return $result === 1 ? $captured : null;
    }

    /**
     * @return array<list<string>>
     */
    private static function captureAll(string $pattern, string $subject): array
    {
        if (preg_match_all($pattern, $subject, $captured) === false) {
            throw new RuntimeException("Pattern {$pattern} failed: ".preg_last_error_msg());
        }

        return $captured;
    }

    private static function replace(string $pattern, string $subject): string
    {
        $result = preg_replace($pattern, ' ', $subject);

        if ($result === null) {
            throw new RuntimeException("Pattern {$pattern} failed: ".preg_last_error_msg());
        }

        return $result;
    }

    /** @return list<string> */
    private static function sourceFiles(string $directory, string $extension): array
    {
        $paths = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function basenames(array $paths): array
    {
        $names = array_map(static fn (string $path): string => basename($path), $paths);

        // Sorted, because the caller asserts a **set** and the iterator walks
        // directories rather than the alphabet. Point 5.1 added two files and
        // the diff showed five lines moving when two had been added — an
        // ordering the assertion never meant to pin, and one that would make
        // every future addition harder to read than it is.
        sort($names);

        return $names;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Could not read {$path}.");
        }

        return $contents;
    }

    private static function report(string $path): string
    {
        return "Hard-coded user-facing text in {$path}. Move it to a lang file "
            .'(Coding Standards §11, MVP_Build_Plan Module 0).';
    }
}
