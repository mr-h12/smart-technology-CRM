<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §6.1`/`§6.2` — a customer list query that cannot be honoured.
 *
 * §6.1: *"Invalid or excessive values return `400 invalid_request`."* §6.2:
 * *"Reject unknown filter, sort, group, or include values with `400
 * invalid_request`; **never ignore them silently**."*
 *
 * Which is why the query is parsed in Domain rather than by a Form Request: a
 * failed Form Request is a `422 validation_failed`, and the contract asks for a
 * 400. The SPA acts on the difference — 422 means a person typed something
 * wrong in a form, 400 means the client built a URL this API does not offer.
 *
 * ── The third copy, and the reason it is not shared ────────────────────────
 *
 * Identity has `InvalidListQuery` and Admin has `InvalidListingQuery`. The
 * `CHECKLIST.md` debt that moved `ApiEnvelope` into `App\Support\Http` names
 * this contract too, and it **cannot** follow: all three live in **Domain**,
 * whose `deptrac.layers.yaml` ruleset is empty on purpose — "may depend on
 * nothing", the load-bearing rule of that file. That was probed rather than
 * assumed: a real reference from a Domain class produced
 * `DependsOnDisallowedLayer`. Sharing this needs a decision to weaken Domain's
 * ruleset, which is bigger than a Customers point, so the register carries it.
 */
final class InvalidCustomerListQuery extends RuntimeException
{
    public const ERROR_CODE = 'invalid_request';

    private function __construct(public readonly string $parameter, public readonly string $detailCode)
    {
        parent::__construct('Invalid customer list query on '.$parameter.': '.$detailCode);
    }

    public static function of(string $parameter, string $detailCode): self
    {
        return new self($parameter, $detailCode);
    }

    public function messageKey(): string
    {
        return 'customers.list_query.'.$this->detailCode;
    }
}
