<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Importing;

use App\Modules\Customers\Domain\Writing\CustomerDraft;
use App\Support\Csv\CsvReader;
use Illuminate\Validation\ValidationException;

/**
 * §3.3's import file: which columns a customer file may carry, and the two
 * other words for them. How a file is read — the separator sniff, the BOM, the
 * header matching and its refusals — is `App\Support\Csv\CsvReader`, moved out
 * of this class unchanged in F-09 · 1.3 so suppliers read files the same way.
 */
final readonly class CustomerCsv
{
    /** The header cells a file may carry — §4.2's user-entered fields, and no others. */
    public const COLUMNS = CustomerDraft::WRITABLE;

    /**
     * Headers that are a different word for a field, normalised form on the left.
     *
     * Two entries, each quoted from a source rather than invented:
     *
     * - `contact` — §4.2 describes the field as *"Single contact (D-18)"*, so
     *   the document's own shorter word for `contact_person`.
     * - `second_phone` — `customers.attributes.phone2` is already the words
     *   **"second phone"**, which is what every validation message calls the
     *   field to the person now filling in the file.
     *
     * Nothing else is here. `phone_2`, `mobile`, an Arabic label: each would be
     * a guess about a file nobody has shown, and each is one line the day
     * somebody does.
     */
    private const ALIASES = [
        'contact' => 'contact_person',
        'second_phone' => 'phone2',
    ];

    /**
     * @param  resource  $handle  an open read stream; the caller closes it
     * @return list<array<string, string>> one entry per data row, keyed by column, missing cells as ''
     *
     * @throws ValidationException when the file carries no usable header
     */
    public static function rows($handle): array
    {
        return CsvReader::rows($handle, self::COLUMNS, self::ALIASES, 'customers.import');
    }
}
