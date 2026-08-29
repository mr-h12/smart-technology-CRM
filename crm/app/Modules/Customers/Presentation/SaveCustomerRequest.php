<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation;

use App\Modules\Customers\Domain\Writing\CustomerDraft;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The boundary for `POST /customers` and `PATCH /customers/{customer}`.
 *
 * One class for both verbs, because the field rules are the same field rules
 * and only two things differ: whether `name` is required, and whether
 * `sales_owner_id` may be written at all.
 *
 * ── Three columns are `prohibited`, not merely absent ──────────────────────
 *
 * `customer_status`, `is_archived` and `is_incomplete` are refused with a 422
 * rather than dropped. {@see CustomerDraft} would filter them out anyway, so
 * the write is safe either way — but a caller who sends `customer_status` has
 * a wrong idea about who owns that value (§4.5, `D-49`: it is derived), and
 * answering 201 would confirm it. `OpenAPI §6.2` takes the same line about
 * unknown query parameters: refusing beats ignoring.
 *
 * `sales_owner_id` is prohibited on `PATCH` for §3.3's reason — `assign` is a
 * permission of its own, granted to two roles where `edit` is granted to five,
 * and `OpenAPI §7.2` gives it the route `PATCH /customers/{id}/assign`
 * (Point 3.5).
 *
 * Authorisation is on the route (`permission:customer.create` /
 * `permission:customer.edit`), §3.12 rule 1. The row scope is not here: it is a
 * decision about *which* record, which a Form Request cannot see.
 */
final class SaveCustomerRequest extends FormRequest
{
    private function isCreate(): bool
    {
        return $this->isMethod('POST');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // `regex:/\S/` beside `required`, because `required` accepts "   ".
        // The table carries `CHECK (btrim(name) <> '')`, and a constraint
        // violation reaches the caller as a 500 — the boundary refuses first so
        // the database never has to.
        $name = $this->isCreate()
            ? ['required', 'string', 'max:255', 'regex:/\S/']
            : ['sometimes', 'required', 'string', 'max:255', 'regex:/\S/'];

        return [
            'name' => $name,

            // Lengths are the columns' own (Point 1.1). A value longer than the
            // column is a 500 from PostgreSQL if it is not refused here.
            'sector' => ['nullable', 'string', 'max:64'],
            'region' => ['nullable', 'string', 'max:128'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'phone2' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],

            // Existence is checked by the use case through Identity's contract,
            // not by `exists:users,id` — that rule is a direct read of another
            // module's table, which `CLAUDE.md` forbids.
            'sales_owner_id' => $this->isCreate()
                ? ['nullable', 'uuid']
                : ['prohibited'],

            'customer_status' => ['prohibited'],
            'is_archived' => ['prohibited'],
            'is_incomplete' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $attributes = [];

        foreach ([...CustomerDraft::writableOnCreate(), 'customer_status', 'is_archived', 'is_incomplete'] as $field) {
            $attributes[$field] = (string) __('customers.attributes.'.$field);
        }

        return $attributes;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // The default for `prohibited` says only that the field "is
            // prohibited", which tells a developer nothing about *why*. These
            // three are the ones a caller is most likely to try.
            'customer_status.prohibited' => (string) __('customers.validation.status_is_derived'),
            'is_archived.prohibited' => (string) __('customers.validation.archive_has_its_own_action'),
            'sales_owner_id.prohibited' => (string) __('customers.validation.assign_has_its_own_action'),
            'name.regex' => (string) __('customers.validation.name_not_blank'),
        ];
    }
}
