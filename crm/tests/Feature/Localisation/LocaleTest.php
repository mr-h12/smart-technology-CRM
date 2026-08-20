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

    public function test_every_english_message_has_an_arabic_counterpart(): void
    {
        // A missing key falls back to English silently, so the gap only appears
        // to the user. Two entries were English-only in the published Arabic
        // file and were translated.
        foreach (['validation', 'auth', 'passwords', 'pagination'] as $file) {
            /** @var array<string, mixed> $en */
            $en = require lang_path("en/{$file}.php");
            /** @var array<string, mixed> $ar */
            $ar = require lang_path("ar/{$file}.php");

            self::assertSame([], array_keys(array_diff_key($en, $ar)),
                "lang/ar/{$file}.php is missing keys present in English.");
        }
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
