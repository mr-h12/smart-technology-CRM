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
    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function locales(): array
    {
        return [
            'arabic' => ['ar', 'ar', 'rtl'],
            'english' => ['en', 'en', 'ltr'],
            'weighted arabic' => ['ar-EG,ar;q=0.9,en;q=0.8', 'ar', 'rtl'],
            // Unmatched falls back to the configured default, which is ar (§1).
            'unsupported' => ['fr-FR', 'ar', 'rtl'],
        ];
    }

    #[DataProvider('locales')]
    public function test_the_shell_carries_the_language_and_direction(string $header, string $lang, string $dir): void
    {
        $response = $this->withHeader('Accept-Language', $header)->get('/');

        $response->assertOk();
        $response->assertSee('lang="'.$lang.'"', false);
        $response->assertSee('dir="'.$dir.'"', false);
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
