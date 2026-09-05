<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 6, Point 1.1 — the `supplier_quotations` table.
 *
 * §7.2's field list is read out of the master documentation rather than
 * retyped here, on `DealSchemaMigrationTest`'s precedent: a column dropped
 * from both the migration and a hand-written list would pass a check that
 * only agrees with itself.
 *
 * Three of §7.2's rows are deliberately **not** columns, and each is asserted
 * as an exception with its own reason rather than quietly skipped:
 *
 * - `pdf_file` — `D-71` puts attachments in `supplier_quotation_files`, not in
 *   a column, because a column cannot carry the two foreign keys that decision
 *   exists to provide.
 * - `Line items` — Point 1.2's own table.
 * - `entered_by` — `DB-02`'s `created_by`, already on every table through
 *   `standardColumns()`. A second column for the same fact is the duplicate
 *   `CLAUDE.md`'s waste audit exists to catch.
 * - `currency` — stored as `currency_id`, because `currencies.code` is unique
 *   only among live rows and PostgreSQL cannot point a foreign key at a
 *   partial unique index. Asserted below as a column that must **not** exist
 *   beside one that must.
 *
 * See the migration's docblock for why `supplier_id` is the one required link
 * (§4.1 draws exactly one line into a supplier quotation), why `deal_id` is
 * nullable (`D-51`), and why the currency is a `currency_id` rather than a
 * three-letter code.
 */
