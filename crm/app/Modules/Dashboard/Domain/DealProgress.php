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
    /**
     * §4.4's statuses in pipeline order — the funnel's order. A copy of Deals' own
     * list (`DealStatusTransition::statuses()`), because Dashboard may not read Deals'
     * domain; `RefreshDashboardSalesTest` fails the moment the two differ.
     *
     * @var list<string>
     */
    public const PIPELINE = [
        'lead', 'contacted', 'waiting_customer_request', 'supplier_rfq', 'supplier_quotation', 'quotation_sent',
        'negotiations', 'won', 'purchasing', 'delivery', 'delivery_complete', 'lost',
    ];

    /** @var list<string> */
    public const CLOSED = ['won', 'purchasing', 'delivery', 'delivery_complete', 'lost'];

    /** `D-144`'s conversion: the deal reached Won — `won` or any status after it. @var list<string> */
    public const REACHED_WON = ['won', 'purchasing', 'delivery', 'delivery_complete'];

    /** `$part ÷ $whole × 100` to two places, null when there is nothing to divide by. */
    public static function percent(int $part, int $whole): ?string
    {
        return self::ratio($part * 100, $whole);
    }

    /**
     * `$numerator ÷ $denominator` to two places, half up, in integers — no float
     * comes near a figure (`DB-07`'s spirit). Null when there is nothing to divide by.
     */
    public static function ratio(int $numerator, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }
        $hundredths = intdiv($numerator * 200 + $denominator, 2 * $denominator);

        return intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

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
