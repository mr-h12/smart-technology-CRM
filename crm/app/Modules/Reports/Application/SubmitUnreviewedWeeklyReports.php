<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\Contracts\ReportSubmitted;
use App\Support\Settings\SettingReader;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * `J-06` (§15; `D-136`): a weekly draft still unsubmitted after the review
 * window (`limits.weekly_review_window_hours`, 24 hours while unset — `D-127`)
 * is submitted unchanged to the default recipient (`D-129`), flagged Not
 * reviewed: the same frozen copy and recipient row as the author's submit.
 * With no valid default recipient it stays a draft and the next run tries
 * again. Daily reports are never touched. Idempotent (§15.1): only drafts
 * move. The audit carries no actor, and `ReportSubmitted` follows each commit
 * as `SubmitReport` dispatches it (`D-132`).
 */
final readonly class SubmitUnreviewedWeeklyReports
{
    private const DEFAULT_WINDOW_HOURS = 24;

    public function __construct(
        private ReportDirectoryInterface $reports,
        private SettingReader $limits,
        private FrozenCopy $frozen,
        private RecipientCandidates $candidates,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
        private Dispatcher $events,
    ) {}

    /** @return int how many reports were submitted */
    public function run(): int
    {
        $window = $this->limits->nullableInteger('limits.weekly_review_window_hours') ?? self::DEFAULT_WINDOW_HOURS;
        $submitted = 0;

        foreach ($this->reports->staleWeeklyDraftIds(now()->subHours($window)) as $id) {
            $version = $this->connection->transaction(fn (): ?int => $this->submit($id));

            if ($version !== null) {
                $this->events->dispatch(new ReportSubmitted($id, $version));
                $submitted++;
            }
        }

        return $submitted;
    }

    private function submit(string $id): ?int
    {
        $report = $this->reports->find($id, true);
        $recipient = $report === null || $report->status !== 'draft' ? null : $this->candidates->defaultFor($report);

        if ($report === null || $recipient === null
            || ! $this->reports->submit($id, $report->versionToken, $this->frozen->of($report), [$recipient], null, true)) {
            return null;
        }

        $this->audit->record(AuditEvent::of('REPORT_SUBMITTED'), 'report', $id, ['status' => 'draft'], [
            'status' => 'submitted', 'recipient_ids' => [$recipient], 'not_reviewed' => true,
        ]);

        return $report->submittedVersions + 1;
    }
}
