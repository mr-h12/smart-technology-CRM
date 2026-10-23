<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Domain\Contracts\CatalogItemLabelsInterface;
use Illuminate\Database\ConnectionInterface;

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

    public function unitsOf(array $unitCodes): array
    {
        if ($unitCodes === []) {
            return [];
        }

        $units = [];
        /** @var object{code: string, label_en: string, label_ar: string} $row */
        foreach ($this->connection->table('enum_lists')
            ->where('list', 'units')
            ->whereIn('code', $unitCodes)
            // The live entry first; an archived one still names a unit already chosen.
            ->orderByRaw('deleted_at IS NOT NULL')
            ->get(['code', 'label_en', 'label_ar']) as $row) {
            $units[(string) $row->code] ??= ['en' => (string) $row->label_en, 'ar' => (string) $row->label_ar];
        }

        return $units;
    }
}
