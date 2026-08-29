<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The boundary for `PATCH /customers/{customer}/assign`.
 *
 * One field, and it is `required` rather than `nullable`: §3.3 has no
 * "unassign" row, and a customer left with no owner is `D-34`'s deactivation
 * path, not a transfer. Clearing an owner through this route would be an action
 * no document describes.
 *
 * Existence is checked by the use case through Identity's contract, not by
 * `exists:users,id` — that rule is a direct read of another module's table,
 * which `CLAUDE.md` forbids. {@see AssignCustomer}
 */
final class AssignCustomerRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'sales_owner_id' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'sales_owner_id' => (string) __('customers.attributes.sales_owner_id'),
        ];
    }

    public function ownerId(): string
    {
        $id = $this->validated('sales_owner_id');

        // Narrowed rather than cast: `rules()` guarantees a string, and
        // `Coding Standards` forbids the untyped escape hatch that would let a
        // future rule change slip through silently.
        if (! is_string($id)) {
            throw new \RuntimeException('The assign request validated a non-string owner id.');
        }

        return $id;
    }
}
