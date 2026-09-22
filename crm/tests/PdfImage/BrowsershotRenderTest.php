<?php

declare(strict_types=1);

namespace Tests\PdfImage;

use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use Tests\TestCase;

/**
 * Module 9, Point 2.1 — a real render, which only the `pdf` image can do.
 *
 * Not in any `phpunit.xml` suite on purpose: the `app` image has no browser,
 * so this directory runs only when named, inside the image that renders —
 * `docker compose exec worker-pdf php artisan test tests/PdfImage`. Point 2.6
 * puts that command in CI.
 */
final class BrowsershotRenderTest extends TestCase
{
    public function test_that_arabic_and_english_render_to_a_real_pdf(): void
    {
        $pdf = $this->app->make(PdfRendererInterface::class)->render(
            '<html lang="ar" dir="rtl"><body style="font-family: \'Noto Sans Arabic\'">'
            .'<p>يسعدنا أن نقدم لكم عرض الأسعار التالي</p><p dir="ltr">Offer 1,234.50 EGP</p></body></html>',
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(5000, strlen($pdf), 'A PDF this small carries no embedded font.');
        self::assertStringContainsString('NotoSansArabic', $pdf, 'The Arabic face was not embedded (§14.6).');
    }

    public function test_that_a_script_in_the_page_does_not_run(): void
    {
        // Chrome's sandbox is off in this container, so the page gets no
        // JavaScript. Were the script to run, it would add three pages.
        $pdf = $this->app->make(PdfRendererInterface::class)->render(
            '<p>Offer</p><script>for (let i = 0; i < 3; i++) { const d = document.createElement("div");'
            .' d.style.pageBreakBefore = "always"; d.textContent = "x"; document.body.appendChild(d); }</script>',
        );

        self::assertSame(1, preg_match_all('#/Type\s*/Page(?!s)#', $pdf), 'A script in the rendered page ran.');
    }
}
