<?php

declare(strict_types=1);

namespace Tests\PdfImage;

use App\Modules\Pdf\Application\CustomerQuotationHtml;
use App\Modules\Pdf\Domain\Contracts\PdfAssetsInterface;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Domain\View\CustomerQuotationLine;
use Tests\Fixtures\CustomerQuotationViewFixture;
use Tests\TestCase;

/**
 * Module 9, Point 2.4 — `D-89`'s template through the real engine.
 *
 * The HTML tests prove the rules; this proves the page survives Chromium: it
 * renders, it carries the embedded faces rather than a system fallback, and a
 * three-line quotation still fits on one sheet — `D-79`'s one-page guard, which
 * must be re-checked on every template change because content once ran 3 mm
 * over A4 and silently produced a blank second page.
 */
final class CustomerQuotationPdfTest extends TestCase
{
    public function test_that_both_languages_render_on_one_page_with_the_embedded_faces(): void
    {
        foreach (['en' => CustomerQuotationViewFixture::make(), 'ar' => CustomerQuotationViewFixture::arabic()] as $locale => $view) {
            $html = $this->app->make(CustomerQuotationHtml::class)->render($view, $locale);
            $pdf = $this->app->make(PdfRendererInterface::class)->render($html);

            self::assertStringStartsWith('%PDF-', $pdf, "The {$locale} quotation did not render.");
            self::assertSame(1, preg_match_all('#/Type\s*/Page(?!s)#', $pdf), "The {$locale} quotation spilled onto a second page (D-79's one-page guard).");

            $faces = BrowsershotRenderTest::baseFontsOf($pdf);

            self::assertSame([], array_filter($faces, static fn (string $f): bool => str_contains($f, 'DejaVu')),
                "A system face reached the {$locale} document.");
            self::assertNotSame([], array_filter($faces, static fn (string $f): bool => str_contains($f, $locale === 'ar' ? 'NotoNaskhArabic' : 'Inter')),
                "The {$locale} document does not carry its embedded face.");
        }
    }

    public function test_that_the_arabic_page_sets_arabic_in_naskh_and_nowhere_in_noto_sans(): void
    {
        // D-101: Noto Naskh Arabic replaces Noto Sans Arabic on the whole page,
        // the live page-number footer included — which is why this renders
        // with the footer, unlike the test above.
        $faces = BrowsershotRenderTest::baseFontsOf($this->render(CustomerQuotationViewFixture::arabic(), 'ar'));

        self::assertNotSame([], array_filter($faces, static fn (string $f): bool => str_contains($f, 'NotoNaskhArabic')), 'The Arabic page is not in Naskh.');
        self::assertSame([], array_filter($faces, static fn (string $f): bool => str_contains($f, 'NotoSansArabic')), 'Noto Sans Arabic still reaches the Arabic page.');
    }

    public function test_that_digits_and_latin_on_the_arabic_page_stay_inter(): void
    {
        // D-101 keeps Inter for numbers and English (D-70). That holds only
        // while the embedded Arabic face is the Arabic-script subset: a full
        // Naskh file carries 0-9 and Latin of its own, and Chrome would take
        // them before falling through to Inter. Same stack as the template.
        $css = $this->app->make(PdfAssetsInterface::class)->fontFaceCss();
        $pdf = $this->app->make(PdfRendererInterface::class)->render(
            "<!doctype html><html lang=\"ar\" dir=\"rtl\"><head><style>{$css} body { font-family: 'CRM Sans Arabic', 'CRM Sans'; }</style></head>"
            .'<body>0123456789.,ATEN</body></html>'
        );

        self::assertSame(['Inter-Regular'], BrowsershotRenderTest::baseFontsOf($pdf), 'A face other than Inter drew the digits or the Latin text.');
    }

    public function test_that_a_long_quotation_runs_onto_more_pages_and_a_short_one_does_not(): void
    {
        // Point 2.5. The item count varies per quotation — that is the whole
        // reason D-79 refused a hard-coded "page 1 of 1" — so the template must
        // paginate rather than clip, and a row may not split across the break.
        $lines = [];
        for ($i = 1; $i <= 40; $i++) {
            $lines[] = new CustomerQuotationLine($i, "Formatter M428dw spare part {$i}", '2', '5219.30', '10438.60', null, null, null, '10438.60');
        }

        $pdf = $this->render(CustomerQuotationViewFixture::make(['lines' => $lines]), 'en');

        self::assertGreaterThan(1, self::pagesIn($pdf), 'Forty lines fitted on one page, so something clipped them.');
        self::assertSame(1, self::pagesIn($this->render(CustomerQuotationViewFixture::make(), 'en')));
    }

    public function test_that_the_footer_reaches_the_document(): void
    {
        // Chrome draws the footer as its own mini-document, and a PDF's text is
        // glyph indices rather than characters, so the numbers cannot be read
        // back out here — the owner reads them at Point 2.7. What is provable
        // is that asking for the footer changes the document: without it the
        // same quotation renders materially smaller.
        $view = CustomerQuotationViewFixture::make();
        $html = $this->app->make(CustomerQuotationHtml::class);
        $renderer = $this->app->make(PdfRendererInterface::class);

        $page = $html->render($view, 'en');
        $withFooter = $renderer->render($page, $html->footer('en'));
        $without = $renderer->render($page);

        self::assertGreaterThan(strlen($without) + 2000, strlen($withFooter), 'The footer was not drawn.');
        self::assertSame(1, self::pagesIn($withFooter), 'The footer pushed the document onto a second page.');
    }

    private function render(\App\Modules\Pdf\Domain\View\CustomerQuotationView $view, string $locale): string
    {
        $html = $this->app->make(CustomerQuotationHtml::class);

        return $this->app->make(PdfRendererInterface::class)->render($html->render($view, $locale), $html->footer($locale));
    }

    private static function pagesIn(string $pdf): int
    {
        $pages = preg_match_all('#/Type\s*/Page(?!s)#', $pdf);

        self::assertNotFalse($pages, 'The page scan failed on the PDF.');

        return $pages;
    }
}
