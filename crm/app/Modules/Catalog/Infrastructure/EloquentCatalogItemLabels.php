<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Domain\Contracts\CatalogItemLabelsInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\JoinClause;

/** F-16 · 1.1 — `EloquentCustomerNames`' shape over `catalog_items`. */
final readonly class EloquentCatalogItemLabels implements CatalogItemLabelsInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function labelsOf(array $catalogItemIds): array
    {
        if ($catalogItemIds === []) {
            return [];
        }

        $labels = [];
        /** @var object{id: string, label: string|null} $row */
        foreach ($this->connection->table('catalog_items')
            ->whereIn('id', $catalogItemIds)
            ->selectRaw('id, coalesce(name, service_type) as label')
            ->get() as $row) {
            if ($row->label !== null) {
                $labels[(string) $row->id] = (string) $row->label;
            }
        }

        return $labels;
    }

    public function unitsOf(array $catalogItemIds): array
    {
        if ($catalogItemIds === []) {
            return [];
        }

        $units = [];
        /** @var object{id: string, label_en: string, label_ar: string} $row */
        foreach ($this->connection->table('catalog_items')
            ->join('enum_lists', function (JoinClause $join): void {
                $join->on('enum_lists.code', '=', 'catalog_items.unit')->where('enum_lists.list', 'units');
            })
            ->whereIn('catalog_items.id', $catalogItemIds)
            // The live entry first; an archived one still names a unit already chosen.
            ->orderByRaw('enum_lists.deleted_at IS NOT NULL')
            ->get(['catalog_items.id', 'enum_lists.label_en', 'enum_lists.label_ar']) as $row) {
            $units[(string) $row->id] ??= ['en' => (string) $row->label_en, 'ar' => (string) $row->label_ar];
        }

        return $units;
    }
}
