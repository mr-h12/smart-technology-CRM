<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Presentation;

use App\Modules\Pdf\Domain\Contracts\ReportPdfGenerationsInterface;
use App\Modules\Reports\Domain\Contracts\ReportSubmitted;
use App\Modules\Reports\Domain\Contracts\SubmittedReportsInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `D-132`, spec §4.2 — a committed submit queues its version's PDF. Synchronous,
 * inside the submitter's request, so the row exists as soon as the submit
 * returns; the render itself goes to the `pdf` queue. The unique key makes a
 * repeat a no-op. The row acts for the report's author, who is the submitter.
 *
 * The submit has committed by now, so nothing here may turn it into an error
 * (a failed PDF never unwinds the submit): on the `sync` connection the job runs
 * inline, and its failure is already recorded on the row by `failed()`.
 * ponytail: a push that fails outright (Redis down) leaves the row `queued` with
 * no job; the manual retry `D-132` defers to a later slice is the way back.
 */
final readonly class QueuePdfWhenReportSubmitted
{
    public function __construct(
        private ReportPdfGenerationsInterface $generations,
        private SubmittedReportsInterface $reports,
    ) {}

    public function handle(ReportSubmitted $event): void
    {
        $version = $this->reports->version($event->reportId, $event->version);
        $id = $version === null ? null : $this->generations->queue($event->reportId, $event->version, $version['author_id']);

        if ($id === null) {
            return;
        }

        try {
            RenderReportPdfJob::dispatch($id);
        } catch (Throwable $failure) {
            Log::warning("D-132: report {$event->reportId} v{$event->version} was submitted, but its PDF job failed: {$failure->getMessage()}");
        }
    }
}
