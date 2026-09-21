<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Support\Csv\CsvReader;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * F-09 · 1.3 — the CSV format rules `CustomerCsv` proved (Module 3, Points 3.6
 * and 3.7), moved to one reader so the suppliers' import (1.4) does not copy
 * them. The customers' own endpoint tests are the proof nothing changed; these
 * pin the moved rules to the class that now owns them.
 *
 * `php://memory`, as `VirusScanningTest` does: the reader takes an open stream
 * and never opens one (`StorageServiceTest`'s `fopen` guard covers `app/`).
 */
final class CsvReaderTest extends TestCase
{
    private const COLUMNS = ['name', 'phone', 'contact_person'];

    private const ALIASES = ['contact' => 'contact_person'];

    public function test_that_a_bom_does_not_corrupt_the_first_column(): void
    {
        self::assertSame([['name' => 'Alpha', 'phone' => '0100']], $this->read("\xEF\xBB\xBFname,phone\nAlpha,0100\n"));
    }

    public function test_that_semicolons_and_crlf_are_read(): void
    {
        self::assertSame(
            [['name' => 'Alpha', 'phone' => '0100'], ['name' => 'Beta', 'phone' => '0111']],
            $this->read("name;phone\r\nAlpha;0100\r\nBeta;0111\r\n"),
        );
    }

    public function test_that_a_quoted_field_keeps_its_newline(): void
    {
        self::assertSame([['name' => "Alpha\nBranch"]], $this->read("name\n\"Alpha\nBranch\"\n"));
    }

    public function test_that_a_header_is_matched_by_the_word_and_by_an_alias(): void
    {
        self::assertSame(
            [['name' => 'Alpha', 'contact_person' => 'Sara']],
            $this->read("Name,Contact\nAlpha,Sara\n"),
        );
        self::assertSame(
            [['name' => 'Alpha', 'contact_person' => 'Sara']],
            $this->read("NAME,Contact-Person\nAlpha,Sara\n"),
        );
    }

    public function test_that_an_empty_file_is_refused(): void
    {
        self::assertSame([__('customers.import.empty_file')], $this->refusal(''));
    }

    public function test_that_an_unknown_column_is_refused_as_written(): void
    {
        self::assertSame(
            [__('customers.import.unknown_columns', ['columns' => 'Sales rep'])],
            $this->refusal("name,Sales rep\nAlpha,Omar\n"),
        );
    }

    public function test_that_a_field_named_twice_is_refused(): void
    {
        self::assertSame(
            [__('customers.import.duplicate_columns', ['columns' => 'contact_person'])],
            $this->refusal("name,contact,contact_person\nAlpha,Sara,Sara\n"),
        );
    }

    public function test_that_a_file_without_a_name_column_is_refused(): void
    {
        self::assertSame([__('customers.import.missing_name_column')], $this->refusal("phone\n0100\n"));
    }

    public function test_that_a_blank_line_is_not_a_row(): void
    {
        self::assertSame([['name' => 'Alpha'], ['name' => 'Beta']], $this->read("name\nAlpha\n\nBeta\n"));
    }

    /** `D-31`: a short row is missing fields, not a malformed file. */
    public function test_that_a_short_row_is_padded_with_empty_cells(): void
    {
        self::assertSame(
            [['name' => 'Alpha', 'phone' => '', 'contact_person' => '']],
            $this->read("name,phone,contact_person\nAlpha\n"),
        );
    }

    public function test_that_an_empty_header_cell_is_skipped(): void
    {
        self::assertSame([['name' => 'Alpha']], $this->read("name,\nAlpha,ignored\n"));
    }

    /** The prefix is the caller's: suppliers will name their own messages. */
    public function test_that_the_messages_come_from_the_caller_prefix(): void
    {
        $stream = $this->stream('');

        try {
            CsvReader::rows($stream, self::COLUMNS, self::ALIASES, 'some.prefix');
            self::fail('An empty file was accepted.');
        } catch (ValidationException $e) {
            self::assertSame(['some.prefix.empty_file'], $e->errors()['file']);
        } finally {
            fclose($stream);
        }
    }

    // ───────────────────────────────────────────────────────────── helpers

    /** @return list<array<string, string>> */
    private function read(string $csv): array
    {
        $stream = $this->stream($csv);

        try {
            return CsvReader::rows($stream, self::COLUMNS, self::ALIASES, 'customers.import');
        } finally {
            fclose($stream);
        }
    }

    /** @return mixed the messages reported against `file` */
    private function refusal(string $csv): mixed
    {
        try {
            $this->read($csv);
        } catch (ValidationException $e) {
            return $e->errors()['file'] ?? null;
        }

        self::fail('The reader accepted a file it had to refuse.');
    }

    /** @return resource */
    private function stream(string $csv)
    {
        $stream = fopen('php://memory', 'rb+');
        self::assertIsResource($stream);
        fwrite($stream, $csv);
        rewind($stream);

        return $stream;
    }
}
