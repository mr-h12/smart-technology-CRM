<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain\Contracts;

use App\Modules\Reports\Domain\Report;

/**
 * The module's persistence port: `reports`, `report_versions`, `report_recipients`,
 * `D-131`'s rows — kind `follow_up` (`report_follow_ups`) and `note` (`report_notes`) — and `D-127`'s `report_comments`.
 */
interface ReportDirectoryInterface
{
    /**
     * A new draft; answers its id and code.
     *
     * @return array{id: string, code: string}
     */
    public function createDraft(string $type, string $codePrefix, string $authorId, string $periodStart, string $periodEnd): array;

    /**
     * `D-127`: the author's live daily report for the company day (any status), or null.
     *
     * @return array{id: string, code: string}|null
     */
    public function findDaily(string $authorId, string $day): ?array;

    /** `$lock` holds the row until the caller's transaction ends, so a row write and a submit never interleave. */
    public function find(string $reportId, bool $lock = false): ?Report;

    /** @return array{items: list<Report>, total: int} newest period first; archived ones (`J-08`) only when asked for */
    public function forAuthor(string $authorId, int $page, int $perPage, bool $includeArchived): array;

    /**
     * One kind's live rows of a report, in the order they were added, each with its `id`.
     *
     * @return list<array<string, string>>
     */
    public function rowsOf(string $kind, string $reportId): array;

    /**
     * @param  array<string, string>  $fields
     * @return string the new row's id
     */
    public function addRow(string $kind, string $reportId, array $fields, string $actorId): string;

    /**
     * The live row when it is this report's, with its `id`; null otherwise.
     *
     * @return array<string, string>|null
     */
    public function findRow(string $kind, string $reportId, string $rowId): ?array;

    /** @param  array<string, string>  $fields */
    public function updateRow(string $kind, string $rowId, array $fields, string $actorId): void;

    /** Soft-deleted (`DB-01`). */
    public function removeRow(string $kind, string $rowId, string $actorId): void;

    /**
     * `D-127`: a recipient's comment, appended; answers its id and when it was written (UTC, ATOM).
     *
     * @return array{id: string, created_at: string}
     */
    public function addComment(string $reportId, string $body, string $actorId): array;

    /**
     * A report's live comments, oldest first.
     *
     * @return list<array{id: string, body: string, author_id: string, created_at: string}>
     */
    public function commentsOf(string $reportId): array;

    /**
     * Submit under the token: status, the next frozen version and the
     * recipients — one live row per user, so a resubmit keeps who stays,
     * adds who is new and soft-removes who was dropped. False, and nothing
     * written, when the token moved. `$actorId` is null for J-06 (`D-136`), which
     * submits with `$notReviewed` set; any submit by a person clears the flag.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $recipientIds
     */
    public function submit(string $reportId, int $versionToken, array $snapshot, array $recipientIds, ?string $actorId, bool $notReviewed = false): bool;

    /**
     * The newest frozen copy, or null for a report never submitted.
     *
     * @return array<string, mixed>|null
     */
    public function latestSnapshot(string $reportId): ?array;

    /**
     * One version's frozen copy and when it was submitted (its row's `created_at`,
     * UTC, ATOM), or null when the report has no such version.
     *
     * @return array{snapshot: array<string, mixed>, submitted_at: string}|null
     */
    public function versionOf(string $reportId, int $version): ?array;

    /** @return list<string> */
    public function recipientIdsOf(string $reportId): array;

    /** @return array{items: list<Report>, total: int} the reports sent to the user, newest period first */
    public function forRecipient(string $userId, int $page, int $perPage, bool $includeArchived): array;

    /**
     * A recipient's answer under the token: the report moves to `$status`
     * (`viewed`, `acknowledged` or `returned`) and the newest version row
     * records who and when (and the return reason). False, and nothing
     * written, when the token moved.
     */
    public function receive(string $reportId, int $versionToken, string $status, ?string $returnReason, string $actorId): bool;

    /**
     * `D-135`, J-04: every daily report still in draft whose day is a working
     * day (`D-125`), before `$today` and on or after `$goLive` becomes `missed`.
     * Only drafts move, so a second run moves none.
     *
     * @return list<string> the ids moved
     */
    public function markMissed(string $goLive, string $today): array;

    /**
     * `D-136` J-05, `D-140` J-07: the authors who already have a report of
     * `$type` starting on `$periodStart` (soft-deleted ones included, so a
     * deleted draft is not remade).
     *
     * @return list<string>
     */
    public function authorsOf(string $type, string $periodStart): array;

    /**
     * `D-126`, RPT-WK: the author's daily reports whose day is in `[$from, $to]`, oldest first.
     *
     * @return list<array{code: string, period_start: string, status: string}>
     */
    public function dailyReportsOf(string $authorId, string $from, string $to): array;

    /**
     * `D-136`, J-06: the weekly drafts created before `$createdBefore`, oldest first.
     *
     * @return list<string> the ids
     */
    public function staleWeeklyDraftIds(\DateTimeInterface $createdBefore): array;

    /**
     * `J-08`, §11.4: every non-draft report not yet archived whose submit (a Missed
     * report's day, having none) is before `$cutoff` becomes `archived`. Only those
     * move, so a second run moves none.
     *
     * @return array<string, string> the id of each report moved, with the status it had
     */
    public function archiveOlderThan(\DateTimeInterface $cutoff): array;
}
