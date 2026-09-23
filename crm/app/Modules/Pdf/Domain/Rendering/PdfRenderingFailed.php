<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Rendering;

use RuntimeException;
use Throwable;

/**
 * A render that did not produce a PDF — the one failure type Step 3's job
 * retries and records, whatever the engine underneath threw.
 */
final class PdfRenderingFailed extends RuntimeException
{
    public static function browserUnavailable(string $chromePath): self
    {
        return new self(
            "No browser at {$chromePath}. PDFs render only in the pdf image, on the pdf queue (§15.1).",
        );
    }

    public static function because(Throwable $cause): self
    {
        return new self('The PDF render failed: '.$cause->getMessage(), 0, $cause);
    }
}
