<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Reading;

use DateTimeImmutable;

/**
 * One `audit_log` row as it is **read back** — the counterpart to
 * {@see \App\Modules\Audit\Domain\AuditEntry}, which is the shape going in.
 *
 * ── Why this is not `AuditEntry` ───────────────────────────────────────────
 *
 * `AuditEntry` is a write-side value object: it demands an `AuditContext`,
 * normalises the timestamp, and refuses a float because it is on its way to a
 * permanent row (`DB-07`, `D-30`). None of that applies to a row already
 * written — the float check would be re-litigating a decision the database has
 * already recorded, and the context fields a reader needs are two, not eight.
 * Reusing it would mean reconstructing a write-time invariant to answer a read.
 *
 * ── What a reader is given, and what it is not ─────────────────────────────
 *
 * `ip_address`, `user_agent`, `request_id` and `correlation_id` are on the row
 * and are **not** here. They are forensic fields for `AUD-05`'s structured log,
 * not fields a timeline shows a salesperson, and `SEC-10`'s reason for storing
 * the impersonator does not extend to publishing an IP address to whoever holds
 * `deal.view_timeline`. A reader that wants them can be given them by a later
 * point that states why.
 */
final readonly class AuditRecord
{
    /**
     * @param  array<array-key, mixed>|null  $oldValues
     * @param  array<array-key, mixed>|null  $newValues
     */
    public function __construct(
        public string $id,
        public string $event,
        public string $entityType,
        public string $entityId,
        public ?array $oldValues,
        public ?array $newValues,
        public ?string $actorId,
        public ?string $impersonatedUserId,
        public DateTimeImmutable $recordedAt,
    ) {}

    /**
     * One value out of `old_values`, or null when the payload never carried it.
     *
     * Every caller asking "what did `status` change from" would otherwise write
     * the same three-line null-and-type dance, and the third caller would write
     * it slightly differently. A non-string (a number, an array) answers null:
     * the question is what a reader can *display*, and a nested array is not it.
     */
    public function oldValue(string $key): ?string
    {
        return self::stringValue($this->oldValues, $key);
    }

    public function newValue(string $key): ?string
    {
        return self::stringValue($this->newValues, $key);
    }

    /** @param  array<array-key, mixed>|null  $values */
    private static function stringValue(?array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
