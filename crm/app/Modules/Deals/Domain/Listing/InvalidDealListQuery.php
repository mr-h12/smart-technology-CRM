<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §6.1`/`§6.2` — a deal list query that cannot be honoured.
 *
 * The fourth copy of this shape (Identity, Admin, Customers, Suppliers,
 * Catalog precede it) and for the same reason each of those recorded: it lives
 * in **Domain**, whose `deptrac.layers.yaml` ruleset is empty on purpose — "may
 * depend on nothing" — so a shared base in `App\Support` is not available to
 * it without weakening that rule, which is bigger than this point.
 */
final class InvalidDealListQuery extends RuntimeException
{
    public const ERROR_CODE = 'invalid_request';

    private function __construct(public readonly string $parameter, public readonly string $detailCode)
    {
        parent::__construct('Invalid deal list query on '.$parameter.': '.$detailCode);
    }

    public static function of(string $parameter, string $detailCode): self
    {
        return new self($parameter, $detailCode);
    }

    public function messageKey(): string
    {
        return 'deals.list_query.'.$this->detailCode;
    }
}
