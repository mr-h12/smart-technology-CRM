<?php

declare(strict_types=1);

namespace Tests\PdfImage;

use App\Modules\Pdf\Application\CustomerQuotationHtml;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
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

            preg_match_all('#/BaseFont\s*/(?:[A-Z]{6}\+)?([A-Za-z0-9,\-_.]+)#', $pdf, $matches);
            $faces = array_values(array_unique($matches[1]));

            self::assertSame([], array_filter($faces, static fn (string $f): bool => str_contains($f, 'DejaVu')),
                "A system face reached the {$locale} document.");
            self::assertNotSame([], array_filter($faces, static fn (string $f): bool => str_contains($f, $locale === 'ar' ? 'NotoSansArabic' : 'Inter')),
                "The {$locale} document does not carry its embedded face.");
        }
    }
}
