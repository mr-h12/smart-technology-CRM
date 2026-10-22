<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Contracts;

/**
 * Module 16, Point 2 (`D-153`) — what `J-16` asks Quotations: the quotations a
 * customer could still let lapse soon. Read-only.
 */
interface ExpiringQuotationsInterface
{
    /**
     * Every live `sent` quotation whose `valid_until` is between `$from` and `$to`
     * (calendar days, `Y-m-d`, both included), with its code, that day, and its
     * deal's owner — §3.5's "own"; `null` for an unowned deal.
     *
     * @return list<array{quotation_id: string, number: string, valid_until: string, owner_id: string|null}>
     */
    public function sentExpiringBetween(string $from, string $to): array;
}
