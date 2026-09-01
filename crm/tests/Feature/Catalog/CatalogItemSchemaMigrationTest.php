<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 4, Point 1.2 — `catalog_items`, the table §7.3 publishes.
 *
 * ── §7.3 is shaped differently from §7.1, so it is read differently ────────
 *
 * §4.2 and §7.1 are `| Field | Notes |` tables whose first cell *is* the
 * column name, so Module 3 Point 1.1 and Module 4 Point 1.1 could map them
 * mechanically. §7.3 is not that: it is a two-column `| Product | Service |`
 * layout whose cells are prose labels — "Category (for search)", "Description
 * · active product". A mechanical map is impossible, so the translation is
 * written out below as `LABELS` and **the document is still the authority**:
 * `test_that_section_7_3_publishes_no_label_this_table_has_not_placed` fails
 * if §7.3 ever grows a label the map does not know. Without that second test
 * the map would be a hand-written list agreeing with itself, which is the
 * failure mode Point 1.1 was written to avoid.
 *
 * ── One table, because the build plan names one ────────────────────────────
 *
 * `MVP_Build_Plan_EN.md` Module 4 lists "Tables: `catalog_items` · `suppliers`"
 * — a product and a service are two *tabs*, not two tables. `kind` carries the
 * split and every type-specific column is nullable, with the conditional rules
 * belonging to Point 3.2's Form Request rather than to the schema.
 *
 * ── `company` is shared, by the owner's decision of 2026-08-30 ─────────────
 *
 * §7.3 lists "Providing team / company" under **Service** only, while its own
 * prose ("Two tabs: Product · Service, grouped by company/team name") and the
 * build plan's acceptance criterion ("New product appears under the Product
 * tab, **grouped by company**") both require it of a product too. Two sources
 * against one silence; the owner chose the shared column. Awaiting a `D-xx`.
 *
 * ── No prices, and that is the criterion this file ticks ───────────────────
 *
 * §7.3 opens "Descriptive data only — **no prices**" and `D-21` puts every
 * price on the supplier quotation. That is asserted here as the *absence* of a
 * money column rather than trusted to review, which is what makes the
 * checklist line "Catalog holds no prices" a check instead of an opinion.
 */
