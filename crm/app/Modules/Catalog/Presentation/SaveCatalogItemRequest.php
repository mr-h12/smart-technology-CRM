<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation;

use App\Modules\Catalog\Domain\Writing\CatalogItemDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The boundary for `POST /catalog-items` and `PATCH /catalog-items/{catalogItem}`.
 *
 * One class for both verbs, because §3.7's write row is one cell — "create ·
 * edit · deactivate · set colour" — so the field rules are identical and only
 * whether `kind` is required differs.
 *
 * ── The conditional rules live here and nowhere else ───────────────────────
 *
 * §7.3's two tabs are one table split by `kind`, and Point 1.2 deliberately
 * wrote **no cross-field CHECK**: a constraint enforcing "a product needs a
 * unit" would duplicate a validation rule in a place no error message can
 * reach, and a violation would arrive at the caller as a 500. So `required_if`
 * carries it, which is what makes a 422 naming the field possible.
 *
 * ⚠️ **`required_if` fires only when `kind` is in the payload.** A `PATCH`
 * that sends `{"unit": null}` without naming the kind blanks a product's unit,
 * because a partial update has no view of the stored row. Closing that would
 * mean the boundary reading the database, which is a layer this class does not
 * cross. Recorded in `CHECKLIST.md`.
 *
 * ── `unit` and `service_type` are checked for shape, not for membership ────
 *
 * Both are `enum_lists` codes (`DB-05`), and Point 1.2 gave neither a foreign
 * key because PostgreSQL refuses one against that table's partial unique index.
 * Its comment says they are "validated at the boundary" — and the same promise
 * was made about `customers.sector` in Module 3 and **not kept**.
 *
 * ⚠️ **Point 6.3 removed the obstacle but deliberately not the gap.** The
 * `AdminContract` deptrac layer this used to say did not exist now does, and
 * Catalog reaches `ManagedListRepositoryInterface` through it — so "the layer
 * is missing" is no longer the reason `unit` and `service_type` go unchecked.
 * They are simply still unchecked, and closing them is a different decision
 * from the one 6.3 made: **`company` is not validated against the list either.
 * It is *registered into* it.** An unknown company becomes a listed company; an
 * unknown unit would have to be refused, because §7.3 fixes the unit vocabulary
 * ("piece · metre · kilo · extendable") in a way it does not fix the set of
 * companies a business trades with. So the two are not the same problem wearing
 * one name, and 6.3 answers only the one the owner ruled on.
 *
 * `customers.sector` is untouched by any of this. The debt stays in
 * `CHECKLIST.md` awaiting a `D-xx`, with its reason corrected.
 *
 * ── The price fields are refused, not ignored ──────────────────────────────
 *
 * §7.3 opens "Descriptive data only — **no prices**" and `D-21` puts price,
 * cost and margin on the supplier quotation. {@see CatalogItemDraft} would
 * filter them out anyway, so the write is safe either way — but a caller who
 * sends one has a wrong idea about where a price lives, and answering 201 would
 * confirm it. `OpenAPI §6.2` takes the same line about unknown query
 * parameters: refusing beats ignoring.
 *
 * Authorisation is on the route (`permission:catalog.manage`), §3.12 rule 1.
 */
final class SaveCatalogItemRequest extends FormRequest
{
    /** The route carries `permission:catalog.manage`; that is the authorisation. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // A row in neither tab appears on no screen §7.3 describes, so `kind`
        // is required to create. On an edit it is merely optional — and when it
        // *is* sent, the conditional rules below re-check the whole row against
        // the tab it is moving to.
        $kind = $this->isMethod('POST')
            ? ['required', Rule::in(CatalogItemDraft::KINDS)]
            : ['sometimes', 'required', Rule::in(CatalogItemDraft::KINDS)];

        $company = $this->isMethod('POST')
            ? ['required', 'string', 'max:255', 'regex:/\S/']
            : ['sometimes', 'required', 'string', 'max:255', 'regex:/\S/'];

        return [
            'kind' => $kind,

            // §7.3 names the product by its name; a service is identified by
            // its type, which is why Point 1.2 left the column nullable.
            // `regex:/\S/` beside it, because `required` accepts "   " and the
            // table carries `CHECK (name IS NULL OR btrim(name) <> '')` — a
            // constraint violation reaches the caller as a 500, so the boundary
            // refuses first and the database never has to.
            'name' => ['required_if:kind,product', 'nullable', 'string', 'max:255', 'regex:/\S/'],

            // Lengths are the columns' own (Point 1.2).
            'product_code' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', 'string', 'max:128'],

            // §7.3's Product row: "Unit". A service has none.
            'unit' => ['required_if:kind,product', 'nullable', 'string', 'max:64'],

            // §7.3's Service row: "Service type". A product has none.
            'service_type' => ['required_if:kind,service', 'nullable', 'string', 'max:64'],

            // §7.3 lists "Providing team / company" in the Service column and
            // marks nothing required. **Owner's ruling, 2026-08-31: required
            // for both tabs** — §7.3 also opens "grouped by company/team name",
            // and a row with no company falls out of the only grouping the
            // screen has. The column stays nullable: a `NOT NULL` migration
            // would fail on the rows that already have none, so the rule lives
            // here, the same shape as `name` above. `regex:/\S/` for the same
            // reason as `name` — `required` accepts "   ". Awaiting a `D-xx`.
            //
            // ⚠️ `ManagedList::Companies` is seeded **empty** and only the
            // Super Admin may add to it (`admin.system_settings` on
            // `POST /managed-lists/{list}`), so a fresh install cannot create a
            // catalog item until a company is added. Owner accepted that as a
            // setup step, 2026-08-31; it belongs in the manual test list.
            //
            // Required to create, `sometimes|required` to edit — the shape
            // `kind` uses above, and for the same reason: a `PATCH` that does
            // not name the column is not asking to blank it, while one that
            // *does* name it must give a real value.
            'company' => $company,
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],

            // §3.7's "deactivate", as a field. There is no `/deactivate` route:
            // `OpenAPI §7.2` reserves an action suffix for what is not a normal
            // update, and §3.7 grants this under the same permission as `edit`.
            'is_active' => ['sometimes', 'boolean'],

            // §7.3 and `D-21`. Not a validation of a value — a refusal of the
            // idea that a catalog item carries money at all.
            'price' => ['prohibited'],
            'cost' => ['prohibited'],
            'margin' => ['prohibited'],
        ];
    }
}
