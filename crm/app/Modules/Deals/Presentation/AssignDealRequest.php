<?php

declare(strict_types=1);

namespace App\Modules\Deals\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * The boundary for `PATCH /deals/{deal}/assign` —
 * `AssignCustomerRequest`'s shape (Module 3 Point 3.5), on the same reasoning.
 *
 * One field, `required` rather than `nullable`: §3.4 has no "unassign" row,
 * and `owner_id` left empty is not a documented state a transfer produces.
 *
 * Existence is checked by the use case through Identity's contract, not by
 * `exists:users,id` — a direct read of another module's table, which
 * `CLAUDE.md` forbids. {@see \App\Modules\Deals\Application\Assignment\AssignDeal}
 */
final class AssignDealRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'owner_id' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'owner_id' => (string) __('deals.attributes.owner_id'),
        ];
    }

    public function ownerId(): string
    {
        $id = $this->validated('owner_id');

        if (! is_string($id)) {
            throw new RuntimeException('The assign request validated a non-string owner id.');
        }

        return $id;
    }
}
