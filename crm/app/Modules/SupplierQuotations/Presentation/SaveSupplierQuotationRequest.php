<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * §7.2's create, validated at the boundary — `SaveCatalogItemRequest`'s shape
 * (Module 4 Point 3.2).
 *
 * ── Every rule that mirrors a database constraint is here for one reason ───
 *
 * A constraint violation reaches the caller as a **500**. Points 1.1 and 1.2
 * put four of them on these two tables — the foreign keys, `unit_price >= 0`,
 * `quantity > 0`, and the CHECK that allows both money columns or neither — and
 * each is mirrored below so the boundary answers `422` first and the database
 * never has to. That is the reasoning `SaveCatalogItemRequest` gives for its
 * `regex:/\S/` beside a `CHECK (btrim(name) <> '')`.
 *
 * ── An unknown *id* is a 422; an unknown *name* is `D-22`'s auto-add ───────
 *
 * `D-22` says a product the catalog does not have is added automatically,
 * without review. Point 3.3 opens the door for it: a line carries
 * `catalog_item_id` **or** `product_name`, exactly one. An id that is sent is
 * still checked against the table — a caller naming a row that is not there
 * has a stale screen, not a new product — and `exists` is allowed here because
 * it is a database rule rather than a cross-module code path; a call into
 * Catalog's repository would not be. The *resolution* of a name into a product
 * belongs to `CatalogProductProvisionerInterface` (Point 3.1) inside the use
 * case's transaction, which is Points 3.4 and 3.5.
 *
 * ── `whereNull('deleted_at')` on every existence rule ──────────────────────
 *
 * `DB-01` soft-deletes everything, and Laravel's `exists` is a raw table query
 * that would happily accept an archived supplier, deal, currency or product.
 * An offer filed against a deleted row is exactly the kind of write the archive
 * exists to prevent.
 *
 * Authorisation is on the route (`permission:supplier_quotation.create`),
 * §3.12 rule 1.
 */
final class SaveSupplierQuotationRequest extends FormRequest
{
    /** The route carries `permission:supplier_quotation.create`; that is the authorisation. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // §4.1 draws exactly one line into this entity, and Point 1.1 made
            // the column `NOT NULL` for it. On a `PATCH` it becomes
            // `sometimes|required` — `SaveSupplierRequest`'s pattern: an edit
            // that does not name the supplier keeps the one it has, but an edit
            // that names it may not blank it. §7.2 does not freeze it, so it is
            // editable (Point 2.4).
            'supplier_id' => $this->isMethod('POST')
                ? ['required', 'uuid', $this->alive('suppliers')]
                : ['sometimes', 'required', 'uuid', $this->alive('suppliers')],

            // `D-51`: standalone, and available to any deal.
            'deal_id' => ['nullable', 'uuid', $this->alive('deals')],

            // Owner's ruling, 2026-09-02: **entered by the user**, never summed
            // from the lines. `min:0` has no source of its own — it mirrors the
            // `unit_price >= 0` CHECK Point 1.2 put on a line, because §5 has no
            // negative price anywhere and an offer that totals below zero is not
            // a discount. Flagged as a boundary rule with no citation rather
            // than presented as one.
            'total_price' => ['nullable', 'numeric', 'min:0', 'required_with:currency_id'],

            // Point 1.1's CHECK: `(total_price IS NULL) = (currency_id IS NULL)`.
            // Both `required_with`s together are what makes the pair symmetric.
            'currency_id' => ['nullable', 'uuid', 'required_with:total_price', $this->alive('currencies')],

            'offer_date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],

            // §7.2 marks the code "Automatic" and `document_sequences` allocates
            // it. `prohibited` rather than ignored, on `SaveCatalogItemRequest`'s
            // reading of `OpenAPI §6.2`: a caller who sends one has a wrong idea
            // about where a code comes from, and answering 201 would confirm it.
            'code' => ['prohibited'],

            // §7.2's `Line items`. Optional as a whole — §7.2 requires no line
            // count — and every line complete when there is one.
            'items' => ['sometimes', 'array'],

            // `D-22`, Point 3.3: a line names its product **either** by id or
            // by name, and a caller typing an offer out of a supplier's PDF
            // usually has only the name. `required_without` on both sides makes
            // one of them mandatory; `prohibits` on the id refuses the pair,
            // because an id and a name can disagree and nothing in §7.2 or
            // `D-22` says which would win. The `*` in both parameters is
            // replaced with the line's own index — `Prohibits` and
            // `RequiredWithout` are both in Laravel's `$dependentRules`, which
            // is what earns that substitution.
            'items.*.catalog_item_id' => [
                'required_without:items.*.product_name',
                'prohibits:items.*.product_name',
                'uuid',
                $this->alive('catalog_items'),
            ],

            // The catalog column's own shape (Module 4 Point 1.2): `varchar(255)`
            // and `CHECK (name IS NULL OR btrim(name) <> '')`, mirrored here for
            // the reason the header of this class gives — a constraint violation
            // reaches the caller as a 500. `regex:/\S/` is
            // `SaveCatalogItemRequest`'s, and it is not a re-trim: `TrimStrings`
            // has already run, so this refuses a name that was *only*
            // whitespace rather than tidying one that had some.
            'items.*.product_name' => [
                'required_without:items.*.catalog_item_id',
                'string',
                'max:255',
                'regex:/\S/',
            ],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /** `DB-01`: an archived row is not a row this offer may be filed against. */
    private function alive(string $table): Exists
    {
        return Rule::exists($table, 'id')->whereNull('deleted_at');
    }
}
