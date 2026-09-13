<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use App\Modules\Admin\Domain\Money\CurrencyCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The boundary of `POST /quotations` — Module 7 Point 3.4.
 *
 * Point 3.3 built `CreateQuotation` against an assumed request shape and
 * narrowed it by hand; this is where that shape becomes a validated contract.
 * Two things it deliberately does **not** check:
 *
 * - **Existence of the deal and the customer.** The use case reads the deal
 *   through `DealFactsInterface` to apply §3.5's create scope, and an unknown
 *   deal is a `422` on `deal_id` from there; the customer must equal the deal's
 *   (owner's ruling 2026-09-11), which subsumes "exists". One check per rule,
 *   in the layer that owns the rule.
 * - **Existence of a supplier line.** A gone or soft-deleted line is §5.6's own
 *   case — "product or price missing at the supplier → block" — and answers
 *   `422 business_rule_blocked` / `supplier_price_missing`, not a validation
 *   error. Sending an `exists` failure instead would rename a documented rule.
 *
 * ── The table's CHECKs, mirrored ───────────────────────────────────────────
 *
 * Every bound below repeats a constraint Points 1.1–1.4 put on the tables, so
 * the database never answers a bad request with a 500: `tax_percent IS NULL OR
 * > 0` (`D-63` — no tax is null, zero is not a rate), `discount_percent` in
 * `[0, 100)`, `valid_until >= quotation_date`, `quantity > 0`, `amount >= 0`,
 * a non-blank additional description of at most 255.
 *
 * ── `DB-07` at the boundary ────────────────────────────────────────────────
 *
 * Money, percentages and quantities are decimal **strings**. A JSON number is
 * a PHP float by the time validation sees it, and `numeric` alone would wave it
 * through to `Decimal::of()`'s throw. `string` first, then the plain-decimal
 * regex, then `numeric` for the comparison rules — `RecordFxRateRequest`'s
 * triple. The regex has no sign, so a negative margin is refused here; if a
 * loss-leading quotation is ever wanted, the regex is the one place to relax.
 */
final class SaveQuotationRequest extends FormRequest
{
    /** The route's `permission:quotation.create` decides; the use case scopes. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // §6.2's "Core" group is the quotation's identity: named once, on
            // the `POST`, and refused on a `PATCH` rather than ignored — a
            // caller sending a different deal has a wrong idea of what an edit
            // can do, and a 200 would confirm it (Point 3.6).
            'deal_id' => $this->isMethod('POST') ? ['required', 'uuid'] : ['prohibited'],
            'customer_id' => $this->isMethod('POST') ? ['required', 'uuid'] : ['prohibited'],
            'currency' => ['required', Rule::enum(CurrencyCode::class)],
            'default_margin' => self::decimal('required'),
            'discount_percent' => self::decimal('required', 'gte:0', 'lt:100'),
            'tax_percent' => self::decimal('nullable', 'gt:0'),
            'quotation_date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quotation_date'],
            'payment_terms' => ['nullable', 'string'],
            'warranty' => ['nullable', 'string'],
            'delivery_terms' => ['nullable', 'string'],
            'show_delivery_terms' => ['nullable', 'boolean'],

            // An edit replaces every editable field (Point 3.6): a body that
            // omits the lines has not said "keep them" — it has said nothing,
            // and §5 re-prices from what was said. `present` makes the
            // omission a 422 on a `PATCH`; on a `POST` an absent list is an
            // empty one.
            'lines' => $this->isMethod('POST') ? ['sometimes', 'array'] : ['present', 'array'],
            'lines.*.supplier_quotation_item_id' => ['required', 'uuid'],
            'lines.*.quantity' => self::decimal('required', 'gt:0'),
            'lines.*.margin_percent' => self::decimal('nullable'),

            'additional_items' => $this->isMethod('POST') ? ['sometimes', 'array'] : ['present', 'array'],
            'additional_items.*.description' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'additional_items.*.amount' => self::decimal('required', 'gte:0'),

            // `document_sequences` allocates the code and the status machine
            // owns the status; neither is the caller's to send.
            'code' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * `DB-07`'s decimal-string triple, with the presence rule first and any
     * bound after.
     *
     * @return list<string>
     */
    private static function decimal(string $presence, string ...$bounds): array
    {
        return array_merge([$presence, 'string', 'regex:/^\d+(\.\d+)?$/', 'numeric'], array_values($bounds));
    }
}
