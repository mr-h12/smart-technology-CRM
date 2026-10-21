<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Domain;

/**
 * `D-144`: a deal is **closed** once the sales decision is made — `won` or any
 * status after it (§4.4: `purchasing`, `delivery`, `delivery_complete`), or
 * `lost` — and **open** before that.
 */
final class DealProgress
{
    /** @var list<string> */
    public const CLOSED = ['won', 'purchasing', 'delivery', 'delivery_complete', 'lost'];

    /**
     * @param  array<string, int>  $byStatus
     * @return array{total: int, open: int, closed: int}
     */
    public static function split(array $byStatus): array
    {
        $total = array_sum($byStatus);
        $closed = array_sum(array_intersect_key($byStatus, array_flip(self::CLOSED)));

        return ['total' => $total, 'open' => $total - $closed, 'closed' => $closed];
    }
}
