<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain\Contracts;

use App\Modules\Reports\Domain\Report;

/**
 * The module's persistence port: `reports`, `report_versions`, `report_recipients`,
 * and `D-131`'s rows — kind `follow_up` (`report_follow_ups`) and `note` (`report_notes`).
 */
interface ReportDirectoryInterface
{
    /**
     * A new draft; answers its id and code.
     *
     * @return array{id: string, code: string}
     */
    public function createDraft(string $type, string $codePrefix, string $authorId, string $periodStart, string $periodEnd): array;

    /** `$lock` holds the row until the caller's transaction ends, so a row write and a submit never interleave. */
    public function find(string $reportId, bool $lock = false): ?Report;

    /** @return array{items: list<Report>, total: int} newest period first */
    public function forAuthor(string $authorId, int $page, int $perPage): array;

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
     * Submit under the token: status, the next frozen version and the
     * recipients — one live row per user, so a resubmit keeps who stays,
     * adds who is new and soft-removes who was dropped. False, and nothing
     * written, when the token moved.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $recipientIds
     */
    public function submit(string $reportId, int $versionToken, array $snapshot, array $recipientIds, string $actorId): bool;

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
    public function forRecipient(string $userId, int $page, int $perPage): array;

    /**
     * A recipient's answer under the token: the report moves to `$status`
     * (`viewed`, `acknowledged` or `returned`) and the newest version row
     * records who and when (and the return reason). False, and nothing
     * written, when the token moved.
     */
    public function receive(string $reportId, int $versionToken, string $status, ?string $returnReason, string $actorId): bool;
}