final class SupplierQuotationSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    private const UNIQUE_VIOLATION = '23505';

    /**
     * §7.2 rows that are not columns of this table, each with the source that
     * puts them elsewhere.
     */
    private const NOT_COLUMNS = [
        'pdf_file' => 'D-71 — the `supplier_quotation_files` pivot',
        'Line items' => 'Point 1.2 — `supplier_quotation_items`',
        'entered_by' => 'DB-02 — `created_by`',
        'currency' => 'the id, not the code — `currencies.code` is only partially unique',
    ];

    private string $supplierId;

    private string $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplierId = Uuid::uuid4()->toString();
        $this->currencyId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert([
            'id' => $this->supplierId,
            'name' => 'Alpha Supplies',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('currencies')->insert([
            'id' => $this->currencyId,
            'code' => 'EGP',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'is_base' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────── the block

    public function test_that_the_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('supplier_quotations'));
    }

    public function test_that_the_table_carries_the_block_section_4_8_requires(): void
    {
        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            self::assertTrue(
                Schema::hasColumn('supplier_quotations', $column),
                "`supplier_quotations` is missing {$column} (DB-02).",
            );
        }

        self::assertTrue(
            Schema::hasColumn('supplier_quotations', 'deleted_at'),
            '`supplier_quotations` is missing the DB-01 soft delete.',
        );
    }

    /** `DB-07`. This table carries money, so the rule has real work here. */
    public function test_that_no_column_stores_a_float(): void
    {
        self::assertTrue(Schema::hasTable('supplier_quotations'), 'no table, so this check proves nothing.');

        $floats = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            ['supplier_quotations', 'double precision', 'real'],
        );

        self::assertSame([], $floats, '`supplier_quotations` has a float column — DB-07 forbids it.');
    }

    // ──────────────────────────────────────────────────────────── §7.2's fields

    public function test_that_every_field_section_7_2_publishes_is_accounted_for(): void
    {
        $fields = self::documentedFields();

        self::assertGreaterThanOrEqual(
            8,
            count($fields),
            '§7.2 published fewer fields than expected — the parser is reading the wrong table.',
        );

        foreach ($fields as $field) {
            if (array_key_exists($field, self::NOT_COLUMNS)) {
                self::assertFalse(
                    Schema::hasColumn('supplier_quotations', $field),
                    "§7.2's `{$field}` lives elsewhere (".self::NOT_COLUMNS[$field].') and must not be a column here.',
                );

                continue;
            }

            self::assertTrue(
                Schema::hasColumn('supplier_quotations', $field),
                "§7.2 publishes `{$field}` and `supplier_quotations` has no such column.",
            );
        }

        // The one exception that is a *rename* rather than a relocation: the
        // fact §7.2 calls `currency` is on this table, under the name a
        // foreign key can actually use.
        self::assertTrue(
            Schema::hasColumn('supplier_quotations', 'currency_id'),
            '§7.2 publishes `currency` and neither it nor `currency_id` is a column.',
        );
    }

    // ─────────────────────────────────────────────────── `code`, SQ-2026-0001

    public function test_that_a_quotation_without_a_code_is_refused(): void
    {
        self::assertSame(self::NOT_NULL_VIOLATION, $this->refusedWith(fn () => $this->insert(['code' => null])));
    }

    public function test_that_two_quotations_cannot_share_a_code(): void
    {
        $this->insert(['code' => 'SQ-2026-0001']);

        self::assertSame(
            self::UNIQUE_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['code' => 'SQ-2026-0001'])),
        );
    }

    // ───────────────────────────────────────────── the supplier, and the deal

    public function test_that_a_quotation_without_a_supplier_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['supplier_id' => null])),
        );
    }

    public function test_that_an_unknown_supplier_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['supplier_id' => Uuid::uuid4()->toString()])),
        );
    }

    /** `D-51`: standalone, and available to any deal. */
    public function test_that_a_quotation_needs_no_deal(): void
    {
        $id = $this->insert(['deal_id' => null]);

        self::assertNull(DB::table('supplier_quotations')->where('id', $id)->value('deal_id'));
    }

    public function test_that_an_unknown_deal_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['deal_id' => Uuid::uuid4()->toString()])),
        );
    }

    // ───────────────────────────────────────────── the price and its currency

    public function test_that_a_price_and_a_currency_may_both_be_absent(): void
    {
        $id = $this->insert(['total_price' => null, 'currency_id' => null]);

        self::assertNull(DB::table('supplier_quotations')->where('id', $id)->value('total_price'));
    }

    public function test_that_a_price_without_a_currency_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['total_price' => '1500.00', 'currency_id' => null])),
        );
    }

    public function test_that_a_currency_without_a_price_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['total_price' => null, 'currency_id' => $this->currencyId])),
        );
    }

    public function test_that_an_unknown_currency_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert([
                'total_price' => '1500.00',
                'currency_id' => Uuid::uuid4()->toString(),
            ])),
        );
    }

    // ─────────────────────────────────────────────────────────────── `DB-09`

    public function test_that_the_columns_db_09_names_are_indexed(): void
    {
        foreach (['supplier_id', 'deal_id', 'offer_date'] as $column) {
            self::assertNotSame(
                [],
                DB::select(
                    'select indexname from pg_indexes where tablename = ? and indexdef like ?',
                    ['supplier_quotations', '%('.$column.')%'],
                ),
                "DB-09 names this category and `{$column}` has no index.",
            );
        }
    }

    // ───────────────────────── the debt `create_files_and_attachment_pivots` recorded

    public function test_that_supplier_quotation_files_refuses_a_parent_that_does_not_exist(): void
    {
        $fileId = Uuid::uuid4()->toString();

        DB::table('files')->insert([
            'id' => $fileId,
            'original_name' => 'offer.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_path' => '2026/09/supplier_quotation/'.$fileId.'.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame(self::FOREIGN_KEY_VIOLATION, $this->refusedWith(fn () => DB::table('supplier_quotation_files')->insert([
            'supplier_quotation_id' => Uuid::uuid4()->toString(),
            'file_id' => $fileId,
        ])));
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    /**
     * `migrate:reset`, not `migrate:rollback --path`: `--path` does not choose
     * which migrations roll back. `Migrator::rollback()` takes the whole last
     * batch from the repository and uses the path only to resolve each entry to
     * a file, skipping what it cannot resolve ("Migration not found") — so under
     * `RefreshDatabase`, where the schema is a single batch, a one-file path
     * means "run this down() while every later table still stands", and any new
     * child foreign key turns this red (`SQLSTATE[2BP01]`) without this down()
     * having changed. Same shelf life, same fix, as `RbacSchemaMigrationTest`
     * and `AuditLogMigrationTest`. `DEV-03` asks that rollback work; rolling the
     * chain back and forward proves more of it, not less.
     */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);

        self::assertFalse(
            Schema::hasTable('supplier_quotations'),
            'down() left `supplier_quotations` behind (DEV-03).',
        );

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasTable('supplier_quotations'), '`supplier_quotations` did not come back.');
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('supplier_quotations')->insert(array_merge([
            'id' => $id,
            'code' => 'SQ-2026-'.substr(str_replace('-', '', $id), -4),
            'supplier_id' => $this->supplierId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function refusedWith(callable $write): string
    {
        try {
            $write();
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;

            self::assertIsString($state);

            return $state;
        }

        self::fail('The database accepted a row it should have refused.');
    }

    /** @return list<string> */
    private static function documentedFields(): array
    {
        self::assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted. This fails rather than skips: without it '
            .'these checks would only be agreeing with the migration that wrote them.',
        );

        $body = (string) file_get_contents(self::MASTER_DOCUMENTATION);

        $start = strpos($body, '### 7.2 Supplier Quotations');
        self::assertIsInt($start, '§7.2 is no longer a heading in the master documentation.');

        $end = strpos($body, '### 7.3', $start);
        self::assertIsInt($end, '§7.3 no longer follows §7.2 — the slice would run to the end of the file.');

        $fields = [];

        foreach (explode("\n", substr($body, $start, $end - $start)) as $line) {
            if (! str_starts_with(trim($line), '|')) {
                continue;
            }

            $cell = trim(explode('|', $line)[1] ?? '');

            if ($cell === '' || $cell === 'Field' || str_starts_with($cell, '---')) {
                continue;
            }

            foreach (explode('·', $cell) as $name) {
                $fields[] = trim($name);
            }
        }

        return $fields;
    }
}
