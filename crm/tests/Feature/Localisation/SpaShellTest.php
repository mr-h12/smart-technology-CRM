<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The server half of "switching language flips direction", which is one of
 * Module 0's acceptance criteria.
 *
 * The client half — clicking the switch and watching dir change without a
 * reload — was verified in a real browser. What is asserted here is that the
 * document arrives correct in the first place, so the first paint is already in
 * the right direction rather than corrected by JavaScript afterwards.
 */
final class SpaShellTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function locales(): array
    {
        return [
            'arabic' => ['ar'],
            // The shell used to answer this header with an English document,
            // which made the product's language a property of the visitor's
            // browser. §1 makes the system Arabic-first, so the default is the
            // configured one and the browser's preference does not override it.
            'english' => ['en'],
            'weighted english' => ['en-GB,en;q=0.9,ar;q=0.8'],
            'unsupported' => ['fr-FR'],
        ];
    }

    #[DataProvider('locales')]
    public function test_the_shell_opens_in_the_configured_default_language(string $header): void
    {
        $response = $this->withHeader('Accept-Language', $header)->get('/');

        $response->assertOk();
        $response->assertSee('lang="ar"', false);
        $response->assertSee('dir="rtl"', false);
    }

    public function test_the_default_is_the_configured_one_and_not_a_hard_coded_arabic(): void
    {
        // Without this, `lang="ar"` written straight into the template passes
        // every case above while APP_LOCALE means nothing.
        config(['app.default_locale' => 'en']);

        $response = $this->withHeader('Accept-Language', 'ar')->get('/');

        $response->assertSee('lang="en"', false);
        $response->assertSee('dir="ltr"', false);
    }

    public function test_the_shell_carries_no_user_facing_text(): void
    {
        // CLAUDE.md: no string literals in Blade. The shell exists to mount the
        // application; every visible string belongs to a lang file, and a
        // sentence added here would be invisible to the translation gate in 3.3.
        $html = $this->withHeader('Accept-Language', 'ar')->get('/')->getContent();
        self::assertIsString($html);

        // Strip everything that is markup, script or style, then look at what a
        // reader would actually see.
        $visible = trim(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? ''));

        // The <title> is the one exception, and it is the application name — a
        // proper noun that is the same in both languages, not a translatable
        // sentence. Anything else appearing here is a literal that escaped the
        // lang files.
        $appName = config('app.name');
        self::assertIsString($appName);

        self::assertSame($appName, $visible,
            "The SPA shell must render no text beyond the application name; found: {$visible}");
    }

    public function test_the_application_is_not_still_called_laravel(): void
    {
        // Caught by the test above: APP_NAME was never changed from the
        // skeleton's default, so every browser tab said "Laravel".
        self::assertSame('Smart Technology CRM', config('app.name'));
    }
}
