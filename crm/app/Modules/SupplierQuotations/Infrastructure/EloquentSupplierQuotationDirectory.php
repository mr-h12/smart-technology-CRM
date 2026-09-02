<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Infrastructure;

use App\Modules\SupplierQuotations\Domain\Contracts\SupplierQuotationDirectoryInterface;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationSummary;
use App\Modules\SupplierQuotations\Domain\Writing\SupplierQuotationDraft;
use App\Modules\SupplierQuotations\Infrastructure\Eloquent\SupplierQuotation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * {@see SupplierQuotationDirectoryInterface} over `supplier_quotations` —
 * `EloquentDealDirectory`'s shape (Module 5 Point 2.3), minus the two things
 * §3.6 removes: there is no scoped builder factory and no `SearchService`
 * collaborator, because every role reads this resource with `Scope::All` and
 * nothing searches it until Step 4.
 *
 * ── The lines go in with one statement, and with no model of their own ─────
 *
 * Point 2.1. `supplier_quotation_items` has no Eloquent class: the only thing
 * this module does with a line is insert a batch of them and, from Point 2.2,
 * read them back — neither needs casts, events or a soft-delete trait, and a
 * model added for a second use nobody has asked for is the speculative file
 * `CLAUDE.md`'s waste audit names. `insert()` with the whole batch is also one
 * round trip where a model would be one per line.
 *
 * The transaction is **not** opened here. `CreateSupplierQuotation` owns it,
 * because `DB-11` puts the audit row inside the same commit and this class has
 * no audit vocabulary — the same division `SaveDeal` and `EloquentDealDirectory`
 * already draw.
 */
final readonly class EloquentSupplierQuotationDirectory implements SupplierQuotationDirectoryInterface
{
    /** §4.7: "SQ-2026-0001". */
    private const CODE_PREFIX = 'SQ';

    public function __construct(private ConnectionInterface $connection) {}

    public function create(SupplierQuotationDraft $draft, string $actorId): SupplierQuotationSummary
    {
        $row = new SupplierQuotation;
        $row->fill($draft->attributes);

        $row->code = $this->nextCode();
        $row->created_by = $actorId;
        $row->updated_by = $actorId;
        $row->save();

        $this->writeLines($row->id, $draft->items, $actorId);

        return self::hydrate($row);
    }

    /**
     * §7.2's lines, into Point 1.2's table.
     *
     * No `id` from the caller and no timestamps either: `insert()` bypasses the
     * model, so everything `standardColumns()` would have filled is filled here
     * instead. `DB-02`'s actor is the use case's on a line exactly as on the
     * header — a line has no separate author.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function writeLines(string $quotationId, array $items, string $actorId): void
    {
        if ($items === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'id' => Str::uuid()->toString(),
                'supplier_quotation_id' => $quotationId,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
                ...$item,
            ];
        }

        $this->connection->table('supplier_quotation_items')->insert($rows);
    }

    /**
     * §4.7's `SQ-YYYY-NNNN`, allocated from `document_sequences` (Module 0) —
     * its second consumer, and deliberately the same statement `DL`'s uses.
     *
     * ── Why this is one statement and not a read-then-write ────────────────
     *
     * `SELECT last_value` followed by `UPDATE … SET last_value = ? + 1` lets
     * two concurrent creates read the same value and both write the same next
     * one — the duplicate `supplier_quotations_code_unique` exists to catch,
     * except that catching it there means the second caller's request fails
     * with a constraint violation instead of succeeding with the number it
     * should have gotten. `INSERT … ON CONFLICT … DO UPDATE … RETURNING` is one
     * round trip PostgreSQL executes under a single row lock, so the two
     * callers serialise on that row instead of racing past it.
     *
     * The year comes from `now()` rather than from the request, and the table
     * keys on `(prefix, year)` (Module 0) — so `DL`'s count is not this one's,
     * and a create crossing midnight on 31 December starts a new count on
     * 1 January rather than 2026 counting forever.
     */
    private function nextCode(): string
    {
        $year = (int) now()->format('Y');

        $row = $this->connection->selectOne(
            'insert into document_sequences (prefix, year, last_value) values (?, ?, 1) '
            .'on conflict (prefix, year) do update '
            .'set last_value = document_sequences.last_value + 1 '
            .'returning last_value',
            [self::CODE_PREFIX, $year],
        );

        $value = is_object($row) ? ($row->last_value ?? null) : null;

        if (! is_int($value) && ! is_string($value)) {
            // Unreachable in production — the statement above always returns
            // exactly one row — and refusing loudly here is cheaper than a code
            // silently formatted as "SQ-2026-".
            throw new RuntimeException('document_sequences did not return a last_value for SQ.');
        }

        return sprintf('%s-%d-%04d', self::CODE_PREFIX, $year, (int) $value);
    }

    private static function hydrate(SupplierQuotation $row): SupplierQuotationSummary
    {
        return new SupplierQuotationSummary(
            id: $row->id,
            code: $row->code,
            supplierId: $row->supplier_id,
            dealId: $row->deal_id,
            totalPrice: $row->total_price,
            currencyId: $row->currency_id,
            offerDate: $row->offer_date,
            validUntil: $row->valid_until,
            notes: $row->notes,
        );
    }
}
