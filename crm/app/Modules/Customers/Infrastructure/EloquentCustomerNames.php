<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure;

use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use App\Modules\Customers\Domain\Contracts\NewCustomersInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * F-07 · 1.2 — `customers.name` by id, the shape of `EloquentUserFacts` less
 * its filters: deliberately no `deleted_at`, no `is_archived`, no scope.
 */
final readonly class EloquentCustomerNames implements CustomerNamesInterface, NewCustomersInterface
{
    /**
     * `(created_at AT TIME ZONE ?)::date` — the day a `timestamptz` falls on in the company's zone.
     */
    private const DAY = '(created_at AT TIME ZONE ?)::date';

    public function __construct(private ConnectionInterface $connection) {}

    public function namesOf(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $names = [];
        /** @var object{id: string, name: string} $row */
        foreach ($this->connection->table('customers')
            ->whereIn('id', $customerIds)
            ->select('id', 'name')
            ->get() as $row) {
            $names[(string) $row->id] = (string) $row->name;
        }

        return $names;
    }

    public function createdBetween(string $creatorId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = [];
        /** @var object{id: string, name: string} $row */
        foreach ($this->connection->table('customers')
            ->where('created_by', $creatorId)->whereNull('deleted_at')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->orderBy('created_at')->orderBy('id')
            ->select('id', 'name')
            ->get() as $row) {
            $rows[] = ['customer_id' => (string) $row->id, 'name' => (string) $row->name];
        }

        return $rows;
    }

    public function createdCountsByDay(string $zone, ?array $days = null): array
    {
        if ($days === []) {
            return [];
        }

        $query = $this->connection->table('customers')->whereNull('deleted_at')
            ->selectRaw('('.self::DAY.')::text AS day, sales_owner_id AS owner_id, count(*) AS customers', [$zone])
            ->groupByRaw('1, sales_owner_id')
            ->orderByRaw('1')->orderBy('sales_owner_id');

        if ($days !== null) {
            $query->whereRaw('('.self::DAY.')::text IN ('.implode(', ', array_fill(0, count($days), '?')).')', [$zone, ...$days]);
        }

        $rows = [];
        /** @var object{day: string, owner_id: ?string, customers: int} $row */
        foreach ($query->get() as $row) {
            $rows[] = ['day' => $row->day, 'owner_id' => $row->owner_id, 'customers' => (int) $row->customers];
        }

        return $rows;
    }

    public function creationDaysTouchedSince(\DateTimeInterface $since, string $zone): array
    {
        /** @var list<string> $days */
        $days = $this->connection->table('customers')
            ->where(fn (Builder $q) => $q->where('updated_at', '>', $since)->orWhere('deleted_at', '>', $since))
            ->selectRaw('DISTINCT ('.self::DAY.')::text AS day', [$zone])
            ->orderBy('day')
            ->pluck('day')->all();

        return $days;
    }
}
