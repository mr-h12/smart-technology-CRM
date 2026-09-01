<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 4, Point 1.1 — the `suppliers` table.
 *
 * ── The field list is read out of the documentation, not out of memory ─────
 *
 * §7.1 publishes the fields, and this file parses that table rather than
 * restating it, exactly as Module 3 Point 1.1 does for §4.2. A column dropped
 * from the migration *and* from a hand-written list here would pass a test
 * that only agrees with itself.
 *
 * The parser stops at "**Colour meanings:**" on purpose: §7.1 carries a second
 * table below that marker whose first column is a colour, not a field, and a
 * slice running to §7.2 would read "🟢 Green" as a column name.
 *
 * ── `linked_quotations` is deliberately not a column ───────────────────────
 *
 * §7.1 annotates it "Automatic", and §4.1's entity map draws the arrow the
 * other way: `Supplier ──► Supplier Quotation`, with the key on the quotation.
 * The link is therefore derived by Module 6 from `supplier_quotations.supplier_id`,
 * and storing it here would be a denormalised copy that goes stale. The test
 * pins both halves — that the documentation still says "Automatic", and that
 * no column was written anyway — so if that word ever changes the decision is
 * revisited rather than inherited.
 *
 * ── `color_rating` and `type` are CHECKs, not enum tables ──────────────────
 *
 * `DB-05` requires enum **tables** for four named lists — "sectors · units ·
 * service types · delivery terms" — and neither of these is among them. §7.1
 * fixes the four colours by giving each a meaning, and adding a fifth is a
 * change to that meaning table, not a row an administrator adds in settings.
 *
 * ── `is_active` exists because §3.7 grants "deactivate" ────────────────────
 *
 * §3.7's write row is "create · edit · deactivate · set colour" and §3.12 rule
 * 3 forbids hard-deleting a supplier outright. §10.4 then describes the state
 * a deactivated supplier is in. That is a stored flag, distinct from `DB-01`'s
 * soft delete, which nothing in this system may set on a supplier.
 */
