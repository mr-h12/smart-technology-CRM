<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Presentation;

use App\Modules\Pdf\Application\Generation\RenderReportPdf;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * `D-132` — the `pdf` queue's job for one report version's stored PDF, on
 * `RenderQuotationPdfJob`'s terms: the worker's `--tries=3`, then `failed()`
 * records the reason. It carries only the generation's id; the version is
 * frozen, so the job reads it when it runs.
 */
final class RenderReportPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** `RenderQuotationPdfJob::$timeout`'s reasoning: above Browsershot's 60 s, under `retry_after`'s 90 s. */
    public int $timeout = 80;

    public function __construct(public readonly string $generationId)
    {
        $this->onQueue(QueueName::Pdf->value);
    }

    public function handle(RenderReportPdf $render): void
    {
        $render->handle($this->generationId, $this->attempts());
    }

    public function failed(Throwable $exception): void
    {
        app(RenderReportPdf::class)->giveUp($this->generationId, $exception);
    }
}
