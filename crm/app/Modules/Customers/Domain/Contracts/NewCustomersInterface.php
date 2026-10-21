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

    /**
     * Module 14's J-09 (`D-144`, `D-146`): live customers by creation day in `$zone`
     * and current sales owner; archived ones count. `$days` (`Y-m-d`) narrows it to
     * those days; null is every day. Read-only.
     *
     * @param  list<string>|null  $days
     * @return list<array{day: string, owner_id: ?string, customers: int}>
     */
    public function createdCountsByDay(string $zone, ?array $days = null): array;

    /**
     * The creation days (`Y-m-d` in `$zone`) of customers changed after `$since`.
     *
     * @return list<string>
     */
    public function creationDaysTouchedSince(\DateTimeInterface $since, string $zone): array;

    /**
     * Module 14's by-sector (`D-144`): each customer's sector code now (`DB-05`), null
     * when it has none; an absent id has no entry. Archived customers included. Read-only.
     *
     * @param  list<string>  $customerIds
     * @return array<string, ?string>
     */
    public function sectorsOf(array $customerIds): array;
}
