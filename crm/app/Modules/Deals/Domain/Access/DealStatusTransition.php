<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Access;

/**
 * §4.4's graph, transcribed from the table rather than the ASCII diagram
 * above it.
 *
 * The diagram draws `↘ Lost` once, near `Won`, which reads as branching from
 * one place. The table is the one this class follows, because it names both
 * sources explicitly: `"Quotation Sent | Sales (after approval) |
 * Negotiations / Won / Lost"` and `"Negotiations | Sales | Won / Lost"` —
 * `Lost` is reachable from exactly these two, not from `Lead` through
 * `Supplier Quotation`, which the diagram's single arrow could be misread to
 * imply.
 *
 * `Lost` and `Delivery Complete` are terminal — an empty edge list, not a
 * self-loop — because §4.4 says so ("Terminal") for both. Nothing here
 * enforces *who* may make a given transition beyond the coarse
 * `deal.change_status` scope already checked (Point 2.1): §4.4's "Who
 * changes it" column names roles that overlap exactly with the roles already
 * holding that one permission, and no second permission is seeded in
 * `PermissionMatrix` for any transition except `Delivery → Delivery
 * Complete`, which `deal.mark_delivery_complete` covers separately
 * (`ChangeDealStatus`, `D-14`). Inventing a finer-grained check the matrix
 * does not seed would be exactly the kind of authorisation rule this
 * project's other row-scope classes refuse to guess at.
 */
final class DealStatusTransition
{
    /** @var array<string, list<string>> */
    private const EDGES = [
        'lead' => ['contacted'],
        'contacted' => ['waiting_customer_request'],
        'waiting_customer_request' => ['supplier_rfq'],
        'supplier_rfq' => ['supplier_quotation'],
        'supplier_quotation' => ['quotation_sent'],
        'quotation_sent' => ['negotiations', 'won', 'lost'],
        'negotiations' => ['won', 'lost'],
        'won' => ['purchasing'],
        'purchasing' => ['delivery'],
        'delivery' => ['delivery_complete'],
        'lost' => [],
        'delivery_complete' => [],
    ];

    public static function isAllowed(string $from, string $to): bool
    {
        return in_array($to, self::EDGES[$from] ?? [], true);
    }

    /**
     * @return list<string> the states §4.4 permits from $from, empty when terminal
     */
    public static function allowedFrom(string $from): array
    {
        return self::EDGES[$from] ?? [];
    }
}
