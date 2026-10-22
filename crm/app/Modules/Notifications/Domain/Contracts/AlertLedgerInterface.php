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
     * @param  list<array{user_id: string, subject_id: string, details: array<string, string>}>  $wanted
     * @return array{raised: int, cleared: int}
     */
    public function keepOpen(string $kind, array $wanted): array;
}
