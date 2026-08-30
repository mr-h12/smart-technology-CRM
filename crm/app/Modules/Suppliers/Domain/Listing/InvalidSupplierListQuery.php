<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §6.1`/`§6.2` — a supplier list query that cannot be honoured.
 *
 * §6.1: *"Invalid or excessive values return `400 invalid_request`."* §6.2:
 * *"Reject unknown filter, sort, group, or include values with `400
 * invalid_request`; **never ignore them silently**."*
 *
 * Parsed in Domain rather than by a Form Request because a failed Form Request
 * is a `422 validation_failed` and the contract asks for a 400. The SPA acts on
 * the difference: 422 means a person typed something wrong in a form, 400 means
 * the client built a URL this API does not offer.
 *
 * ── The fourth copy, and the reason it is still not shared ─────────────────
 *
 * Identity has `InvalidListQuery`, Admin `InvalidListingQuery`, Customers
 * `InvalidCustomerListQuery`. The `CHECKLIST.md` register carries this debt and
 * it **cannot** be paid from a module point: all four live in **Domain**, whose
 * `deptrac.layers.yaml` ruleset is empty on purpose — "may depend on nothing".
 * Module 3 probed that rather than assuming it, and a real reference from a
 * Domain class produced `DependsOnDisallowedLayer`. Sharing these needs a
 * decision to weaken Domain's ruleset, which is bigger than this point.
 */
final class InvalidSupplierListQuery extends RuntimeException
{
    public const ERROR_CODE = 'invalid_request';

    private function __construct(public readonly string $parameter, public readonly string $detailCode)
    {
        parent::__construct('Invalid supplier list query on '.$parameter.': '.$detailCode);
    }

    public static function of(string $parameter, string $detailCode): self
    {
        return new self($parameter, $detailCode);
    }

    public function messageKey(): string
    {
        return 'suppliers.list_query.'.$this->detailCode;
    }
}
