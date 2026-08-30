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
 *
 * ── A header is matched by the word, not by the identifier (Point 3.7) ─────
 *
 * Added after a real export was refused in the running application on
 * 2026-08-30: its header line read `Name,Sector,Region,Contact,Phone,Second
 * phone,WhatsApp,Email,Start date`, and three of those nine were rejected
 * because the reader compared them character for character against §4.2's
 * column identifiers. Nothing in the sources says a file must repeat an
 * identifier's punctuation, and a refusal over a space is one nobody can act on
 * without being shown the schema.
 *
 * So a header cell is normalised — lower-cased, and any run of spaces or
 * hyphens collapsed into a single underscore — before it is matched. That alone
 * settles `Start date`, `Contact Person` and every other multi-word field, and
 * it invents no vocabulary: it is the same word with the spreadsheet's
 * punctuation.
 *
 * {@see self::ALIASES} then covers the two headers that are a *different* word
 * for the same field, and each of the two is quoted from a source rather than
 * guessed. **It is a constant, not a lookup into the lang files.** Deriving the
 * accepted set from `customers.attributes` would make a data-import contract
 * change whenever a translator edits a label, and make it depend on the
 * caller's locale — a file that imports for one user and is refused for another.
 *
 * ── One field, one column ─────────────────────────────────────────────────
 *
 * Aliases create a way for two different headers to mean one field, so they owe
 * a guard: a field named twice is refused. Whichever column won would be a
 * silent choice between two columns a person filled in on purpose — the same
 * defect as dropping an unknown one. The guard covers the plain case too
 * (`name,name`), which the reader used to accept with the last column winning.
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
        $unknown = [];
        $seen = [];
        $twice = [];

        foreach ($header as $index => $cell) {
            $written = trim((string) $cell);

            if ($index === 0) {
                // The measured BOM, removed once and where it arrives — before
                // anything tries to read the first cell as a word.
                $written = ltrim($written, self::BOM);
            }

            // An empty header cell is not a column. `row()` skips it, and a
            // trailing separator is an ordinary way for a file to be written.
            if ($written === '') {
                $columns[] = '';

                continue;
            }

            $column = self::field($written);

            if ($column === null) {
                // Kept **as the person wrote it**. Reporting the normalised
                // form would answer a complaint about `Sales rep` with the word
                // `sales_rep`, which describes the importer's internals to
                // somebody looking for their own spreadsheet column.
                $unknown[] = $written;
                $columns[] = '';

                continue;
            }

            if (in_array($column, $seen, true)) {
                $twice[] = $column;
            }

            $seen[] = $column;
            $columns[] = $column;
        }

        if ($unknown !== []) {
            throw self::refuse('customers.import.unknown_columns', ['columns' => implode(', ', $unknown)]);
        }

        if ($twice !== []) {
            // The **field**, not the headers that named it: two different words
            // can collide here, and the field is the thing the person has to
            // decide about.
            throw self::refuse('customers.import.duplicate_columns', [
                'columns' => implode(', ', array_values(array_unique($twice))),
            ]);
        }

        if (! in_array('name', $columns, true)) {
            // §4.2's one required field, and `customers_name_not_blank` is a
            // CHECK: a file without it cannot produce a single saveable row.
            throw self::refuse('customers.import.missing_name_column');
        }

        return $columns;
    }

    /**
     * The §4.2 field a header cell names, or null when it names none.
     *
     * The normalisation is deliberately small: lower-case, and any run of
     * spaces or hyphens collapsed to one underscore. It is not a fuzzy match —
     * `Sales rep` still has to be refused, because a reader that guessed at it
     * would import a column into a field nobody chose.
     */
    private static function field(string $written): ?string
    {
        $normalised = preg_replace('/[\s\-]+/', '_', strtolower($written));

        // `preg_replace` answers null only on a malformed pattern; the pattern
        // is a literal here, so this narrows rather than handles.
        if (! is_string($normalised)) {
            return null;
        }

        $normalised = self::ALIASES[$normalised] ?? $normalised;

        return in_array($normalised, self::COLUMNS, true) ? $normalised : null;
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
