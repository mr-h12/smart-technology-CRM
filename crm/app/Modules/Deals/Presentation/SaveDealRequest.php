<?php

declare(strict_types=1);

namespace App\Modules\Deals\Presentation;

use App\Modules\Deals\Domain\Writing\DealDraft;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The boundary for `POST /deals` and `PATCH /deals/{deal}` —
 * `SaveCustomerRequest`'s shape (Module 3 Point 3.3), on the same reasoning.
 *
 * One class for both verbs: the field rules are the same rules, and only two
 * things differ — whether `customer_id` is required, and whether `customer_id`
 * / `owner_id` may be written at all.
 *
 * ── Five columns are `prohibited`, not merely absent ───────────────────────
 *
 * `code`, `status`, `approval_status`, `rejection_reason` and `last_activity_at`
 * are refused with a 422 rather than silently dropped — {@see DealDraft} would
 * filter them out anyway, but a caller who sends `status` has a wrong idea
 * about who owns that value, and answering 201/200 would confirm it.
 *
 * `customer_id` and `owner_id` are prohibited on `PATCH` for the reason
 * `sales_owner_id` is on Customers: `customer_id` has no documented transfer
 * operation at all, and `owner_id`'s is `assign_owner`, a permission and route
 * of its own (a later point).
 */
final class SaveDealRequest extends FormRequest
{
    private function isCreate(): bool
    {
        return $this->isMethod('POST');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'customer_id' => $this->isCreate()
                // Existence is a foreign key PostgreSQL already enforces
                // (Point 1.1); this only refuses a value the database could
                // not accept before it ever reaches the write.
                ? ['required', 'uuid']
                : ['prohibited'],

            // Lengths are the columns' own (Point 1.1).
            'title' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'in:'.implode(',', ['outlook_whatsapp', 'outdoor_visit', 'employee_entry'])],
            'service_type' => ['nullable', 'string', 'in:'.implode(',', ['product', 'service'])],

            // Existence is checked by the use case through Identity's
            // contract, not by `exists:users,id` — a direct read of another
            // module's table, which `CLAUDE.md` forbids.
            'owner_id' => $this->isCreate()
                ? ['nullable', 'uuid']
                : ['prohibited'],

            'code' => ['prohibited'],
            'status' => ['prohibited'],
            'approval_status' => ['prohibited'],
            'rejection_reason' => ['prohibited'],
            'last_activity_at' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $fields = [
            ...DealDraft::writableOnCreate(),
            'code', 'status', 'approval_status', 'rejection_reason', 'last_activity_at',
        ];

        $attributes = [];

        foreach (array_unique($fields) as $field) {
            $attributes[$field] = (string) __('deals.attributes.'.$field);
        }

        return $attributes;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.prohibited' => (string) __('deals.validation.status_is_derived'),
            'approval_status.prohibited' => (string) __('deals.validation.approval_is_derived'),
            'code.prohibited' => (string) __('deals.validation.code_is_generated'),
            'customer_id.prohibited' => (string) __('deals.validation.customer_is_fixed_at_creation'),
            'owner_id.prohibited' => (string) __('deals.validation.assign_has_its_own_action'),
        ];
    }
}
