<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §6.1`/`§6.2` — a supplier-quotation list query that cannot be
 * honoured.
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
 * ── The fifth copy, and it still cannot be shared from here ────────────────
 *
 * Identity has `InvalidListQuery`, Admin `InvalidListingQuery`, Customers
 * `InvalidCustomerListQuery`, Suppliers `InvalidSupplierListQuery`. The
 * `CHECKLIST.md` register carries the debt and states why a module point cannot
 * pay it: all of them live in **Domain**, whose `deptrac.layers.yaml` ruleset is
 * empty on purpose — "may depend on nothing" — and Module 3 probed that rather
 * than assuming it, getting `DependsOnDisallowedLayer` from a real reference.
 * Sharing them needs a decision to weaken Domain's ruleset, which is larger
 * than any point in this step.
 */
final class InvalidSupplierQuotationListQuery extends RuntimeException
{
    public const ERROR_CODE = 'invalid_request';

    private function __construct(public readonly string $parameter, public readonly string $detailCode)
    {
        parent::__construct('Invalid supplier quotation list query on '.$parameter.': '.$detailCode);
    }

    public static function of(string $parameter, string $detailCode): self
    {
        return new self($parameter, $detailCode);
    }

    public function messageKey(): string
    {
        return 'supplier_quotations.list_query.'.$this->detailCode;
    }
}
