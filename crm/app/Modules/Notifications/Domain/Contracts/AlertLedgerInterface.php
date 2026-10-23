<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Contracts;

/**
 * Module 16 (`D-153`) — the jobs' side of the `alerts` table: raising and clearing.
 * Readers use {@see AlertInboxInterface}.
 */
interface AlertLedgerInterface
{
    /**
     * Make the open alerts of `$kind` exactly `$wanted`, raised once (§18.3,
     * "escalate once"): an alert not yet open is raised; one already open keeps its
     * counter and read state and takes the new details; an open one not wanted any
     * more is cleared, its row kept. Alerts of any other kind are untouched.
     *
     * @param  list<array{user_id: string, subject_id: string|null, details: array<string, string>}>  $wanted  a `null` subject is an alert with no record of its own (storage)
     * @return array{raised: int, cleared: int}
     */
    public function keepOpen(string $kind, array $wanted): array;

    /**
     * Something happened again (a lock, a failure): for each of `$userIds`, the open
     * alert of `$kind` on `$subjectId` takes one more occurrence, the new details, a
     * new `last_raised_at`, and is unread again — a new event is news, not a
     * reminder; with none open, one is raised. A `null` subject is an alert with no
     * record of its own (a backup).
     *
     * @param  list<string>  $userIds
     * @param  array<string, string>  $details
     */
    public function occurred(string $kind, ?string $subjectId, array $userIds, array $details): void;

    /**
     * Clear the open alerts of `$kind` whose subject is not in `$stillTrue`, rows kept.
     *
     * @param  list<string>  $stillTrue
     * @return int how many were cleared
     */
    public function clearAllBut(string $kind, array $stillTrue): int;
}
