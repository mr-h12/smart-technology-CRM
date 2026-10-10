<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure;

use App\Modules\Customers\Domain\Contracts\CustomerContactsInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\JoinClause;

final readonly class EloquentCustomerContacts implements CustomerContactsInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function contactOf(string $customerId): ?array
    {
        $row = $this->connection->table('customers')
            ->leftJoin('enum_lists', function (JoinClause $join): void {
                $join->on('enum_lists.code', '=', 'customers.contact_title')->where('enum_lists.list', 'contact_titles');
            })
            ->where('customers.id', $customerId)
            // The live title first; an archived one still names a title already chosen.
            ->orderByRaw('enum_lists.deleted_at IS NOT NULL')
            ->first(['customers.contact_person', 'enum_lists.label_en']);

        if ($row === null || ! is_string($row->contact_person) || trim($row->contact_person) === '') {
            return null;
        }

        return ['name' => $row->contact_person, 'title_en' => is_string($row->label_en) ? $row->label_en : null];
    }
}