final class CatalogItemSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    /** §7.3's two tabs. */
    private const KINDS = ['product', 'service'];

    /**
     * §7.3's labels, and the columns each one becomes.
     *
     * Two labels name more than one column: "Description · active product" is
     * the description and the flag, and every "active" label is the same flag.
     * `company` appears against the service label because that is the only
     * place §7.3 writes it — the owner's decision makes the column shared, not
     * the document.
     *
     * @var array<string, list<string>>
     */
    private const LABELS = [
        // The Product column.
        'Product code' => ['product_code'],
        'Product name' => ['name'],
        'Category (for search)' => ['category'],
        'Unit (piece · metre · kilo · extendable)' => ['unit'],
        'Description · active product' => ['description', 'is_active'],

        // The Service column.
        'Service type (installation · repair · maintenance · setup · extendable)' => ['service_type'],
        'Service description (optional)' => ['description'],
        'Providing team / company' => ['company'],
        'Active service (yes/no)' => ['is_active'],
        'Notes' => ['notes'],
    ];

    /** @return list<array{string}> */
    public static function kinds(): array
    {
        return array_map(static fn (string $k): array => [$k], self::KINDS);
    }

    // ─────────────────────────────────────────────────────────── the block

    public function test_that_the_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('catalog_items'), 'Module 4 needs `catalog_items`.');
    }

    public function test_that_the_table_carries_the_block_section_4_8_requires(): void
    {
        $rule = self::documentationLineContaining('DB-02');

        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            self::assertStringContainsString($column, $rule, "§4.8's DB-02 no longer names {$column}.");
            self::assertTrue(Schema::hasColumn('catalog_items', $column), "`catalog_items` is missing {$column}.");
        }

        self::assertTrue(Schema::hasColumn('catalog_items', 'deleted_at'), 'Missing the DB-01 soft delete.');
    }

    public function test_that_no_column_stores_a_float(): void
    {
        self::assertTrue(Schema::hasTable('catalog_items'), 'The table does not exist, so this proves nothing.');

        $floats = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            ['catalog_items', 'double precision', 'real'],
        );

        self::assertSame([], $floats, '`catalog_items` has a float column — DB-07 forbids it.');
    }

    // ──────────────────────────────────────────────────── §7.3 / D-21: no prices

    /**
     * The catalog holds no price, in the only way a schema can say so.
     *
     * Both halves again: the documentation still forbids it, and the table
     * still has nothing that could hold one. A `numeric` column is what `DB-07`
     * requires money to be, so its absence is the strongest available signal.
     */
    public function test_that_the_catalog_stores_no_price(): void
    {
        $catalog = self::documentationSlice('### 7.3 Catalog', '## 8.');

        self::assertStringContainsString(
            'no prices',
            $catalog,
            '§7.3 no longer says the catalog is descriptive only.',
        );

        self::assertStringContainsString(
            'the catalog holds descriptive data only',
            self::documentationLineContaining('| D-21 |'),
            'D-21 no longer keeps prices on the supplier quotation.',
        );

        $numeric = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type = ?',
            ['catalog_items', 'numeric'],
        );

        self::assertSame([], $numeric, 'A numeric column on the catalog is a price. D-21 puts prices on the offer.');

        foreach (Schema::getColumnListing('catalog_items') as $column) {
            // `getColumnListing()` is typed `mixed[]`, and PHPStan level 10
            // refuses it as a string. Narrowed by assertion rather than by a
            // cast, which is the escape hatch Coding Standards §5 forbids —
            // the same way `UserSchemaMigrationTest::columns()` does it.
            self::assertIsString($column);

            self::assertDoesNotMatchRegularExpression(
                '/price|cost|margin|amount/i',
                $column,
                "`{$column}` names money, and §7.3 makes the catalog descriptive only.",
            );
        }
    }

    // ────────────────────────────────────────────────────────── §7.3's fields

    public function test_that_every_label_section_7_3_publishes_has_a_column(): void
    {
        foreach (self::LABELS as $label => $columns) {
            foreach ($columns as $column) {
                self::assertTrue(
                    Schema::hasColumn('catalog_items', $column),
                    "§7.3 publishes `{$label}` and `catalog_items` has no `{$column}`.",
                );
            }
        }
    }

    /**
     * The map is not allowed to fall behind the document.
     *
     * `LABELS` is a hand-written translation, and a hand-written list is only
     * trustworthy while something forces it to keep up. This reads §7.3 and
     * fails on any label the map has not placed.
     */
    public function test_that_section_7_3_publishes_no_label_this_table_has_not_placed(): void
    {
        $published = self::documentedCatalogLabels();

        self::assertGreaterThanOrEqual(
            10,
            count($published),
            '§7.3 published fewer labels than expected — the parser is reading the wrong table.',
        );

        foreach ($published as $label) {
            self::assertArrayHasKey(
                $label,
                self::LABELS,
                "§7.3 publishes `{$label}` and this table has not placed it in a column.",
            );
        }
    }

    // ────────────────────────────────────────────────────────── §7.3's two tabs

    #[DataProvider('kinds')]
    public function test_that_each_tab_section_7_3_opens_is_accepted(string $kind): void
    {
        $id = $this->insert(['kind' => $kind]);

        self::assertSame($kind, DB::table('catalog_items')->where('id', $id)->value('kind'));
    }

    public function test_that_a_kind_section_7_3_does_not_open_a_tab_for_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['kind' => 'bundle'])),
        );
    }

    /** A row belonging to neither tab appears on no screen §7.3 describes. */
    public function test_that_an_item_belonging_to_no_tab_is_refused(): void
    {
        self::assertSame(self::NOT_NULL_VIOLATION, $this->refusedWith(fn () => $this->insert(['kind' => null])));
    }

    /** §7.3 marks nothing required, so a blank name is the boundary's to reject — but not a silent one. */
    public function test_that_a_blank_name_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->insert(['name' => '   '])));
    }

    public function test_that_an_item_may_be_saved_before_its_name_is_known(): void
    {
        $id = $this->insert(['name' => null]);

        self::assertNull(DB::table('catalog_items')->where('id', $id)->value('name'));
    }

    // ───────────────────────────────────────────── the owner's decision on company

    /**
     * Both tabs group by company (owner's decision, 2026-08-30, option (a)).
     *
     * The build plan requires "New product appears under the Product tab,
     * grouped by company" and §7.3's prose groups the whole catalog by
     * "company/team name", while §7.3's field table writes the label only
     * against Service. One shared column serves both.
     */
    #[DataProvider('kinds')]
    public function test_that_either_tab_can_record_the_company_it_is_grouped_by(string $kind): void
    {
        $id = $this->insert(['kind' => $kind, 'company' => 'Smart Technology']);

        self::assertSame('Smart Technology', DB::table('catalog_items')->where('id', $id)->value('company'));
    }

    // ──────────────────────────────────────────────────────────── DB-04 keys

    public function test_that_an_unknown_creator_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['created_by' => Uuid::uuid4()->toString()])),
        );
    }

    /**
     * `unit` and `service_type` are `enum_lists` codes with no foreign key, and
     * the reason is a property of `enum_lists` rather than a preference.
     *
     * Its uniqueness is a *partial* index and PostgreSQL refuses a key against
     * one — `SQLSTATE[42830]`, the same wall Module 3 Point 1.1 hit on
     * `customers.sector`. Both halves are pinned so that if that index ever
     * becomes total, the decision is revisited rather than inherited.
     */
    public function test_that_the_managed_list_references_are_unenforceable_and_therefore_unenforced(): void
    {
        $partial = DB::scalar(
            "select indexdef from pg_indexes where indexname = 'enum_lists_code_unique_alive'",
        );

        self::assertIsString($partial, 'enum_lists lost the index this decision was measured against.');
        self::assertStringContainsString('WHERE (deleted_at IS NULL)', $partial, 'The index is no longer partial.');

        foreach (['unit', 'service_type'] as $column) {
            $keys = DB::select(
                'select conname from pg_constraint c '
                .'join pg_attribute a on a.attrelid = c.conrelid and a.attnum = any(c.conkey) '
                ."where c.conrelid = 'catalog_items'::regclass and c.contype = 'f' and a.attname = ?",
                [$column],
            );

            self::assertSame([], $keys, "A foreign key on `{$column}` cannot exist — PostgreSQL refuses it (42830).");
        }
    }

    /** `DB-05` names both lists, so the columns must still be reading from them. */
    public function test_that_db_05_still_names_the_two_lists_these_columns_read(): void
    {
        $rule = self::documentationLineContaining('DB-05');

        foreach (['units', 'service types'] as $list) {
            self::assertStringContainsString($list, $rule, "DB-05 no longer names {$list}.");
        }
    }

    // ─────────────────────────────────────────────────────────────── flags

    public function test_that_a_new_item_is_active(): void
    {
        $row = DB::table('catalog_items')->where('id', $this->insert([]))->first();

        self::assertNotNull($row);
        self::assertTrue((bool) $row->is_active, '§7.3 records an active product/service; a new one is active.');
    }

    // ────────────────────────────────────────────────────────────── DEV-03

    /**
     * `migrate:reset`, not `migrate:rollback --path`: `--path` does not choose
     * which migrations roll back. `Migrator::rollback()` takes the whole last
     * batch from the repository and uses the path only to resolve each entry to
     * a file, skipping what it cannot resolve ("Migration not found") — so under
     * `RefreshDatabase`, where the schema is a single batch, a one-file path
     * means "run this down() while every later table still stands", and any new
     * child foreign key turns this red (`SQLSTATE[2BP01]`) without this down()
     * having changed. Module 6 Point 1.2's `supplier_quotation_items` is that
     * child — it is what reddened this test, and this test's subject did not
     * change. Same shelf life, same fix, as the four converted in Point 1.1.
     */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);

        self::assertFalse(Schema::hasTable('catalog_items'), 'down() left `catalog_items` behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasTable('catalog_items'), '`catalog_items` did not come back.');
    }

    // ───────────────────────────────────────────────────────────── helpers

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('catalog_items')->insert(array_merge([
            'id' => $id,
            'kind' => 'product',
            'name' => 'Test Item',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function refusedWith(callable $write): string
    {
        try {
            $write();
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        self::fail('The database accepted a write it had to refuse.');
    }

    private static function documentation(): string
    {
        self::assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted. This fails rather than skips: without it '
            .'these checks would only be agreeing with the migration that wrote them.',
        );

        return (string) file_get_contents(self::MASTER_DOCUMENTATION);
    }

    private static function documentationLineContaining(string $needle): string
    {
        foreach (explode("\n", self::documentation()) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        self::fail("The master documentation no longer states {$needle}.");
    }

    private static function documentationSlice(string $from, string $to): string
    {
        $body = self::documentation();
        $start = strpos($body, $from);

        self::assertIsInt($start, "`{$from}` is no longer in the master documentation.");

        $end = strpos($body, $to, $start);
        self::assertIsInt($end, "`{$to}` no longer follows `{$from}` — the slice would run to the end of the file.");

        return substr($body, $start, $end - $start);
    }

    /**
     * Both columns of §7.3's `| Product | Service |` table.
     *
     * Unlike §4.2 and §7.1, *both* cells carry a label here — the table is a
     * side-by-side comparison of two tabs, not a field-and-note pair.
     *
     * @return list<string>
     */
    private static function documentedCatalogLabels(): array
    {
        $labels = [];

        foreach (explode("\n", self::documentationSlice('### 7.3 Catalog', '## 8.')) as $line) {
            $line = trim($line);

            if (! str_starts_with($line, '|')) {
                continue;
            }

            foreach ([1, 2] as $position) {
                $cell = trim(explode('|', $line)[$position] ?? '');

                // The header row names the two tabs; its rule carries nothing.
                if ($cell === '' || $cell === 'Product' || $cell === 'Service' || str_starts_with($cell, '---')) {
                    continue;
                }

                $labels[] = $cell;
            }
        }

        return $labels;
    }
}
