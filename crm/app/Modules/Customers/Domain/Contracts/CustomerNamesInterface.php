<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Contracts;

/**
 * The one fact Module 7 reads to label a quotation row: its customer's name
 * (`D-83`, reversing Module 7 Step 5 Q7).
 *
 * **Name only**, so a caller permitted a quotation is not thereby granted
 * customer data. No {@see \App\Modules\Customers\Domain\Access\CustomerRowScope},
 * for the reason {@see CustomerTaxStatusInterface} gives: a quotation's
 * customer is fixed by its deal, and `§3.3` scopes `customer.view` apart from
 * `quotation.view`, so a caller may legitimately read a quotation whose
 * customer is outside their customer scope. Neither `is_archived` nor
 * `deleted_at` filters the answer (the owner's ruling, 2026-09-21): the
 * system archives rather than deletes (`DB-01`) and the name on a row must
 * never depend on the customer's state.
 */
interface CustomerNamesInterface
{
    /**
     * Customer id => name for one page's rows. An id no customer carries has
     * no entry, so the caller falls back to the identifier it labelled before.
     * One query for N ids, never N; the empty list answers `[]` without one.
     *
     * @param  list<string>  $customerIds
     * @return array<string, string>
     */
    public function namesOf(array $customerIds): array;
}
