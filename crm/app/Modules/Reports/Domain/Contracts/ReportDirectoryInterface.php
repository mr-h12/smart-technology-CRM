<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain\Contracts;

use App\Modules\Reports\Domain\Report;

/** The module's persistence port: `reports`, `report_versions`, `report_recipients`. */
interface ReportDirectoryInterface
{
    /**
     * A new draft; answers its id and code.
     *
     * @return array{id: string, code: string}
     */
    public function createDraft(string $type, string $codePrefix, string $authorId, string $periodStart, string $periodEnd): array;

    public function find(string $reportId): ?Report;

    /** @return array{items: list<Report>, total: int} newest period first */
    public function forAuthor(string $authorId, int $page, int $perPage): array;

    /** False when the token moved or the report left Draft — nothing was written. */
    public function updateNotes(string $reportId, int $versionToken, ?string $notes, string $actorId): bool;

    /**
     * Submit under the token: status, the next frozen version and the
     * recipients. False, and nothing written, when the token moved.
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

    /** @return list<string> */
    public function recipientIdsOf(string $reportId): array;
}
