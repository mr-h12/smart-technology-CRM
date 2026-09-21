<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application\Importing;

use App\Support\Csv\CsvReader;
use Illuminate\Validation\ValidationException;

/**
 * `D-85`'s import file: which columns a supplier file may carry. How a file is
 * read — the separator, the BOM, the header matching and its refusals — is
 * `CsvReader`'s (F-09 · 1.3).
 *
 * `color_rating` is **not** a column: `D-19` makes the colour a manual rating
 * and §7.1's White is "new / not yet rated", so a file carrying one is refused
 * as an unknown column rather than silently dropped. `is_active` is not one
 * either: an imported supplier is a working one.
 *
 * No aliases. `contact` is the customers' (§4.2's "Single contact"); §7.1
 * gives suppliers no second word for a field, and inventing one is a guess
 * about a file nobody has shown.
 */
final readonly class SupplierCsv
{
    public const COLUMNS = ['name', 'type', 'phone', 'contact_person', 'has_open_account'];

    /**
     * @param  resource  $handle  an open read stream; the caller closes it
     * @return list<array<string, string>> one entry per data row, keyed by column, missing cells as ''
     *
     * @throws ValidationException when the file carries no usable header
     */
    public static function rows($handle): array
    {
        return CsvReader::rows($handle, self::COLUMNS, [], 'suppliers.import');
    }
}
