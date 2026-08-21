<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * OpenAPI §2: the client sends Accept-Language: ar or en, stable machine codes
 * stay English, user-facing messages are localized.
 *
 * Both halves are asserted. Translating the codes as well would break every
 * client that switches on them, and is the kind of thing that only shows up
 * once a client is written.
 */
final class LocaleTest extends TestCase
{
    // No probe route. defineRoutes is a Testbench hook that a plain Laravel
    // TestCase never calls, and a route registered in setUp is added after the
    // kernel has already resolved them. /api/v1/ping is a real route the
    // middleware runs on, which is a better subject anyway — it asserts the
    // behaviour clients actually get.

    /** @return array<string, array{0: string, 1: string}> */
    public static function headers(): array
    {
        return [
            'plain arabic' => ['ar', 'ar'],
            'plain english' => ['en', 'en'],
            // Real browsers send a weighted list, not a bare tag.
            'weighted, arabic first' => ['ar-EG,ar;q=0.9,en;q=0.8', 'ar'],
            'weighted, english first' => ['en-GB,en;q=0.9,ar;q=0.8', 'en'],
            // Nothing we support: fall back to the configured default rather
            // than 400, since the header is advisory and a request should not
            // fail over a preference. That default is ar — this is an
            // Arabic-first system (§1), and the first version of this test
            // assumed en and was wrong about the application, not the code.
            'unsupported' => ['fr-FR,fr;q=0.9', 'ar'],
            // Untrusted input. App::setLocale feeds the translation file loader,
            // so a path fragment must never reach it — it resolves to the
            // default like any other unmatched header.
            'path traversal attempt' => ['../../etc/passwd', 'ar'],
        ];
    }

    // PHPUnit 12 no longer reads @dataProvider from a docblock — the attribute
    // is the supported form, and the annotation fails with "too few arguments"
    // rather than being reported as unrecognised.
    #[DataProvider('headers')]
    public function test_the_locale_comes_from_the_accept_language_header(string $header, string $expected): void
    {
        $response = $this->withHeader('Accept-Language', $header)->getJson('/api/v1/ping');

        $response->assertOk();
        $response->assertHeader('Content-Language', $expected);
    }

    public function test_the_response_varies_on_accept_language(): void
    {
        // Without Vary a shared cache can hand an Arabic response to a client
        // that asked for English.
        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/ping')
            ->assertHeader('Vary', 'Accept-Language');
    }

    public function test_validation_messages_are_translated(): void
    {
        app()->setLocale('ar');
        $arabic = Validator::make([], ['email' => 'required'])->errors()->first('email');

        app()->setLocale('en');
        $english = Validator::make([], ['email' => 'required'])->errors()->first('email');

        self::assertMatchesRegularExpression('/\p{Arabic}/u', $arabic,
            'A client asking for Arabic must not receive an English validation message.');
        self::assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $english);
        self::assertNotSame($arabic, $english);
    }

    /**
     * Message files nest — 'between' holds four messages under it — so a
     * comparison has to reach the leaves. Keys come back dotted and prefixed
     * with the file: 'validation.between.numeric'.
     *
     * Only the key set matters here, so the leaves are recorded as true
     * rather than carried along as mixed values nothing reads.
     *
     * @param  array<mixed, mixed>  $messages
     * @return array<string, true>
     */
    private static function flatten(array $messages, string $prefix): array
    {
        $flat = [];

        foreach ($messages as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += self::flatten($value, $path);

                continue;
            }

            $flat[$path] = true;
        }

        return $flat;
    }

    /**
     * Every message the locale defines, from every file it defines them in.
     *
     * @return array<string, true>
     */
    private static function messages(string $locale): array
    {
        $flat = [];

        foreach (glob(lang_path($locale.'/*.php')) ?: [] as $file) {
            /** @var array<mixed, mixed> $messages */
            $messages = require $file;

            $flat += self::flatten($messages, basename($file, '.php'));
        }

        return $flat;
    }

    /**
     * Keys of the locale's JSON file, whose keys are English sentences rather
     * than dotted paths.
     *
     * @return array<string, true>
     */
    private static function jsonMessages(string $locale): array
    {
        $path = lang_path($locale.'.json');

        if (! is_file($path)) {
            return [];
        }

        /** @var array<string, string> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return array_fill_keys(array_keys($decoded), true);
    }

    public function test_the_two_locales_define_the_same_keys(): void
    {
        // A missing key is silent — it only appears to the user, in the wrong
        // language or as the raw key.
        //
        // This assertion used to run array_diff_key over the two top levels,
        // which compares 'between' and never the four messages beneath it.
        // Deleting the Arabic 'between.numeric' left the whole suite green
        // while an Arabic client was served "The n field must be between 1 and
        // 5." Flatten to the leaves, read whatever the lang directory actually
        // holds rather than a list of names that goes stale, and compare in
        // both directions: lang/en was missing 187 keys lang/ar had, including
        // the whole of actions.php and http-statuses.php, and the one-way
        // check could not see any of them.
        $en = self::messages('en');
        $ar = self::messages('ar');

        self::assertSame([], array_keys(array_diff_key($en, $ar)),
            'lang/ar is missing keys that lang/en defines.');
        self::assertSame([], array_keys(array_diff_key($ar, $en)),
            'lang/en is missing keys that lang/ar defines.');
    }

    public function test_the_two_locales_define_the_same_json_keys(): void
    {
        // JSON keys are the English sentence itself, so English resolves even
        // with no file at all — except for plural forms, which the key alone
        // cannot express. The files are published as a pair and must stay one.
        $en = self::jsonMessages('en');
        $ar = self::jsonMessages('ar');

        self::assertNotSame([], $en, 'lang/en.json is missing entirely.');
        self::assertSame([], array_keys(array_diff_key($en, $ar)),
            'lang/ar.json is missing keys that lang/en.json defines.');
        self::assertSame([], array_keys(array_diff_key($ar, $en)),
            'lang/en.json is missing keys that lang/ar.json defines.');
    }

    public function test_no_locale_renders_a_raw_translation_key(): void
    {
        // The behavioural half. The two assertions above compare files; this
        // one asks the translator what a user would actually be shown, which
        // is the form the defect took: with no lang/en/actions.php, an English
        // __('actions.save') rendered the literal string "actions.save".
        $keys = array_keys(self::messages('ar') + self::messages('en'));
        $raw = [];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ($keys as $key) {
                if (__($key) === $key) {
                    $raw[] = $locale.': '.$key;
                }
            }
        }

        self::assertSame([], $raw,
            'These keys render as themselves instead of a message: '.implode(', ', $raw));
    }

    public function test_machine_codes_are_never_translated(): void
    {
        // OpenAPI §5.1 lists the stable codes clients switch on. Coding
        // Standards §8: "do not make clients parse error text."
        app()->setLocale('ar');

        foreach (['validation_failed', 'permission_denied', 'concurrency_conflict'] as $code) {
            self::assertSame($code, __($code),
                'An error code must pass through untranslated in any locale.');
        }
    }
}
