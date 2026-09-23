<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Pdf\Domain\Contracts\PdfAssetsInterface;
use App\Modules\Pdf\Infrastructure\FilePdfAssets;
use Tests\TestCase;

/**
 * Module 9, Point 2.2 — the faces and the letterhead the customer PDF is made
 * of, and the one rule they all obey: **nothing is fetched at render time**.
 *
 * `D-79` embedded four faces base64 "with zero OS fallback", and `D-89` keeps
 * that: the on-premise Linux server has no Arabic system font, and `sans-serif`
 * resolves differently there than on a developer's Mac, so a face that is not
 * embedded changes the document's metrics between development and production.
 * The families are named `CRM Sans` and `CRM Sans Arabic` on purpose — names no
 * operating system ships — so a broken `@font-face` cannot be masked by a
 * system font that happens to have the same name.
 */
final class PdfAssetsTest extends TestCase
{
    public function test_that_the_contract_resolves_to_the_file_assets(): void
    {
        self::assertInstanceOf(FilePdfAssets::class, $this->app->make(PdfAssetsInterface::class));
    }

    public function test_that_all_four_faces_are_declared_and_embedded(): void
    {
        $css = $this->assets()->fontFaceCss();

        self::assertSame(4, substr_count($css, '@font-face'), 'D-89 carries four faces forward from D-79.');

        foreach ([['CRM Sans', '400'], ['CRM Sans', '700'], ['CRM Sans Arabic', '400'], ['CRM Sans Arabic', '700']] as [$family, $weight]) {
            self::assertMatchesRegularExpression(
                '/@font-face\s*\{[^}]*font-family:\s*\''.preg_quote($family, '/').'\'[^}]*font-weight:\s*'.$weight.'[^}]*\}/s',
                $css,
                "{$family} {$weight} is not declared.",
            );
        }

        self::assertSame(4, substr_count($css, 'data:font/woff2;base64,'));
    }

    public function test_that_nothing_in_the_css_is_fetched_or_borrowed_from_the_system(): void
    {
        $css = $this->assets()->fontFaceCss();

        // Every url() is a data: URI — no http(s), no file path, and no
        // local(), which is the one way an @font-face can still resolve to a
        // face installed on the box that renders.
        preg_match_all('/url\(([^)]*)\)/', $css, $matches);
        self::assertNotEmpty($matches[1]);

        foreach ($matches[1] as $url) {
            self::assertStringStartsWith('data:font/woff2;base64,', trim($url, '\'" '));
        }

        self::assertStringNotContainsStringIgnoringCase('local(', $css);
        self::assertStringNotContainsStringIgnoringCase('http', $css);
    }

    public function test_that_the_letterhead_images_are_embedded_too(): void
    {
        $assets = $this->assets();

        self::assertStringStartsWith('data:image/png;base64,', $assets->logo());
        self::assertStringStartsWith('data:image/jpeg;base64,', $assets->footerBand());
        self::assertStringStartsWith('data:image/jpeg;base64,', $assets->watermark());

        foreach ([$assets->logo(), $assets->footerBand(), $assets->watermark()] as $uri) {
            self::assertGreaterThan(10_000, strlen($uri), 'An image this small is not the letterhead.');
        }
    }

    public function test_that_every_face_ships_with_its_licence(): void
    {
        // The OFL requires the licence to travel with the font. These files are
        // in the repository next to the faces, not a link in a comment.
        foreach (['LICENSE-Inter-OFL.txt', 'LICENSE-NotoSansArabic-OFL.txt'] as $licence) {
            $path = resource_path('pdf/fonts/'.$licence);

            self::assertFileExists($path);
            self::assertStringContainsString('SIL OPEN FONT LICENSE', (string) file_get_contents($path));
        }
    }

    public function test_that_a_missing_asset_is_named_rather_than_rendered_blank(): void
    {
        $assets = new FilePdfAssets('/nonexistent/pdf');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/inter-400\.woff2/');

        $assets->fontFaceCss();
    }

    private function assets(): PdfAssetsInterface
    {
        return $this->app->make(PdfAssetsInterface::class);
    }
}
