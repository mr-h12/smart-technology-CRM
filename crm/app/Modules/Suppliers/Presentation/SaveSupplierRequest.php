<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Presentation;

use App\Modules\Suppliers\Domain\Writing\SupplierDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The boundary for `POST /suppliers` and `PATCH /suppliers/{supplier}`.
 *
 * One class for both verbs, because §3.7's write row is one cell — "create ·
 * edit · deactivate · set colour" — so the field rules are identical and only
 * whether `name` is required differs.
 *
 * `linked_quotations` is `prohibited` rather than merely absent. {@see
 * SupplierDraft} would filter it out anyway, so the write is safe either way —
 * but a caller who sends it has a wrong idea about who owns that value (§7.1
 * marks it "Automatic"; Module 6 derives it), and answering 201 would confirm
 * it. `OpenAPI §6.2` takes the same line about unknown query parameters:
 * refusing beats ignoring.
 *
 * Authorisation is on the route (`permission:catalog.manage`), §3.12 rule 1.
 */
final class SaveSupplierRequest extends FormRequest
{
    /** The route carries `permission:catalog.manage`; that is the authorisation. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // `regex:/\S/` beside `required`, because `required` accepts "   ".
        // The table carries `CHECK (btrim(name) <> '')`, and a constraint
        // violation reaches the caller as a 500 — the boundary refuses first so
        // the database never has to.
        $name = $this->isMethod('POST')
            ? ['required', 'string', 'max:255', 'regex:/\S/']
            : ['sometimes', 'required', 'string', 'max:255', 'regex:/\S/'];

        return [
            'name' => $name,

            // The closed sets the table's CHECKs enforce, declared once on the
            // draft. Refused here so a violation is a 422 naming the field
            // rather than a 500 from PostgreSQL.
            'type' => ['nullable', Rule::in(SupplierDraft::TYPES)],
            'color_rating' => ['sometimes', 'required', Rule::in(SupplierDraft::RATINGS)],

            // Lengths are the columns' own (Point 1.1).
            'phone' => ['nullable', 'string', 'max:32'],
            'contact_person' => ['nullable', 'string', 'max:255'],

            'has_open_account' => ['sometimes', 'boolean'],

            // §3.7's "deactivate", as a field. There is no `/deactivate` route:
            // `OpenAPI §7.2` reserves an action suffix for what is not a normal
            // update, and §3.7 grants this under the same permission as `edit`.
            'is_active' => ['sometimes', 'boolean'],

            'linked_quotations' => ['prohibited'],
        ];
    }
}