final class SupplierSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    /** §7.1's colour meanings table, as the column stores them. */
    private const COLOURS = ['green', 'yellow', 'red', 'white'];

    /** §7.1: "name · type | supplier / distributor". */
    private const TYPES = ['supplier', 'distributor'];

    /** @return list<array{string}> */
    public static function colours(): array
    {
        return array_map(static fn (string $c): array => [$c], self::COLOURS);
    }

    /** @return list<array{string}> */
    public static function types(): array
    {
        return array_map(static fn (string $t): array => [$t], self::TYPES);
    }

    // ─────────────────────────────────────────────────────────── the block

    public function test_that_the_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('suppliers'), 'Module 4 needs `suppliers`.');
    }

    public function test_that_the_table_carries_the_block_section_4_8_requires(): void
    {
        $rule = self::documentationLineContaining('DB-02');

        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            self::assertStringContainsString($column, $rule, "§4.8's DB-02 no longer names {$column}.");
            self::assertTrue(Schema::hasColumn('suppliers', $column), "`suppliers` is missing {$column} (DB-02).");
        }

        self::assertTrue(Schema::hasColumn('suppliers', 'deleted_at'), '`suppliers` is missing the DB-01 soft delete.');
    }

    /** `DB-07`. §7.1 records no amount — `D-21` keeps every price on the supplier quotation. */
    public function test_that_no_column_stores_a_float(): void
    {
        self::assertTrue(Schema::hasTable('suppliers'), '`suppliers` does not exist, so this check proves nothing.');

        $floats = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            ['suppliers', 'double precision', 'real'],
        );

        self::assertSame([], $floats, '`suppliers` has a float column — DB-07 forbids it.');
    }

    // ────────────────────────────────────────────────────────── §7.1's fields

    public function test_that_every_field_section_7_1_publishes_has_a_column(): void
    {
        $fields = self::documentedSupplierFields();

        self::assertGreaterThanOrEqual(
            6,
            count($fields),
            '§7.1 published fewer fields than expected — the parser is reading the wrong table.',
        );

        foreach ($fields as $field) {
            if ($field === 'linked_quotations') {
                continue;
            }

            self::assertTrue(
                Schema::hasColumn('suppliers', $field),
                "§7.1 publishes `{$field}` and `suppliers` has no such column.",
            );
        }
    }

    /**
     * The one field §7.1 publishes that is derived rather than stored.
     *
     * Both halves, so the exemption cannot outlive its reason: the document
     * still calls it automatic, and no column was written anyway.
     */
    public function test_that_the_link_to_quotations_is_derived_and_therefore_not_stored(): void
    {
        $row = self::documentationLineContaining('| linked_quotations');

        self::assertStringContainsString(
            'Automatic',
            $row,
            '§7.1 no longer calls `linked_quotations` automatic — it may now need storing.',
        );

        self::assertFalse(
            Schema::hasColumn('suppliers', 'linked_quotations'),
            'Module 6 derives this from `supplier_quotations.supplier_id`; a stored copy goes stale.',
        );
    }

    /** A supplier with no name cannot be selected on an offer. */
    public function test_that_a_supplier_without_a_name_is_refused(): void
    {
        self::assertSame(self::NOT_NULL_VIOLATION, $this->refusedWith(fn () => $this->insert(['name' => null])));
    }

    /** NOT NULL alone lets a space through. */
    public function test_that_a_blank_name_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->insert(['name' => '   '])));
    }

    // ─────────────────────────────────────────────────────── §7.1 the colours

    #[DataProvider('colours')]
    public function test_that_each_colour_section_7_1_gives_a_meaning_is_accepted(string $colour): void
    {
        $id = $this->insert(['color_rating' => $colour]);

        self::assertSame($colour, DB::table('suppliers')->where('id', $id)->value('color_rating'));
    }

    public function test_that_a_colour_section_7_1_does_not_define_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['color_rating' => 'blue'])),
        );
    }

    /** §7.1: "⚪ White | New / not yet rated". A supplier nobody has rated is white. */
    public function test_that_an_unrated_supplier_is_white(): void
    {
        $id = $this->insert([]);

        self::assertSame('white', DB::table('suppliers')->where('id', $id)->value('color_rating'));
    }

    /** The documentation still gives each stored colour a meaning. */
    public function test_that_section_7_1_still_defines_the_four_colours(): void
    {
        $meanings = self::documentationSlice('### 7.1 Suppliers', '### 7.2');

        foreach (['Green', 'Yellow', 'Red', 'White'] as $colour) {
            self::assertStringContainsString($colour, $meanings, "§7.1 no longer defines the `{$colour}` rating.");
        }
    }

    // ───────────────────────────────────────────────────────── §7.1 the type

    #[DataProvider('types')]
    public function test_that_each_type_section_7_1_names_is_accepted(string $type): void
    {
        $id = $this->insert(['type' => $type]);

        self::assertSame($type, DB::table('suppliers')->where('id', $id)->value('type'));
    }

    public function test_that_a_type_section_7_1_does_not_name_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['type' => 'wholesaler'])),
        );
    }

    /**
     * §7.1 marks nothing required, so the column is nullable and the choice is
     * the boundary's — the same reading Module 3 applied to every §4.2 field
     * but the name. Tightening this later is a Form Request; loosening a
     * NOT NULL after rows exist is a migration, so the reversible one is here.
     */
    public function test_that_a_supplier_may_be_saved_before_its_type_is_known(): void
    {
        $id = $this->insert(['type' => null]);

        self::assertNull(DB::table('suppliers')->where('id', $id)->value('type'));
    }

    // ──────────────────────────────────────────────────────────── DB-04 keys

    public function test_that_an_unknown_creator_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['created_by' => Uuid::uuid4()->toString()])),
        );
    }

    // ─────────────────────────────────────────────────────────────── flags

    /** §3.7 grants "deactivate"; a new supplier is not deactivated. */
    public function test_that_a_new_supplier_is_active_and_has_no_open_account(): void
    {
        $row = DB::table('suppliers')->where('id', $this->insert([]))->first();

        self::assertNotNull($row);
        self::assertTrue((bool) $row->is_active, 'A new supplier must start active (§3.7).');
        self::assertFalse((bool) $row->has_open_account, '§7.1 records an open account, and none is opened by default.');
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
     * having changed. Same shelf life, same fix, as `RbacSchemaMigrationTest`
     * and `AuditLogMigrationTest`. `DEV-03` asks that rollback work; rolling the
     * chain back and forward proves more of it, not less.
     */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);

        self::assertFalse(Schema::hasTable('suppliers'), 'down() left `suppliers` behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasTable('suppliers'), '`suppliers` did not come back.');
    }

    // ───────────────────────────────────────────────────────────── helpers

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert(array_merge([
            'id' => $id,
            'name' => 'Test Supplier',
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
     * §7.1's first column, read out of the document.
     *
     * The section is a Markdown table of `| Field | Notes |`, and a field cell
     * may name two columns separated by `·` — "name · type" is one row and two
     * columns. The slice stops at the colour table, whose first column is a
     * meaning rather than a field.
     *
     * @return list<string>
     */
    private static function documentedSupplierFields(): array
    {
        $fields = [];

        foreach (explode("\n", self::documentationSlice('### 7.1 Suppliers', '**Colour meanings:**')) as $line) {
            if (! str_starts_with(trim($line), '|')) {
                continue;
            }

            $cell = trim(explode('|', $line)[1] ?? '');

            // The header row and its `|---|---|` rule carry no field name.
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
