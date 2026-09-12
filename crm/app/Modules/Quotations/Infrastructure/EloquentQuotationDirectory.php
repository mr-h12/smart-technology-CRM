<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Infrastructure;

use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationAdditionalLine;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationLine;
use App\Modules\Quotations\Domain\Listing\QuotationSummary;
use App\Modules\Quotations\Domain\Writing\QuotationDraft;
use App\Modules\Quotations\Infrastructure\Eloquent\Quotation;
use App\Support\Database\DocumentNumberAllocator;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

/**
 * {@see QuotationDirectoryInterface} over `quotations` —
 * `EloquentSupplierQuotationDirectory`'s shape (Module 6 Point 1.3), minus what
 * §3.5 has not decided yet.
 *
 * ── No scoped builder factory, because the scope column is not in this table ──
 *
 * Deals and Customers start every read from a `scoped()` factory, because
 * `SEC-08` is row-level security and a filter each method remembers to add is a
 * filter one method will forget. Here the column "own" is judged by, is
 * `deals.owner_id` (owner ruling 2026-09-11, Point 3.4), and a subquery on
 * another module's table is what `CLAUDE.md` forbids — so `find()` is
 * unscoped and `ShowQuotation` applies the scope through `DealFactsInterface`.
 * The interface's docblock records the same reasoning from the contract side.
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

    public function find(string $quotationId): ?QuotationDetail
    {
        $row = Quotation::query()->whereKey($quotationId)->first();

        if (! $row instanceof Quotation) {
            return null;
        }

        return new QuotationDetail(
            id: $row->id,
            code: $row->code,
            dealId: $row->deal_id,
            customerId: $row->customer_id,
            quotationDate: $row->quotation_date,
            validUntil: $row->valid_until,
            status: $row->status,
            currencyId: $row->currency_id,
            defaultMargin: $row->default_margin,
            discountPercent: $row->discount_percent,
            taxPercent: $row->tax_percent,
            roundingUnit: $row->rounding_unit,
            roundingEnabled: $row->rounding_enabled,
            subtotal: $row->subtotal,
            additionalTotal: $row->additional_total,
            discountAmount: $row->discount_amount,
            taxBase: $row->tax_base,
            taxAmount: $row->tax_amount,
            netAmount: $row->net_amount,
            totalBeforeRound: $row->total_before_round,
            finalTotal: $row->final_total,
            roundingDiff: $row->rounding_diff,
            paymentTerms: $row->payment_terms,
            warranty: $row->warranty,
            deliveryTerms: $row->delivery_terms,
            showDeliveryTerms: $row->show_delivery_terms,
            version: $row->version,
            parentId: $row->parent_id,
            rejectionReason: $row->rejection_reason,
            sentAt: $row->sent_at,
            isSelfApproved: $row->is_self_approved,
            versionToken: $row->version_token,
            createdBy: $row->created_by,
            updatedBy: $row->updated_by,
            createdAt: new DateTimeImmutable((string) $row->created_at?->toIso8601String()),
            updatedAt: new DateTimeImmutable((string) $row->updated_at?->toIso8601String()),
            items: $this->readItems($row->id),
            additionalItems: $this->readAdditionalItems($row->id),
        );
    }

    public function update(string $quotationId, QuotationDraft $draft, int $expectedToken, string $actorId): bool
    {
        // One statement carries the compare and the bump, so two writers that
        // both read token 3 cannot both pass: the row-level lock PostgreSQL
        // takes for the first UPDATE makes the second re-evaluate `WHERE`
        // against the committed token 4 and match nothing.
        $matched = Quotation::query()
            ->whereKey($quotationId)
            ->where('version_token', $expectedToken)
            ->update([
                ...$draft->attributes,
                'version_token' => $this->connection->raw('version_token + 1'),
                'updated_by' => $actorId,
            ]);

        if ($matched === 0) {
            return false;
        }

        $now = now();

        foreach (['quotation_items', 'quotation_additional_items'] as $table) {
            $this->connection->table($table)
                ->where('quotation_id', $quotationId)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now, 'updated_at' => $now, 'updated_by' => $actorId]);
        }

        $this->writeChildren('quotation_items', $quotationId, $draft->items, $actorId);
        $this->writeChildren('quotation_additional_items', $quotationId, $draft->additionalItems, $actorId);

        return true;
    }

    /**
     * Point 1.3's lines in `line_no` order, read as Module 6's `readLines()`
     * reads its table: no Eloquent model for a child row, and the decimal
     * columns as the text PostgreSQL returns — never cast, for `DB-07`'s reason.
     *
     * @return list<QuotationLine>
     */
    private function readItems(string $quotationId): array
    {
        $lines = [];

        foreach ($this->children('quotation_items', $quotationId) as $row) {
            $lines[] = new QuotationLine(
                id: self::text($row, 'id'),
                lineNo: self::int($row, 'line_no'),
                supplierQuotationItemId: self::text($row, 'supplier_quotation_item_id'),
                unitCost: self::text($row, 'unit_cost'),
                unitCostCurrency: self::text($row, 'unit_cost_currency'),
                unitCostFxRateAtTime: self::text($row, 'unit_cost_fx_rate_at_time'),
                unitCostBase: self::text($row, 'unit_cost_base'),
                marginPercent: self::nullableText($row, 'margin_percent'),
                unitPrice: self::text($row, 'unit_price'),
                quantity: self::text($row, 'quantity'),
                lineTotal: self::text($row, 'line_total'),
                lineCost: self::text($row, 'line_cost'),
            );
        }

        return $lines;
    }

    /** @return list<QuotationAdditionalLine> */
    private function readAdditionalItems(string $quotationId): array
    {
        $lines = [];

        foreach ($this->children('quotation_additional_items', $quotationId) as $row) {
            $lines[] = new QuotationAdditionalLine(
                id: self::text($row, 'id'),
                lineNo: self::int($row, 'line_no'),
                description: self::text($row, 'description'),
                amount: self::text($row, 'amount'),
            );
        }

        return $lines;
    }

    /** @return Collection<int, stdClass> */
    private function children(string $table, string $quotationId): Collection
    {
        return $this->connection->table($table)
            ->where('quotation_id', $quotationId)
            ->whereNull('deleted_at')
            ->orderBy('line_no')
            ->get();
    }

    /**
     * A `NOT NULL` text/uuid/numeric column, refused loudly if the driver hands
     * back anything else — `readLines()`'s reasoning: a `(string)` cast would
     * turn a float into a silently rounded price.
     */
    private static function text(stdClass $row, string $column): string
    {
        $value = $row->{$column} ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("{$column} did not come back as text.");
        }

        return $value;
    }

    /** `margin_percent`: null is "inherits the header's margin", so it is kept apart from the text. */
    private static function nullableText(stdClass $row, string $column): ?string
    {
        return ($row->{$column} ?? null) === null ? null : self::text($row, $column);
    }

    private static function int(stdClass $row, string $column): int
    {
        $value = $row->{$column} ?? null;

        if (! is_int($value)) {
            throw new RuntimeException("{$column} did not come back as an integer.");
        }

        return $value;
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
