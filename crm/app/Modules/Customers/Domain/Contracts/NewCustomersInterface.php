<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Contracts;

/**
 * `D-134`, RPT-WK: the customers a user added in a period — name only, as
 * {@see CustomerNamesInterface} is, so a report is not thereby granted customer data.
 */
interface NewCustomersInterface
{
    /**
     * The customers `$creatorId` added with `created_at` in `[$from, $to)` (instants), oldest
     * first. A live row only (`DB-01`); archived ones count.
     *
     * @return list<array{customer_id: string, name: string}>
     */
    public function createdBetween(string $creatorId, \DateTimeInterface $from, \DateTimeInterface $to): array;
}
