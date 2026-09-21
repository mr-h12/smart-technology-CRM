<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure;

use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * F-07 · 1.2 — `customers.name` by id, the shape of `EloquentUserFacts` less
 * its filters: deliberately no `deleted_at`, no `is_archived`, no scope.
 */
final readonly class EloquentCustomerNames implements CustomerNamesInterface
{
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
}
