<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §6.1`/`§6.2` — a catalog list query that cannot be honoured.
 *
 * §6.1: *"Invalid or excessive values return `400 invalid_request`."* §6.2:
 * *"Reject unknown filter, sort, group, or include values with `400
 * invalid_request`; **never ignore them silently**."* This is the first list in
 * the application to publish a `group_by`, so `unknown_group` is the first
 * detail code for §6.2's third column.
 *
 * Parsed in Domain rather than by a Form Request because a failed Form Request
 * is a `422 validation_failed` and the contract asks for a 400. The SPA acts on
 * the difference: 422 means a person typed something wrong in a form, 400 means
 * the client built a URL this API does not offer.
 *
 * ── The fifth copy, and the reason it is still not shared ──────────────────
 *
 * Identity has `InvalidListQuery`, Admin `InvalidListingQuery`, Customers
 * `InvalidCustomerListQuery`, Suppliers `InvalidSupplierListQuery`. The
 * `CHECKLIST.md` register carries this debt and it **cannot** be paid from a
 * module point: all five live in **Domain**, whose `deptrac.layers.yaml`
 * ruleset is empty on purpose — "may depend on nothing". Module 3 probed that
 * rather than assuming it, and a real reference from a Domain class produced
 * `DependsOnDisallowedLayer`. Sharing these needs a decision to weaken Domain's
 * ruleset, which is bigger than this point.
 */
final class InvalidCatalogItemListQuery extends RuntimeException
{
    public const ERROR_CODE = 'invalid_request';

    private function __construct(public readonly string $parameter, public readonly string $detailCode)
    {
        parent::__construct('Invalid catalog item list query on '.$parameter.': '.$detailCode);
    }

    public static function of(string $parameter, string $detailCode): self
    {
        return new self($parameter, $detailCode);
    }

    public function messageKey(): string
    {
        return 'catalog.list_query.'.$this->detailCode;
    }
}
