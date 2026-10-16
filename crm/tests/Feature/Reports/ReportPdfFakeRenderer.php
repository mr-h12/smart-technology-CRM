<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Domain\Rendering\PdfRenderingFailed;
use RuntimeException;

/** Remembers what it was asked to print and answers fixed bytes, after failing the first `$failures` calls as Chromium does. */
final class ReportPdfFakeRenderer implements PdfRendererInterface
{
    public int $calls = 0;

    /** @var list<string> */
    public array $locales = [];

    /** @var list<string> */
    public array $htmls = [];

    private int $failures = 0;

    public function __construct(private readonly string $bytes) {}

    public function failTimes(int $failures): void
    {
        $this->failures = $failures;
    }

    public function render(string $html, ?string $footerHtml = null): string
    {
        $this->calls++;
        $this->locales[] = str_contains($html, 'dir="rtl"') ? 'ar' : 'en';
        $this->htmls[] = $html;

        if ($this->calls <= $this->failures) {
            throw PdfRenderingFailed::because(new RuntimeException('Chromium did not answer.'));
        }

        return $this->bytes;
    }
}
