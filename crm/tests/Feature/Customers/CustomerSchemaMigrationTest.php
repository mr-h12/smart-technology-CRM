<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 3, Point 1.1 — the `customers` table.
 *
 * ── The field list is read out of the documentation, not out of memory ─────
 *
 * §4.2 publishes the fields, and this file parses that table rather than
 * restating it. A column dropped from the migration and from a hand-written
 * list here would pass a test that only agrees with itself.
 *
 * ── `customer_status` is a code-level set, and `DB-05` says so ─────────────
 *
 * `DB-05` requires enum **tables** for four named lists — "sectors · units ·
 * service types · delivery terms" — and customer status is not among them.
 * §4.5 makes it derived by a rule with five ordered conditions, so a fifth
 * status is a change to that rule and therefore a migration, not a row someone
 * adds in settings. The database enforces the closed set with a CHECK.
 *
 * ── `sector` carries no foreign key, and that was measured ─────────────────
 *
 * `enum_lists`'s uniqueness is a **partial** index (`WHERE deleted_at IS NULL`,
 * Module 2 Point 1.3), and PostgreSQL refuses a foreign key against one:
 * `SQLSTATE[42830] there is no unique constraint matching given keys`. Probed
 * against the running database before this table was written, not assumed.
 * `DB-04` is therefore satisfied where a key is possible — `sales_owner_id`
 * and the two actor columns — and `sector` is validated at the boundary
 * instead. The test below pins both halves so the gap cannot quietly widen.
 */
