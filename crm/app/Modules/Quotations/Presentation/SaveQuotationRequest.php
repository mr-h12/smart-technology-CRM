<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Quotations\Application\Writing\TermSuggestions;
use App\Modules\Quotations\Domain\Pricing\PricingMode;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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
 * - **Existence of the deal's customer** is above; a line's supplier, product and
 *   unit are another matter: QS.2a (`D-155`) makes them the request's own facts, so
 *   an unknown one is a `422` on its own field, here.
 *
 * ── The table's CHECKs, mirrored ───────────────────────────────────────────
 *
 * Every bound below repeats a constraint Points 1.1–1.4 put on the tables, so
 * the database never answers a bad request with a 500:
 * `entered_unit_price >= 0`, `tax_percent` in NUMERIC(6,3) and `>= 0` (`D-157`: zero is a rate on a line), `discount_percent` in
 * `[0, 100)`, `valid_until >= quotation_date`, `quantity > 0`, `amount >= 0`,
 * a non-blank additional description of at most 255.
 *
 * ── `DB-07` at the boundary ────────────────────────────────────────────────
 *
 * Money, percentages and quantities are decimal **strings**. A JSON number is
 * a PHP float by the time validation sees it, and `numeric` alone would wave it
 * through to `Decimal::of()`'s throw. `string` first, then the plain-decimal
 * regex, then `numeric` for the comparison rules — `RecordFxRateRequest`'s
 * triple. The regex has no sign, except on a line margin, which may be negative
 * down to −100% (`unit_price >= 0`; the line's margin is the whole margin since
 * `D-156` retired the quotation's).
 *
 * ── The suppliers per quotation ────────────────────────────────────────────
 *
 * `D-166` (proposed, owner 2026-10-23): at most {@see self::MAX_SUPPLIERS} distinct
 * `supplier_id` among the lines; a 26th is a `422` on `lines`.
 */
final class SaveQuotationRequest extends FormRequest
{
    /** `D-166` (proposed): distinct suppliers one quotation may name. */
    private const MAX_SUPPLIERS = 25;

    /** The route's `permission:quotation.create` decides; the use case scopes. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
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
            // `D-157`: the toggle is the preparer's. `legacy` is what the system stored before QS.2a (`D-165`), never a new save.
            'pricing_mode' => ['required', Rule::in([PricingMode::TaxIncluded->value, PricingMode::TaxExempt->value])],
            'discount_percent' => self::decimal('required', 'gte:0', 'lt:100'),
            // `D-156`, `D-157`: no quotation margin and no quotation tax rate any more — the line carries both.
            // Refused, not ignored: ignoring one would drop a margin or a tax the caller meant.
            'default_margin' => ['prohibited'],
            'tax_percent' => ['prohibited'],
            'tax_percents' => ['prohibited'],
            'quotation_date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quotation_date'],
            // D-103: at most 15 terms, each `{key, title, body}`; `key` marks
            // one of the three ready terms, whose empty name the PDF fills in.
            'terms' => ['sometimes', 'array', 'max:15'],
            'terms.*' => ['array:key,title,body'],
            'terms.*.key' => ['nullable', Rule::in(TermSuggestions::FIELDS)],
            'terms.*.title' => Rule::forEach(fn (mixed $title, string $attribute): array => [
                $this->needsName($attribute) ? 'required' : 'nullable', 'string', 'max:255',
            ]),
            'terms.*.body' => ['nullable', 'string'],

            // An edit replaces every editable field (Point 3.6): a body that
            // omits the lines has not said "keep them" — it has said nothing,
            // and §5 re-prices from what was said. `present` makes the
            // omission a 422 on a `PATCH`; on a `POST` an absent list is an
            // empty one.
            'lines' => [...($this->isMethod('POST') ? ['sometimes', 'array'] : ['present', 'array']), $this->fewSuppliers(...)],
            // `D-155`: the line names its own supplier, product and unit, and carries what was typed.
            'lines.*.supplier_id' => ['required', 'uuid', $this->alive('suppliers')],
            'lines.*.catalog_item_id' => ['required', 'uuid', $this->alive('catalog_items')],
            'lines.*.unit_code' => ['required', 'string', 'max:64', Rule::exists('enum_lists', 'code')->where('list', 'units')->whereNull('deleted_at')],
            // `entered_unit_price` is NUMERIC(18,6) and `>= 0`; `tax_percent` NUMERIC(6,3) and `>= 0`.
            'lines.*.entered_unit_price' => ['required', 'string', 'regex:/^\d+(\.\d{1,6})?$/', 'numeric'],
            'lines.*.tax_percent' => $this->input('pricing_mode') === PricingMode::TaxExempt->value
                ? ['prohibited']
                : ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/', 'numeric', 'lte:999.999'],
            // The line's own margin, down to −100 % where `unit_price >= 0` would refuse it, up to the column's 999.999.
            'lines.*.margin_percent' => ['required', 'string', 'regex:/^-?\d+(\.\d{1,3})?$/', 'numeric', 'gte:-100', 'lte:999.999'],
            'lines.*.quantity' => self::decimal('required', 'gt:0'),
            // The offer line stays provenance for what was saved before; a new save never names one.
            'lines.*.supplier_quotation_item_id' => ['prohibited'],

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
     * F-37 · 1.4b: a `422` under a row names the field the employee sees, not
     * the request path (`terms.0.title`).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'terms.*.title' => (string) __('quotations.attributes.term_name'),
            'additional_items.*.description' => (string) __('quotations.attributes.additional_item_description'),
            'additional_items.*.amount' => (string) __('quotations.attributes.additional_item_amount'),
            'tax_percents' => (string) __('quotations.attributes.tax_percents'),
        ];
    }

    /**
     * `D-103` (4): a term the employee added has no label of its own to fall
     * back on, so once it has a body it needs a name. `$attribute` is the
     * expanded `terms.N.title`; the term is read from the request, because
     * the data `forEach` passes is flattened to `terms.N.body` keys.
     */
    private function needsName(string $attribute): bool
    {
        $term = $this->input(Str::beforeLast($attribute, '.'));

        return is_array($term)
            && ($term['key'] ?? null) === null
            && is_string($term['body'] ?? null)
            && trim($term['body']) !== '';
    }

    /** `D-166` (proposed): a 26th distinct supplier among the lines is refused on `lines`. */
    private function fewSuppliers(string $attribute, mixed $lines, Closure $fail): void
    {
        $suppliers = [];

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_array($line) && is_string($line['supplier_id'] ?? null)) {
                $suppliers[$line['supplier_id']] = true;
            }
        }

        if (count($suppliers) > self::MAX_SUPPLIERS) {
            $fail((string) __('quotations.validation.too_many_suppliers', ['max' => self::MAX_SUPPLIERS]));
        }
    }

    /**
     * `DB-01`: an archived row is not a row a line may name.
     *
     * ponytail: a word-for-word copy of `SaveSupplierQuotationRequest::alive()` — deptrac forbids sharing it
     * across modules; the copy goes when slice 4 retires the supplier-quotation screen.
     */
    private function alive(string $table): Exists
    {
        return Rule::exists($table, 'id')->whereNull('deleted_at');
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
