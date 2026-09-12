<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Status;

/**
 * §6.4's approval cycle and §6.1's nine statuses as one edge table, on
 * `DealStatusTransition`'s shape (Module 7, Point 4.1).
 *
 * §6.4 draws three arrows from `Pending`: `approve` and `edit + approve` both
 * land on `Approved` (one edge), and `return with note` lands on `Draft (v2)`
 * — read here as the edge `pending → draft` on this row (the owner's Q1
 * ruling, 2026-09-12); whether Module 8 also copies the row is Module 8's
 * list. `Sent` fans out to the five customer answers §6.1 names, `expired`
 * being `J-01`'s.
 *
 * The five customer answers are terminal — an empty edge list, not a
 * self-loop. §6.3 continues Partial and Counter through a **full copy** (`D-08`,
 * Point 4.3), so nothing moves *this* row on; `Rejected` archives (Module 10)
 * and `Expired` is the job's last word. Nothing here decides *who* may make a
 * transition: each action carries its own `quotation.*` permission (§3.5),
 * checked at the route as Points 4.2–4.4 do.
 */
final class QuotationStatusTransition
{
    /** @var array<string, list<string>> */
    private const EDGES = [
        'draft' => ['pending'],
        'pending' => ['approved', 'draft'],
        'approved' => ['sent'],
        'sent' => ['accepted', 'partial', 'counter', 'rejected', 'expired'],
        'accepted' => [],
        'partial' => [],
        'counter' => [],
        'rejected' => [],
        'expired' => [],
    ];

    public static function isAllowed(string $from, string $to): bool
    {
        return in_array($to, self::EDGES[$from] ?? [], true);
    }

    /**
     * @return list<string> the statuses §6.4 permits from $from, empty when terminal
     */
    public static function allowedFrom(string $from): array
    {
        return self::EDGES[$from] ?? [];
    }
}
