<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Contracts;

use App\Modules\Audit\Domain\Reading\AuditRecordPage;

/**
 * The read port — the half {@see AuditEntryWriterInterface} deliberately does
 * not offer.
 *
 * ── Why a reader exists at all, and why only now ───────────────────────────
 *
 * `AUD-03` and `D-30` make an audit row immutable and permanent, and the
 * writer's docblock says so: an interface offering `update` or `delete` would
 * describe operations the database exists to reject. **A read is not one of
 * those operations.** The trigger installed by Point 6.2 refuses `UPDATE`,
 * `DELETE` and `TRUNCATE` with SQLSTATE `AUD03`; `SELECT` is untouched, and
 * always was. Append-only is not write-only, and reading a permanent record is
 * the reason it is permanent.
 *
 * Nothing needed it until now. Module 5 Point 1.1 declined to build a
 * `deal_status_history` table on the grounds that `audit_log` already stores
 * §4.4's "old status · new status · who · when" verbatim, and that a second
 * table recording the same fact is the defect `DB-11` and `AUD-02` exist to
 * prevent. That reading stands — and it owes this interface, because §3.4
 * seeds `deal.view_timeline` to all seven roles and, until this point, no route
 * anywhere could check it.
 *
 * ── Scope is the caller's problem, on purpose ──────────────────────────────
 *
 * This port takes an entity type and an id and answers with rows about that
 * entity. It performs **no** authorization: `SEC-08`'s row scoping is the
 * calling module's, because only that module knows what reaching one of its own
 * rows means. A caller must decide the row is visible *before* asking — the
 * order `ChangeDealStatus` already uses for `deal.mark_delivery_complete`.
 */
interface AuditEntryReaderInterface
{
    /**
     * The entity's records, newest first.
     *
     * Newest first is the port's promise rather than the caller's option: a
     * timeline read in insertion order would put the oldest event on page one
     * and the fact anybody actually wants — what just happened — on the last
     * page they will never open.
     *
     * @param  positive-int  $page
     * @param  positive-int  $perPage
     */
    public function forEntity(string $entityType, string $entityId, int $page, int $perPage): AuditRecordPage;
}
