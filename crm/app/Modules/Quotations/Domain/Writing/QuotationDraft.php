<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Writing;

/**
 * The fields that may be written on a customer quotation —
 * `SupplierQuotationDraft`'s shape (Module 6 Point 1.3), on the same reasoning.
 *
 * ── Nine of §10's columns are absent, each for a documented reason ─────────
 *
 * | column | why it is not here |
 * |---|---|
 * | `code` | "Automatic" (§4.7) — {@see \App\Modules\Quotations\Infrastructure\EloquentQuotationDirectory} allocates `QT-YYYY-NNNN`, and a setter would let a caller collide with `document_sequences`. |
 * | `created_by` / `updated_by` | `DB-02`'s actor, taken from what the use case authenticated, never from a request body. |
 * | `status` | A state machine, not a field. The column defaults to `draft` and every move out of it is Step 4's transition with its own rule and its own audit row. |
 * | `version` / `parent_id` | `D-08`'s version chain. A copy is made by `POST /{id}/new-version` (Step 4), which is the only thing allowed to set either. |
 * | `version_token` | `DB-12`'s optimistic lock. It is advanced by a write, never supplied by one — a caller that could set it could defeat `API-12`'s `409`. |
 * | `rejection_reason` / `sent_at` / `is_self_approved` | Approval and delivery facts (Steps 4 and 8), recorded by the transition that causes them. |
 *
 * ── The money is here, and it is still not the caller's ───────────────────
 *
 * `subtotal` through `rounding_diff`, and `rounding_unit`/`rounding_enabled`
 * with them, are `NOT NULL` with no default and bound to each other by Point
 * 1.2's `CHECK`s, so a row cannot be written without them. They are listed here
 * because the **directory** must be able to write them — not because a caller
 * may send them. §5 is explicit that "all prices are calculated in the backend"
 * and the UI "may preview but never be the source of truth": the values come
 * from Step 2's pricing engine, handed in by Step 3's `CreateQuotation`, and
 * the Form Request in front of it never validates them, so `forCreate()` never
 * sees them in a caller's payload.
 *
 * This is the same division Module 6 drew and the opposite outcome, because the
 * rule is different: `supplier_quotations.total_price` is user-entered by the
 * owner's ruling of 2026-09-02, and none of these is.
 *
 * ── One named constructor, not two ────────────────────────────────────────
 *
 * `forUpdate()` is absent for the reason Module 6's Point 1.3 published
 * `create()` alone: `PATCH /{quotation_id}` is Step 3, it is Draft-only and
 * carries `If-Match` (`OpenAPI §7.1`, §9.2), and an update shape guessed here
 * is a promise that point would have to keep without having chosen it.
 */
final readonly class QuotationDraft
{
    /**
     * §10's columns, minus the nine the table above explains.
     *
     * `currency_id` and not a currency code: the column is an id, because
     * `currencies.code` is unique only among live rows and PostgreSQL cannot
     * point a foreign key at a partial unique index (Point 1.1).
     */
    public const WRITABLE_ON_CREATE = [
        'deal_id',
        'customer_id',
        'quotation_date',
        'valid_until',
        'currency_id',
        'default_margin',
        'discount_percent',
        'tax_percent',
        'rounding_unit',
        'rounding_enabled',
        'subtotal',
        'additional_total',
        'discount_amount',
        'tax_base',
        'tax_amount',
        'net_amount',
        'total_before_round',
        'final_total',
        'rounding_diff',
        'payment_terms',
        'warranty',
        'delivery_terms',
        'show_delivery_terms',
    ];

    /**
     * @param  array<string, mixed>  $attributes  already validated at the boundary
     * @param  list<array<string, mixed>>  $items  `quotation_items` rows, priced (see below)
     * @param  list<array<string, mixed>>  $additionalItems  `quotation_additional_items` rows
     */
    private function __construct(
        public array $attributes,
        public array $items = [],
        public array $additionalItems = [],
    ) {}

    /** @param  array<string, mixed>  $validated */
    public static function forCreate(array $validated): self
    {
        $kept = [];

        foreach (self::WRITABLE_ON_CREATE as $key) {
            // array_key_exists and not `??`: `warranty: null` is an erasure the
            // caller asked for, and `??` would silently drop it —
            // `SupplierQuotationDraft` made the same choice for the same reason.
            if (array_key_exists($key, $validated)) {
                $kept[$key] = $validated[$key];
            }
        }

        return new self($kept);
    }

    /**
     * Point 3.6's edit: everything `forCreate()` keeps except `deal_id` and
     * `customer_id`, which are the quotation's identity — §6.2's "Core" group
     * — not fields of an edit. The Form Request already prohibits both on a
     * `PATCH`; this is the second line of the same rule, so the draft cannot
     * carry them even if the boundary is bypassed.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function forUpdate(array $validated): self
    {
        return self::forCreate(array_diff_key($validated, ['deal_id' => true, 'customer_id' => true]));
    }

    /**
     * A copy of this draft carrying the child rows the directory writes.
     *
     * ── The children are never a caller's, unlike Module 6's ───────────────
     *
     * `SupplierQuotationDraft` pulls its `items` straight from the validated
     * payload, because §7.2's supplier line prices are user-entered. A customer
     * quotation's lines are the opposite: `quotation_items` carries `unit_price`,
     * `line_total`, `line_cost` and `unit_cost_base` — every one a §5.1 figure —
     * and §5 is explicit that "all prices are calculated in the backend". So
     * `forCreate()` cannot lift them from a request, and they arrive here instead
     * — priced by Step 2's engine and, for a line that named its product, already
     * resolved to a `supplier_quotation_item_id` — from `CreateQuotation` (Step
     * 3), inside the transaction `DB-11` requires. This is Module 6's
     * `withItems()` for two tables rather than one.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $additionalItems
     */
    public function withLines(array $items, array $additionalItems): self
    {
        return new self($this->attributes, $items, $additionalItems);
    }

    /**
     * A copy of this draft with the backend-owned header fields merged in.
     *
     * `forCreate()` keeps only what a caller may send, and §5 keeps the money
     * out of a caller's hands entirely: `subtotal` through `rounding_diff`,
     * `rounding_unit`/`rounding_enabled` and `currency_id` are computed by
     * `CreateQuotation` (Step 3) from Step 2's engine, the currency the request's
     * code names, and the FX rate captured at creation — never lifted from the
     * request. `tax_percent` is here too because `D-63` *derives* it from the
     * customer's exemption rather than trusting the field: the merge lets the
     * derived value win over whatever `forCreate()` kept. Given last, so a
     * computed key always overrides a caller's.
     *
     * @param  array<string, mixed>  $computed
     */
    public function withComputed(array $computed): self
    {
        return new self([...$this->attributes, ...$computed], $this->items, $this->additionalItems);
    }
}
