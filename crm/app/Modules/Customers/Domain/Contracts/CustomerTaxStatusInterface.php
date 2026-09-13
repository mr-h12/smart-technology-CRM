<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Contracts;

/**
 * The one fact Module 7 reads about a customer to price a quotation: is this
 * customer tax-exempt (`D-63`)?
 *
 * A dedicated interface rather than a method on {@see CustomerDirectoryInterface},
 * on that interface's own terms. Its reads take a {@see \App\Modules\Customers\Domain\Access\CustomerRowScope}
 * because §10's screens are row-scoped; this read is not a screen. A quotation's
 * customer is fixed by its deal, not chosen from a list, and `is_tax_exempt` is
 * a property of the customer, not of who may see them — so a scope parameter
 * here would be the filter with one possible value the waste audit names. The
 * narrow interface is the published surface, the way `CustomerStatusWriterInterface`
 * is the only thing Deals may reach.
 */
interface CustomerTaxStatusInterface
{
    /**
     * Whether the customer is flagged tax-exempt (`D-63`, `customers.is_tax_exempt`).
     *
     * False for an absent or `DB-01` soft-deleted customer: the column defaults
     * to false, a quotation is not priced for an archived customer, and a missing
     * row resolves to the taxed direction — the safe one, since the customer's
     * foreign key on the quotation would refuse a truly non-existent id anyway.
     */
    public function isExempt(string $customerId): bool;
}
