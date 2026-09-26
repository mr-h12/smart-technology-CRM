<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Infrastructure;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderListCriteria;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderPage;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderRecord;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderSummary;
use App\Modules\Quotations\Domain\Listing\QuotationAdditionalLine;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationLine;
use App\Modules\Quotations\Domain\Listing\QuotationListCriteria;
use App\Modules\Quotations\Domain\Listing\QuotationPage;
use App\Modules\Quotations\Domain\Listing\QuotationSummary;
use App\Modules\Quotations\Domain\Writing\QuotationDraft;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use App\Modules\Quotations\Infrastructure\Eloquent\PurchaseOrder;
use App\Modules\Quotations\Infrastructure\Eloquent\Quotation;
use App\Support\Database\DocumentNumberAllocator;
use App\Support\Search\SearchIndex;
use App\Support\Search\SearchService;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
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

    public function __construct(
        private ConnectionInterface $connection,
        private DealFactsInterface $deals,
        private CurrencyRepositoryInterface $currencies,
        private SearchService $search,
    ) {}

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

        // `save()` does not read column defaults back (`version`, `status`).
        $row->refresh();

        return $this->summary($row, $this->currencyCode($row->currency_id));
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
            currency: $this->currencyCode($row->currency_id),
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
            submittedAt: $row->submitted_at?->toIso8601String(),
            returnedAt: $row->returned_at?->toIso8601String(),
            returnNote: $row->return_note,
            isSelfApproved: $row->is_self_approved,
            versionToken: $row->version_token,
            createdBy: $row->created_by,
            updatedBy: $row->updated_by,
            createdAt: new DateTimeImmutable((string) $row->created_at?->toIso8601String()),
            updatedAt: new DateTimeImmutable((string) $row->updated_at?->toIso8601String()),
            items: $this->readItems($row->id),
            additionalItems: $this->readAdditionalItems($row->id),
            purchaseOrder: self::purchaseOrderOf($row->id),
        );
    }

    public function update(string $quotationId, QuotationDraft $draft, int $expectedToken, string $actorId): bool
    {
        // One statement carries the compare and the bump, so two writers that
        // both read token 3 cannot both pass: the row-level lock PostgreSQL
        // takes for the first UPDATE makes the second re-evaluate `WHERE`
        // against the committed token 4 and match nothing.
        $matched = $this->lockedRow($quotationId, $expectedToken)
            ->update([
                ...$draft->attributes,
                'version_token' => $this->connection->raw('version_token + 1'),
                'updated_by' => $actorId,
            ]);

        if ($matched === 0) {
            return false;
        }

        $this->softDeleteChildren($quotationId, $actorId);

        $this->writeChildren('quotation_items', $quotationId, $draft->items, $actorId);
        $this->writeChildren('quotation_additional_items', $quotationId, $draft->additionalItems, $actorId);

        return true;
    }

    public function moveStatus(string $quotationId, string $status, int $expectedToken, string $actorId, array $attributes = []): bool
    {
        // `update()`'s guard, without the children: a transition changes no line.
        return $this->lockedRow($quotationId, $expectedToken)
            ->update([
                ...$attributes,
                'status' => $status,
                'version_token' => $this->connection->raw('version_token + 1'),
                'updated_by' => $actorId,
            ]) === 1;
    }

    public function lockStatusesOfDeal(string $dealId): array
    {
        $statuses = [];

        // `SoftDeletes` keeps a deleted draft out: it is not live (Q12).
        foreach (Quotation::query()->where('deal_id', $dealId)->orderBy('id')->lockForUpdate()->get(['id', 'status']) as $row) {
            $statuses[(string) $row->id] = (string) $row->status;
        }

        return $statuses;
    }

    public function expireSentBefore(string $today): array
    {
        $rows = $this->connection->select(
            "UPDATE quotations SET status = 'expired', version_token = version_token + 1, updated_by = NULL, updated_at = now()
             WHERE status = 'sent' AND valid_until < ? AND deleted_at IS NULL
             RETURNING id",
            [$today],
        );

        $ids = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass && is_string($row->id)) {
                $ids[] = $row->id;
            }
        }

        return $ids;
    }

    public function createPurchaseOrder(string $quotationId, string $customerPoReference, string $poDate, string $actorId): PurchaseOrderSummary
    {
        $order = new PurchaseOrder;
        $order->fill(['quotation_id' => $quotationId, 'customer_po_reference' => $customerPoReference, 'po_date' => $poDate]);
        $order->po_number = (new DocumentNumberAllocator($this->connection, 'PO'))->next();
        $order->created_by = $actorId;
        $order->updated_by = $actorId;
        $order->save();

        return new PurchaseOrderSummary($order->id, $quotationId, $order->po_number, $customerPoReference, $poDate);
    }

    public function delete(string $quotationId, int $expectedToken, string $actorId): bool
    {
        // `update()`'s guard; `SoftDeletes::delete()` would skip it.
        $matched = $this->lockedRow($quotationId, $expectedToken)
            ->update(['deleted_at' => now(), 'updated_by' => $actorId]);

        if ($matched === 0) {
            return false;
        }

        $this->softDeleteChildren($quotationId, $actorId);

        return true;
    }

    public function list(QuotationListCriteria $criteria, QuotationRowScope $scope): QuotationPage
    {
        // `OpenAPI §5.1`: a reach of nothing is answered before any read.
        if ($scope->permitsNothing()) {
            return new QuotationPage([], 0, $criteria->page, $criteria->perPage);
        }

        $currencyId = null;
        if ($criteria->currency !== null) {
            $code = CurrencyCode::tryFrom($criteria->currency);
            $currencyId = $code === null ? null : $this->currencies->find($code)?->id();

            // A code no currency has matches nothing — an empty page, not a 400.
            if ($currencyId === null) {
                return new QuotationPage([], 0, $criteria->page, $criteria->perPage);
            }
        }

        $query = Quotation::query();

        // `SEC-08` in the query: `own` is the deal's `owner_id`, reached as a
        // set through the seam — never a join on `deals` (module isolation).
        if (! $scope->unrestricted) {
            $query->whereIn('quotations.deal_id', $this->reach($scope));
        }

        // Q2: `filter[employee]` intersects the same way — two `IN`s on one column.
        if ($criteria->employeeId !== null) {
            $query->whereIn('quotations.deal_id', $this->deals->dealIdsOwnedBy($criteria->employeeId));
        }

        if ($criteria->statuses !== []) {
            $query->whereIn('quotations.status', $criteria->statuses);
        }

        if ($criteria->bucket === QuotationListCriteria::INCOMPLETE_BUCKET) {
            $query->where('quotations.status', 'draft')->whereNotNull('quotations.returned_at');
        } elseif ($criteria->bucket !== null) {
            $query->whereIn('quotations.status', QuotationListCriteria::BUCKETS[$criteria->bucket]);
        }

        if ($criteria->customerId !== null) {
            $query->where('quotations.customer_id', $criteria->customerId);
        }

        if ($criteria->dealId !== null) {
            $query->where('quotations.deal_id', $criteria->dealId);
        }

        if ($currencyId !== null) {
            $query->where('quotations.currency_id', $currencyId);
        }

        // Q3: bounds compare as NUMERIC against the string the caller sent (`DB-07`).
        if ($criteria->amountMin !== null) {
            $query->where('quotations.final_total', '>=', $criteria->amountMin);
        }

        if ($criteria->amountMax !== null) {
            $query->where('quotations.final_total', '<=', $criteria->amountMax);
        }

        // Q4: inclusive on both ends.
        if ($criteria->from !== null) {
            $query->where('quotations.quotation_date', '>=', $criteria->from);
        }

        if ($criteria->to !== null) {
            $query->where('quotations.quotation_date', '<=', $criteria->to);
        }

        // `OpenAPI §6.1`: "Pagination always happens after authorization
        // scoping" — counted from the scoped builder, not a fresh one.
        $total = $query->count();

        foreach ($criteria->sorts as $sort) {
            $query->orderBy('quotations.'.$sort['field'], $sort['descending'] ? 'desc' : 'asc');
        }

        // A deterministic tiebreak, as `EloquentDealDirectory::list()` has.
        $query->orderBy('quotations.id');

        // One code lookup per currency on the page, not per row.
        $rows = $query->offset($criteria->offset())->limit($criteria->perPage)->get();
        /** @var array<string, string> $codes */
        $codes = [];
        foreach ($rows as $row) {
            $codes[$row->currency_id] ??= $this->currencyCode($row->currency_id);
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->summary($row, $codes[$row->currency_id]);
        }

        return new QuotationPage($items, $total, $criteria->page, $criteria->perPage);
    }

    public function purchaseOrders(PurchaseOrderListCriteria $criteria, QuotationRowScope $scope): PurchaseOrderPage
    {
        // Q10: an order is reached through its quotation — `Quotation`'s
        // soft-delete scope rides the subquery, so a deleted quotation's
        // order goes with it.
        $quotations = Quotation::query()->select('quotations.id');
        if (! $scope->unrestricted) {
            $quotations->whereIn('quotations.deal_id', $this->reach($scope));
        }

        $query = PurchaseOrder::query()->whereIn('purchase_orders.quotation_id', $quotations);

        if ($criteria->q !== null) {
            $query->whereIn('purchase_orders.id', $this->search->search(SearchIndex::PurchaseOrders, $criteria->q));
        }

        $total = $query->count();

        foreach ($criteria->sorts as $sort) {
            $query->orderBy('purchase_orders.'.$sort['field'], $sort['descending'] ? 'desc' : 'asc');
        }
        $query->orderBy('purchase_orders.id');

        $orders = $query->offset($criteria->offset())->limit($criteria->perPage)->get();
        $byId = Quotation::query()->whereIn('id', $orders->pluck('quotation_id')->all())->get()->keyBy('id');

        /** @var array<string, string> $codes */
        $codes = [];
        $items = [];
        foreach ($orders as $order) {
            $quotation = $byId->get($order->quotation_id);
            if (! $quotation instanceof Quotation) {
                throw new RuntimeException("purchase_orders {$order->id} lost its quotation between two reads.");
            }
            $codes[$quotation->currency_id] ??= $this->currencyCode($quotation->currency_id);
            $items[] = self::record($order, $quotation, $codes[$quotation->currency_id]);
        }

        return new PurchaseOrderPage($items, $total, $criteria->page, $criteria->perPage);
    }

    public function findPurchaseOrder(string $purchaseOrderId): ?PurchaseOrderRecord
    {
        // A malformed id never gets here: `{purchaseOrder}` matches only a UUID
        // (`routes/api.php`, F-17 · 1.2).
        $order = PurchaseOrder::query()->whereKey($purchaseOrderId)->first();
        $quotation = $order instanceof PurchaseOrder ? Quotation::query()->whereKey($order->quotation_id)->first() : null;

        return $order instanceof PurchaseOrder && $quotation instanceof Quotation
            ? self::record($order, $quotation, $this->currencyCode($quotation->currency_id))
            : null;
    }

    /**
     * `SEC-08`'s `own` as a set of deal ids through the seam — never a join on `deals`.
     *
     * @return list<string>
     */
    private function reach(QuotationRowScope $scope): array
    {
        $reach = [];
        foreach ($scope->ownerIds as $ownerId) {
            $reach = [...$reach, ...$this->deals->dealIdsOwnedBy($ownerId)];
        }

        return $reach;
    }

    private static function purchaseOrderOf(string $quotationId): ?PurchaseOrderSummary
    {
        $order = PurchaseOrder::query()->where('quotation_id', $quotationId)->first();

        return $order instanceof PurchaseOrder
            ? new PurchaseOrderSummary($order->id, $quotationId, $order->po_number, $order->customer_po_reference, $order->po_date)
            : null;
    }

    private static function record(PurchaseOrder $order, Quotation $quotation, string $currency): PurchaseOrderRecord
    {
        return new PurchaseOrderRecord(
            id: $order->id,
            poNumber: $order->po_number,
            customerPoReference: $order->customer_po_reference,
            poDate: $order->po_date,
            createdAt: (string) $order->created_at?->toIso8601String(),
            createdBy: $order->created_by,
            quotationId: $quotation->id,
            quotationCode: $quotation->code,
            quotationStatus: $quotation->status,
            customerId: $quotation->customer_id,
            dealId: $quotation->deal_id,
            currency: $currency,
            subtotal: $quotation->subtotal,
            additionalTotal: $quotation->additional_total,
            discountAmount: $quotation->discount_amount,
            taxPercent: $quotation->tax_percent,
            taxAmount: $quotation->tax_amount,
            finalTotal: $quotation->final_total,
        );
    }

    /** Q6's row — §6.6's columns, nothing from the cost side. */
    private function summary(Quotation $row, string $currency): QuotationSummary
    {
        return new QuotationSummary(
            id: $row->id,
            code: $row->code,
            version: $row->version,
            status: $row->status,
            customerId: $row->customer_id,
            dealId: $row->deal_id,
            currencyId: $row->currency_id,
            currency: $currency,
            finalTotal: $row->final_total,
            quotationDate: $row->quotation_date,
            validUntil: $row->valid_until,
            submittedAt: $row->submitted_at?->toIso8601String(),
            isSelfApproved: $row->is_self_approved,
            parentId: $row->parent_id,
            createdAt: new DateTimeImmutable((string) $row->created_at?->toIso8601String()),
            updatedAt: new DateTimeImmutable((string) $row->updated_at?->toIso8601String()),
        );
    }

    /**
     * Module 7 Point 6.3 (owner's ruling A, 2026-09-13): the row names its
     * currency, because `GET /currencies` is `admin.system_settings`' and the
     * SPA has nothing to join `currency_id` against. `quotations.currency_id`
     * is a foreign key, so a missing currency is unreachable; refusing loudly
     * rather than answering the id as a code is `DB-07`'s habit.
     */
    private function currencyCode(string $currencyId): string
    {
        $currency = $this->currencies->findById($currencyId);

        if ($currency === null) {
            throw new RuntimeException("quotations.currency_id {$currencyId} names no currency.");
        }

        return $currency->code()->value;
    }

    /**
     * `DB-12`'s compare, as the `WHERE` of every write that bumps or ends the
     * row: matching nothing is the stale answer.
     *
     * @return Builder<Quotation>
     */
    private function lockedRow(string $quotationId, int $expectedToken): Builder
    {
        return Quotation::query()
            ->whereKey($quotationId)
            ->where('version_token', $expectedToken);
    }

    /** `DB-01`: both child tables marked, never removed — `update()` before replacing lines, `delete()` for good. */
    private function softDeleteChildren(string $quotationId, string $actorId): void
    {
        $now = now();

        foreach (['quotation_items', 'quotation_additional_items'] as $table) {
            $this->connection->table($table)
                ->where('quotation_id', $quotationId)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now, 'updated_at' => $now, 'updated_by' => $actorId]);
        }
    }

    public function copy(string $parentId, string $actorId): QuotationSummary
    {
        $parent = Quotation::query()->whereKey($parentId)->first();

        if (! $parent instanceof Quotation) {
            // The use case read this row inside the same transaction.
            throw new RuntimeException("Quotation {$parentId} vanished before it could be copied.");
        }

        // Eloquent's `replicate()` copies the raw attributes as loaded — the
        // decimals as PostgreSQL returned them, never through a float — minus
        // the key and the timestamps; the answer's own marks are listed here.
        $copy = $parent->replicate([
            'code', 'status', 'version', 'parent_id', 'version_token',
            'rejection_reason', 'sent_at', 'submitted_at', 'is_self_approved',
            'created_by', 'updated_by', 'deleted_at', 'returned_at', 'return_note',
        ]);
        $copy->code = (new DocumentNumberAllocator($this->connection, self::CODE_PREFIX))->next();
        $copy->parent_id = $parentId;
        $copy->version = $parent->version + 1;
        $copy->status = 'draft';
        $copy->created_by = $actorId;
        $copy->updated_by = $actorId;

        try {
            $copy->save();
        } catch (QueryException $refused) {
            // 23505 is `unique_violation`; on this insert only
            // `quotations_version_unique_alive` can raise it (the code was just
            // allocated, the id just generated).
            if ($refused->getCode() === '23505') {
                throw QuotationWriteRefused::versionExists();
            }

            throw $refused;
        }

        $items = [];
        foreach ($this->readItems($parentId) as $line) {
            $items[] = $line->asRow();
        }
        $additional = [];
        foreach ($this->readAdditionalItems($parentId) as $line) {
            $additional[] = ['description' => $line->description, 'amount' => $line->amount];
        }

        $this->writeChildren('quotation_items', $copy->id, $items, $actorId);
        $this->writeChildren('quotation_additional_items', $copy->id, $additional, $actorId);

        $copy->refresh();

        return $this->summary($copy, $this->currencyCode($copy->currency_id));
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
