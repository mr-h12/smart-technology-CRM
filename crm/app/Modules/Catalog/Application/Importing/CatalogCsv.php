<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Importing;

use App\Support\Csv\CsvReader;
use Illuminate\Validation\ValidationException;

/** `D-86`'s columns (F-10 · 1.5): every `catalog_items` column a person writes, plus `supplier`. */
final readonly class CatalogCsv
{
    public const COLUMNS = [
        'kind', 'product_code', 'name', 'category', 'unit', 'service_type',
        'description', 'company', 'notes', 'is_active', 'supplier',
    ];

    /**
     * @param  resource  $handle  an open read stream; the caller closes it
     * @return list<array<string, string>> one entry per data row, keyed by column, missing cells as ''
     *
     * @throws ValidationException when the file carries no usable header
     */
    public static function rows($handle): array
    {
        return CsvReader::rows($handle, self::COLUMNS, [], 'catalog.import');
    }
}