final class CustomerSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const MIGRATION = 'database/migrations/2026_08_29_000000_create_customers.php';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    /** §4.5's four outcomes, as the column stores them. */
    private const STATUSES = ['prospect', 'customer', 'no_response', 'deal_not_completed'];

    /** @return list<array{string}> */
    public static function statuses(): array
    {
        return array_map(static fn (string $s): array => [$s], self::STATUSES);
    }

    // ─────────────────────────────────────────────────────────── the block

    public function test_that_the_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('customers'), 'Module 3 needs `customers`.');
    }

    public function test_that_the_table_carries_the_block_section_4_8_requires(): void
    {
        $rule = self::documentationLineContaining('DB-02');

        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            self::assertStringContainsString($column, $rule, "§4.8's DB-02 no longer names {$column}.");
            self::assertTrue(Schema::hasColumn('customers', $column), "`customers` is missing {$column} (DB-02).");
        }

        self::assertTrue(Schema::hasColumn('customers', 'deleted_at'), '`customers` is missing the DB-01 soft delete.');
    }

    /** `DB-07`. No money here yet, but the rule is about the table, not the module. */
    public function test_that_no_column_stores_a_float(): void
    {
        self::assertTrue(Schema::hasTable('customers'), '`customers` does not exist, so this check proves nothing.');

        $floats = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            ['customers', 'double precision', 'real'],
        );

        self::assertSame([], $floats, '`customers` has a float column — DB-07 forbids it.');
    }

    // ────────────────────────────────────────────────────────── §4.2's fields

    /**
     * Every field §4.2 publishes has a column.
     *
     * §4.2 names `added_by`, and the column is `created_by`: `DB-02` mandates
     * that name for "who created this row", and two columns meaning the same
     * thing is the defect, not the reconciliation. The map below is that one
     * translation and nothing else.
     */
    public function test_that_every_field_section_4_2_publishes_has_a_column(): void
    {
        $fields = self::documentedCustomerFields();

        self::assertGreaterThanOrEqual(
            14,
            count($fields),
            '§4.2 published fewer fields than expected — the parser is reading the wrong table.',
        );

        foreach ($fields as $field) {
            $column = $field === 'added_by' ? 'created_by' : $field;

            self::assertTrue(
                Schema::hasColumn('customers', $column),
                "§4.2 publishes `{$field}` and `customers` has no `{$column}`.",
            );
        }
    }

    /** §4.2: "name | Required". */
    public function test_that_a_customer_without_a_name_is_refused(): void
    {
        self::assertSame(self::NOT_NULL_VIOLATION, $this->refusedWith(fn () => $this->insert(['name' => null])));
    }

    /** NOT NULL alone lets a space through, and §4.2 says the name is required. */
    public function test_that_a_blank_name_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->insert(['name' => '   '])));
    }

    // ─────────────────────────────────────────────────── §4.5 customer status

    #[DataProvider('statuses')]
    public function test_that_each_status_section_4_5_derives_is_accepted(string $status): void
    {
        $id = $this->insert(['customer_status' => $status]);

        self::assertSame($status, DB::table('customers')->where('id', $id)->value('customer_status'));
    }

    public function test_that_a_status_section_4_5_does_not_derive_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['customer_status' => 'vip'])),
        );
    }

    /** §4.5 rule 5: "Registered with no deals at all → Prospect". */
    public function test_that_a_customer_with_no_deals_starts_as_a_prospect(): void
    {
        $id = $this->insert([]);

        self::assertSame('prospect', DB::table('customers')->where('id', $id)->value('customer_status'));
    }

    /** The documentation still names the four this column stores. */
    public function test_that_section_4_5_still_names_the_four_derived_statuses(): void
    {
        $body = self::documentation();

        foreach (['Prospect', 'No Response', 'Deal Not Completed'] as $label) {
            self::assertStringContainsString($label, $body, "§4.5 no longer names the `{$label}` status.");
        }
    }

    // ──────────────────────────────────────────────────────────── DB-04 keys

    public function test_that_an_unknown_sales_owner_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['sales_owner_id' => Uuid::uuid4()->toString()])),
        );
    }

    public function test_that_an_unknown_creator_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['created_by' => Uuid::uuid4()->toString()])),
        );
    }

    /**
     * `sector` has no key, and the reason is a property of `enum_lists`.
     *
     * Both halves are asserted: that the referenced uniqueness really is
     * partial, and that no key was written anyway. If Module 2's index ever
     * becomes total, the first assertion fails and this decision is revisited
     * rather than inherited.
     */
    public function test_that_the_sector_reference_is_unenforceable_and_therefore_unenforced(): void
    {
        $partial = DB::scalar(
            "select indexdef from pg_indexes where indexname = 'enum_lists_code_unique_alive'",
        );

        self::assertIsString($partial, 'enum_lists lost the index this decision was measured against.');
        self::assertStringContainsString('WHERE (deleted_at IS NULL)', $partial, 'The index is no longer partial.');

        $keys = DB::select(
            'select conname from pg_constraint c '
            .'join pg_attribute a on a.attrelid = c.conrelid and a.attnum = any(c.conkey) '
            ."where c.conrelid = 'customers'::regclass and c.contype = 'f' and a.attname = 'sector'",
        );

        self::assertSame([], $keys, 'A foreign key on `sector` cannot exist — PostgreSQL refuses it (42830).');
    }

    // ───────────────────────────────────────────────────────────── DB-09 index

    /** `DB-09`: "Indexes on: customer · owner · …" — every scoped read filters by owner. */
    public function test_that_the_owner_column_is_indexed(): void
    {
        // Counted in SQL rather than read off a row object: `DB::select`
        // returns untyped objects, and narrowing one with a cast would be the
        // escape hatch the standards forbid.
        $covered = DB::scalar(
            'select count(*)::int from pg_indexes '
            ."where tablename = 'customers' and indexdef like '%sales_owner_id%'",
        );

        self::assertIsInt($covered);
        self::assertGreaterThan(0, $covered, 'DB-09 requires an index on the owner column.');
    }

    // ─────────────────────────────────────────────────────────────── flags

    public function test_that_a_new_customer_is_neither_archived_nor_incomplete(): void
    {
        $row = DB::table('customers')->where('id', $this->insert([]))->first();

        self::assertNotNull($row);
        self::assertFalse((bool) $row->is_archived, 'A new customer must not start archived (Flow 7).');
        self::assertFalse((bool) $row->is_incomplete, 'A new customer must not start incomplete (D-31).');
    }

    // ────────────────────────────────────────────────────────────── DEV-03

    /**
     * `deals.customer_id` (Module 5 Point 1.1) is a real foreign key onto this
     * table, so `customers` can no longer roll back in isolation — PostgreSQL
     * refuses to drop a table a live constraint still references
     * (`SQLSTATE[2BP01]`). This is `UserSchemaMigrationTest`'s situation on
     * `users`, not a defect here: the dependent migration rolls back first,
     * in the reverse of the order it was applied, and forward again in that
     * same reversed order once `customers` is back.
     */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        $dealsMigration = 'database/migrations/2026_08_31_000000_create_deals.php';

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => $dealsMigration]));
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertFalse(Schema::hasTable('customers'), 'down() left `customers` behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasTable('customers'), '`customers` did not come back.');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('deals'), '`deals` did not come back.');
    }

    // ───────────────────────────────────────────────────────────── helpers

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert(array_merge([
            'id' => $id,
            'name' => 'Test Customer',
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

    /**
     * §4.2's first column, read out of the document.
     *
     * The section is a Markdown table of `| Field | Notes |`, and a field cell
     * may name several columns separated by `·` — "phone · phone2 · whatsapp ·
     * email" is one row and four columns.
     *
     * @return list<string>
     */
    private static function documentedCustomerFields(): array
    {
        $body = self::documentation();
        $start = strpos($body, '### 4.2 Customers');

        self::assertIsInt($start, '§4.2 is no longer a heading in the master documentation.');

        $end = strpos($body, '### 4.3', $start);
        self::assertIsInt($end, '§4.3 no longer follows §4.2 — the slice would run to the end of the file.');

        $fields = [];

        foreach (explode("\n", substr($body, $start, $end - $start)) as $line) {
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
