<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Domain\Rendering\PdfRenderingFailed;
use App\Modules\Pdf\Infrastructure\BrowsershotPdfRenderer;
use Tests\TestCase;

/**
 * Module 9, Point 2.1 — what the renderer does where it **cannot** render.
 *
 * The suite runs in the `app` image, which CI asserts carries no browser
 * (`php-image.yml`, "Assert the app target carries no browser"). So the two
 * failures are what can be proven here: no browser is refused at once and by
 * name, and a browser that fails is reported as a render failure rather than
 * as Browsershot's own exception type leaking into Step 3's job. The real
 * render is `tests/PdfImage/`, run inside the `pdf` image.
 */
final class BrowsershotPdfRendererTest extends TestCase
{
    public function test_that_the_contract_resolves_to_the_browsershot_renderer(): void
    {
        self::assertInstanceOf(BrowsershotPdfRenderer::class, $this->app->make(PdfRendererInterface::class));
    }

    public function test_that_a_missing_browser_is_refused_at_once_and_by_name(): void
    {
        $renderer = new BrowsershotPdfRenderer(chromePath: '/nonexistent/chromium', nodeModulesPath: '/nonexistent', timeoutSeconds: 5);

        $started = microtime(true);

        try {
            $renderer->render('<p>مرحبا</p>');
            self::fail('A renderer with no browser returned a PDF.');
        } catch (PdfRenderingFailed $e) {
            self::assertStringContainsString('/nonexistent/chromium', $e->getMessage());
            self::assertStringContainsString('pdf', $e->getMessage(), 'The message must say which image renders.');
        }

        self::assertLessThan(1.0, microtime(true) - $started, 'A missing browser must not wait for a timeout.');
    }

    public function test_that_a_browser_that_fails_is_reported_as_a_render_failure(): void
    {
        // `/bin/false` exists and is executable, so the process starts and
        // fails — Browsershot's exception must not reach the caller as-is.
        $renderer = new BrowsershotPdfRenderer(chromePath: '/bin/false', nodeModulesPath: '/nonexistent', timeoutSeconds: 5);

        $this->expectException(PdfRenderingFailed::class);

        $renderer->render('<p>Offer</p>');
    }
}
