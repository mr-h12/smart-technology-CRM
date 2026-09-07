<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * §4.7's `PREFIX-YYYY-NNNN` document number, allocated from `document_sequences`
 * (Module 0).
 *
 * ── Why this is shared rather than copied a third time ─────────────────────
 *
 * `DL` (Module 5) and `SQ` (Module 6) each carried a byte-identical private
 * `nextCode()`, and Module 7's `QT` would have been the third. The prefix is
 * the module's; the mechanism is not — so the mechanism moved here, on
 * `Precision`'s terms: one class named in both deptrac configs' narrow
 * `SharedContracts` layer, not a licence over `App\Support\Database` at large.
 *
 * ── Why this is one statement and not a read-then-write ────────────────────
 *
 * `SELECT last_value` followed by `UPDATE … SET last_value = ? + 1` lets two
 * concurrent creates read the same value and both write the same next one —
 * what each caller's `…_code_unique` index exists to catch, except that
 * catching it there means the second caller's request fails with a constraint
 * violation instead of succeeding with the number it should have gotten.
 * `INSERT … ON CONFLICT … DO UPDATE … RETURNING` is one round trip PostgreSQL
 * executes under a single row lock, so the two callers serialise on that row
 * instead of racing past it.
 *
 * The year comes from `now()` rather than from the request, and the table keys
 * on `(prefix, year)` (Module 0) — so `DL`'s count is not `SQ`'s, and a create
 * crossing midnight on 31 December starts a new count on 1 January rather than
 * 2026 counting forever.
 */
final readonly class DocumentNumberAllocator
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $prefix,
    ) {}

    public function next(): string
    {
        $year = (int) now()->format('Y');

        $row = $this->connection->selectOne(
            'insert into document_sequences (prefix, year, last_value) values (?, ?, 1) '
            .'on conflict (prefix, year) do update '
            .'set last_value = document_sequences.last_value + 1 '
            .'returning last_value',
            [$this->prefix, $year],
        );

        $value = is_object($row) ? ($row->last_value ?? null) : null;

        if (! is_int($value) && ! is_string($value)) {
            // Unreachable in production — the statement above always returns
            // exactly one row — and refusing loudly here is cheaper than a code
            // silently formatted as "SQ-2026-".
            throw new RuntimeException(
                sprintf('document_sequences did not return a last_value for %s.', $this->prefix),
            );
        }

        return sprintf('%s-%d-%04d', $this->prefix, $year, (int) $value);
    }
}
