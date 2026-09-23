<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Contracts;

use App\Modules\Pdf\Domain\Rendering\PdfRenderingFailed;

/**
 * HTML in, PDF bytes out — Module 9, Point 2.1.
 *
 * The seam `D-57` asks for: Browsershot drives headless Chrome, the engine
 * `P-01` proved shapes Arabic, and nothing above this interface knows that.
 * Step 3's job calls it on the `pdf` queue, inside the only image that carries
 * a browser.
 */
interface PdfRendererInterface
{
    /**
     * @return string the PDF's bytes
     *
     * @throws PdfRenderingFailed when this image cannot render, or the render fails
     */
    public function render(string $html): string;
}
