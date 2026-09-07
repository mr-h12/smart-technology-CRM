<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Infrastructure;

use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationSummary;
use App\Modules\Quotations\Domain\Writing\QuotationDraft;
use App\Modules\Quotations\Infrastructure\Eloquent\Quotation;
use App\Support\Database\DocumentNumberAllocator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * {@see QuotationDirectoryInterface} over `quotations` —
 * `EloquentSupplierQuotationDirectory`'s shape (Module 6 Point 1.3), minus what
 * §3.5 has not decided yet.
 *
 * ── No scoped builder factory, because there is nothing to scope ───────────
 *
 * Deals and Customers start every read from a `scoped()` factory, because
 * `SEC-08` is row-level security and a filter each method remembers to add is a
 * filter one method will forget. This class has no read: `create()` writes a
 * row the caller just described, and there is no row of somebody else's to
 * leak. The factory arrives with Step 3's `find()`, which is also where §3.5's
 * "own" is finally defined.
 *
 * ── The transaction is not opened here ────────────────────────────────────
 *
 * `CreateQuotation` will own it (Step 3), because `DB-11` puts the audit row
 * and the two child tables inside the same commit and this class has no audit
 * vocabulary — the same division `CreateSupplierQuotation` and
 * `EloquentSupplierQuotationDirectory` already draw.
 *
 * ── The prefix is this module's; the mechanism is not ─────────────────────
 *
 * `QT` is `§4.7`'s third document prefix. It is allocated through
 * {@see DocumentNumberAllocator} (Point 1.6), the shared `INSERT … ON CONFLICT
 * … RETURNING` on `document_sequences` that `DL` and `SQ` already use, so two
 * simultaneous creates serialise on one row rather than racing to the same
 * number and failing the `quotations_code_unique` index.
 */
final readonly class EloquentQuotationDirectory implements QuotationDirectoryInterface
{
    /** §4.7: "QT-2026-0001". */
    private const CODE_PREFIX = 'QT';

    public function __construct(private ConnectionInterface $connection) {}

    public function create(QuotationDraft $draft, string $actorId): QuotationSummary
    {
        $row = new Quotation;
        $row->fill($draft->attributes);

        $row->code = (new DocumentNumberAllocator($this->connection, self::CODE_PREFIX))->next();
        $row->created_by = $actorId;
        $row->updated_by = $actorId;
        $row->save();

        $this->writeChildren('quotation_items', $row->id, $draft->items, $actorId);
        $this->writeChildren('quotation_additional_items', $row->id, $draft->additionalItems, $actorId);

        return new QuotationSummary(id: $row->id, code: $row->code);
    }

    /**
     * Points 1.3 and 1.4's child tables, batched like Module 6's `writeLines()`
     * — no Eloquent model, one `now()` for the batch, and the id, foreign key
     * and `DB-02` actor filled here because `insert()` bypasses the model that
     * would have filled them. One method serves both tables: they share this
     * envelope and differ only in the columns each `$row` already carries, which
     * the use case (Step 3) decides — this class spreads them without inspection.
     *
     * ── `line_no` is positional, and that is the whole of Module 6's difference ──
     *
     * `quotation_items` and `quotation_additional_items` both declare `line_no`
     * NOT NULL as §10's "display order", where `supplier_quotation_items` had no
     * such column. Nothing else records the order the lines were built in, so the
     * order they were handed in is it — 1-based. A user-reorderable list is a
     * later point and already on `CHECKLIST.md`'s debt register; this is the
     * honest most the write can offer today.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeChildren(string $table, string $quotationId, array $rows, string $actorId): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        $insert = [];

        foreach ($rows as $index => $row) {
            $insert[] = [
                'id' => Str::uuid()->toString(),
                'quotation_id' => $quotationId,
                'line_no' => $index + 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
                ...$row,
            ];
        }

        $this->connection->table($table)->insert($insert);
    }
}
