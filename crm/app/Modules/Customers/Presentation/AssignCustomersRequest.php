<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation;

use App\Modules\Customers\Domain\Listing\CustomerListCriteria;
use RuntimeException;

/**
 * The boundary for `POST /customers/assign` (F-19 · 1.2, `D-92`): the single
 * route's owner, plus `OpenAPI §7.3`'s "bounded identifier list".
 *
 * The bound is §6.1's page of 100: the list screen selects within one page. A
 * repeated id is refused rather than folded, so the answer's items stay one per
 * id sent. Existence and reach are the use case's, per customer, at the caller's
 * scope — never `exists:customers,id`, which would answer for rows out of reach.
 */
final class AssignCustomersRequest extends AssignCustomerRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'ids' => ['required', 'list', 'max:'.CustomerListCriteria::MAX_PER_PAGE],
            'ids.*' => ['uuid', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $ids = (string) __('customers.attributes.ids');

        return [...parent::attributes(), 'ids' => $ids, 'ids.*' => $ids];
    }

    /** @return list<string> */
    public function ids(): array
    {
        $ids = $this->validated('ids');

        // Narrowed rather than cast, on `ownerId()`'s reasoning: `rules()`
        // guarantees a list of strings, and a rule change must not slip through.
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new RuntimeException('The bulk assign request validated a non-list of ids.');
        }

        return array_map(static function (mixed $id): string {
            if (! is_string($id)) {
                throw new RuntimeException('The bulk assign request validated a non-string id.');
            }

            return $id;
        }, $ids);
    }
}
