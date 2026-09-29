<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Presentation;

use App\Modules\Pdf\Application\Generation\RenderQuotationPdf;
use App\Modules\Pdf\Domain\View\CustomerQuotationView;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Module 9, Point 3.4 — the `pdf` queue's job (§15.1): one generation, with
 * the view mapped when the button was pressed (Q10). The retry bound is the
 * worker's `--tries=3`, as for every job here; `failed()` records the
 * failure once it is spent (Q3).
 */
final class RenderQuotationPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Above Browsershot's own 60 s (`config/pdf.php`), so a slow Chromium ends
     * in its own error rather than the worker's kill; under the redis
     * connection's `retry_after` of 90 s, so no second worker takes the job
     * while this one still holds it.
     */
    public int $timeout = 80;

    public function __construct(
        public readonly string $generationId,
        public readonly CustomerQuotationView $view,
    ) {
        $this->onQueue(QueueName::Pdf->value);
    }

    public function handle(RenderQuotationPdf $render): void
    {
        $render->handle($this->generationId, $this->view, $this->attempts());
    }

    public function failed(Throwable $exception): void
    {
        app(RenderQuotationPdf::class)->giveUp($this->generationId, $exception);
    }
}
