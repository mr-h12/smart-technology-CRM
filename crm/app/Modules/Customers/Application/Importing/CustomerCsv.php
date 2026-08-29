<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Importing;

use App\Modules\Customers\Domain\Writing\CustomerDraft;
use Illuminate\Validation\ValidationException;

/**
 * §3.3's import file, read with `fgetcsv` and nothing else.
 *
 * The owner narrowed *"import (Excel)"* to CSV on 2026-08-29 — no library, the
 * standard library's own reader. Recorded in `CHECKLIST.md` awaiting a `D-xx`.
 *
 * ── What was measured in this image, rather than assumed (PHP 8.4.24) ──────
 *
 * | behaviour | result |
 * |---|---|
 * | CRLF line endings | handled; no stray `\r` reaches a value |
 * | a newline inside a quoted field | preserved, and the row is still read whole |
 * | a UTF-8 BOM | **not stripped** — the first header cell arrives as `\xEF\xBB\xBFname` |
 *
 * So the BOM is stripped here and the other two are left to `fgetcsv`. Writing
 * a hand-rolled line splitter would have broken the quoted newline that
 * `fgetcsv` already gets right.
 *
 * ── The stream is handed in, and never opened here ────────────────────────
 *
 * §14.2 puts the local file system behind an abstraction layer, and
 * `StorageServiceTest` enforces that as a property of **every** file in the
 * application: no `fopen(` outside `Modules/Storage/Infrastructure`. So this
 * class parses bytes and `StorageServiceInterface::readUploadStream()` is what
 * produced them. Going around the guard by spelling the call differently would
 * have been the same crossing with the evidence removed.
 *
 * ── The separator is sniffed, not configured ──────────────────────────────
 *
 * A `;` file is what a spreadsheet saves in a locale where `,` is the decimal
 * separator, which is the ordinary case here. Asking the uploader to declare it
 * would be a field on a form nobody wants to fill in.
 *
 * ponytail: the sniff counts `;` against `,` on the header line only. A file
 * whose header is one column has no separator to find and defaults to `,`;
 * quoted separators inside header names would fool it. Both are cheap to fix
 * when a real file needs it.
 *
 * ── Unknown columns are refused, not ignored ──────────────────────────────
 *
 * `OpenAPI §6.2` takes that line about unknown query parameters and Point 3.3
 * took it about unknown body fields. A column the importer silently drops is a
 * column the person believed they had imported.
 */
final readonly class CustomerCsv
{
    /** The header cells a file may carry — §4.2's user-entered fields, and no others. */
    public const COLUMNS = CustomerDraft::WRITABLE;

    private const BOM = "\xEF\xBB\xBF";

    /**
     * @param  resource  $handle  an open read stream; the caller closes it
     * @return list<array<string, string>> one entry per data row, keyed by column, missing cells as ''
     *
     * @throws ValidationException when the file carries no usable header
     */
    public static function rows($handle): array
    {
        $separator = self::sniff($handle);

        $header = fgetcsv($handle, 0, $separator, '"', '\\');

        if (! is_array($header)) {
            // An empty upload. `import_batches` can record a file with no data
            // rows, but not one with no columns: there is nothing to say it was
            // a customer file at all.
            throw self::refuse('customers.import.empty_file');
        }

        $columns = self::header($header);

        $rows = [];

        while (($values = fgetcsv($handle, 0, $separator, '"', '\\')) !== false) {
            $row = self::row($columns, $values);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * `,` unless the header line holds more `;`. Reads and rewinds, so the
     * caller's parse starts where it would have anyway.
     *
     * @param  resource  $handle
     */
    private static function sniff($handle): string
    {
        $line = fgets($handle);
        rewind($handle);

        if (! is_string($line)) {
            return ',';
        }

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    /**
     * @param  list<string|null>  $header
     * @return list<string>
     *
     * @throws ValidationException
     */
    private static function header(array $header): array
    {
        $columns = [];

        foreach ($header as $index => $cell) {
            $name = strtolower(trim((string) $cell));

            if ($index === 0) {
                // The measured BOM, removed once and where it arrives.
                $name = ltrim($name, self::BOM);
            }

            $columns[] = $name;
        }

        $unknown = array_values(array_diff(array_filter($columns), self::COLUMNS));

        if ($unknown !== []) {
            throw self::refuse('customers.import.unknown_columns', ['columns' => implode(', ', $unknown)]);
        }

        if (! in_array('name', $columns, true)) {
            // §4.2's one required field, and `customers_name_not_blank` is a
            // CHECK: a file without it cannot produce a single saveable row.
            throw self::refuse('customers.import.missing_name_column');
        }

        return $columns;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string|null>  $values
     * @return array<string, string>|null null for a blank line, which is not a row
     */
    private static function row(array $columns, array $values): ?array
    {
        if ($values === [null] || $values === ['']) {
            // fgetcsv reports a blank line as a single null cell. A trailing
            // newline is the ordinary way a file ends, so this is not an error.
            return null;
        }

        $row = [];

        foreach ($columns as $index => $column) {
            if ($column === '') {
                continue;
            }

            // A row shorter than the header is `D-31`'s case, not a malformed
            // file: the missing cells are missing fields, and the row is
            // flagged rather than refused.
            $row[$column] = trim((string) ($values[$index] ?? ''));
        }

        return $row;
    }

    /** @param  array<string, string>  $replace */
    private static function refuse(string $key, array $replace = []): ValidationException
    {
        // Reported against `file`, because that is the field the caller sent
        // and the only one they can correct.
        return ValidationException::withMessages([
            'file' => [(string) __($key, $replace)],
        ]);
    }
}
