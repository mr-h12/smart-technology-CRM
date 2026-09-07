<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 1.1 — the `quotations` table.
 *
 * The nine statuses are read out of §6.1 in the master documentation rather
 * than retyped here, on `SupplierQuotationSchemaMigrationTest`'s precedent: a
 * status dropped from both the migration and a hand-written list would pass a
 * check that only agrees with itself. §6.1's table is one row per status, so
 * the parse is exact rather than a guess at prose.
 *
 * The rows this test writes are deliberately **§5.2-consistent** — the totals
 * satisfy the four additive identities that Point 1.2 will add as CHECKs, so
 * that point cannot retroactively turn these tests red.
 *
 * See the migration's docblock for why `status` is a constrained column rather
 * than an `enum_lists` row (§14 Q-6), why "Returned" is not a tenth status
 * (§6.3 vs §6.4), and why `version` and `version_token` are two counters.
 */
final class QuotationSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    private const UNIQUE_VIOLATION = '23505';

    private string $customerId;

    private string $dealId;

    private string $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerId = Uuid::uuid4()->toString();
        $this->dealId = Uuid::uuid4()->toString();
        $this->currencyId = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $this->customerId,
            'name' => 'Nile Trading',
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

        DB::table('deals')->insert([
            'id' => $this->dealId,
            'code' => 'DL-2026-0001',
            'customer_id' => $this->customerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────── the block

    public function test_that_the_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('quotations'));
    }

    public function test_that_the_table_carries_the_block_section_4_8_requires(): void
    {
        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            self::assertTrue(
                Schema::hasColumn('quotations', $column),
                "`quotations` is missing {$column} (DB-02).",
            );
        }

        self::assertTrue(
            Schema::hasColumn('quotations', 'deleted_at'),
            '`quotations` is missing the DB-01 soft delete.',
        );
    }

    /** `DB-07`. This is the table the rule was written for. */
    public function test_that_no_column_stores_a_float(): void
    {
        self::assertTrue(Schema::hasTable('quotations'), 'no table, so this check proves nothing.');

        $floats = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            ['quotations', 'double precision', 'real'],
        );

        self::assertSame([], $floats, '`quotations` has a float column — DB-07 forbids it.');
    }

    /** `D-68`: money is `NUMERIC(18,6)`, so a seventh decimal is quantized, never kept as a float. */
    public function test_that_money_is_stored_at_the_precision_d_68_approved(): void
    {
        $this->insert([
            'subtotal' => '1234.5678901',
            'tax_base' => '1234.567890',
            'net_amount' => '1234.567890',
            'total_before_round' => '1234.567890',
            'final_total' => '1234.567890',
        ]);

        self::assertSame(
            '1234.567890',
            $this->storedValue('subtotal'),
            'money is not NUMERIC(18,6) — D-68 fixed the precision.',
        );
    }

    // ────────────────────────────────────────────────────────── §6.1's statuses

    public function test_that_every_status_section_6_1_lists_is_accepted(): void
    {
        $documented = self::documentedStatuses();

        self::assertCount(9, $documented, '§6.1 is headed "Statuses (9)" and no longer lists nine.');

        foreach ($documented as $status) {
            $this->insert(['status' => $status, 'rejection_reason' => 'documented reason']);
        }

        self::assertSame(9, DB::table('quotations')->count());
    }

    /** §6.3 names "Returned"; §6.4 resolves it to `Draft (v2)`. It is not a tenth status. */
    public function test_that_returned_is_not_a_status(): void
    {
        self::assertNotContains('returned', self::documentedStatuses());

        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['status' => 'returned'])),
        );
    }

    public function test_that_an_unknown_status_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['status' => 'archived'])),
        );
    }

    // ───────────────────────────────────────────────────────── `D-63`'s no-tax

    /** `D-63`: an exempt quotation renders no tax line at all — a zero would render one. */
    public function test_that_a_zero_tax_percent_cannot_be_stored(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['tax_percent' => '0'])),
        );
    }

    public function test_that_a_null_tax_percent_is_accepted(): void
    {
        $this->insert(['tax_percent' => null]);

        self::assertNull(DB::table('quotations')->value('tax_percent'));
    }

    public function test_that_a_positive_tax_percent_is_accepted(): void
    {
        $this->insert(['tax_percent' => '14', 'tax_amount' => '0']);

        self::assertSame('14.000', $this->storedValue('tax_percent'));
    }

    // ──────────────────────────────────────────────────────────── §10's ranges

    public function test_that_a_full_discount_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['discount_percent' => '100'])),
        );
    }

    public function test_that_a_negative_discount_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['discount_percent' => '-1'])),
        );
    }

    public function test_that_a_zero_discount_is_accepted(): void
    {
        $this->insert(['discount_percent' => '0']);

        self::assertSame('0.000', $this->storedValue('discount_percent'));
    }

    /** §5.3 · `D-65`: the unit is captured onto the row, and a captured copy is still a real unit. */
    public function test_that_a_zero_rounding_unit_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['rounding_unit' => '0'])),
        );
    }

    // ─────────────────────────────────────── `DB-03` and `DB-12`'s two counters

    public function test_that_version_and_version_token_are_separate_columns(): void
    {
        self::assertTrue(Schema::hasColumn('quotations', 'version'), 'DB-03 needs the document version.');
        self::assertTrue(
            Schema::hasColumn('quotations', 'version_token'),
            'DB-12 / OpenAPI §9.2 need the optimistic lock, and it is not the document version.',
        );
    }

    public function test_that_a_version_below_one_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['version' => 0])),
        );
    }

    public function test_that_a_version_token_below_one_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['version_token' => 0])),
        );
    }

    /** §10: "UNIQUE (parent_id, version) — DB-03: one v2 per parent". */
    public function test_that_one_parent_cannot_have_two_of_the_same_version(): void
    {
        $parent = $this->insert();

        $this->insert(['parent_id' => $parent, 'version' => 2]);

        self::assertSame(
            self::UNIQUE_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['parent_id' => $parent, 'version' => 2])),
        );
    }

    /** `DB-01` soft-deletes, so the index is partial — an archived version must not hold its number forever. */
    public function test_that_an_archived_version_releases_its_number(): void
    {
        $parent = $this->insert();
        $second = $this->insert(['parent_id' => $parent, 'version' => 2]);

        DB::table('quotations')->where('id', $second)->update(['deleted_at' => now()]);

        $this->insert(['parent_id' => $parent, 'version' => 2]);

        self::assertSame(2, DB::table('quotations')->where('parent_id', $parent)->count());
    }

    /** Root quotations all have a NULL parent, and PostgreSQL keeps those distinct. */
    public function test_that_many_root_quotations_may_share_version_one(): void
    {
        $this->insert();
        $this->insert();

        self::assertSame(2, DB::table('quotations')->whereNull('parent_id')->count());
    }

    // ──────────────────────────────────────────────────────────────── §6.3

    public function test_that_a_rejected_quotation_needs_a_reason(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['status' => 'rejected', 'rejection_reason' => null])),
        );
    }

    public function test_that_a_counter_quotation_needs_a_reason(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['status' => 'counter', 'rejection_reason' => null])),
        );
    }

    public function test_that_a_blank_reason_is_not_a_reason(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['status' => 'rejected', 'rejection_reason' => '   '])),
        );
    }

    // ────────────────────────────────────────────────────── the required links

    public function test_that_a_quotation_without_a_deal_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['deal_id' => null])),
        );
    }

    public function test_that_a_quotation_without_a_customer_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['customer_id' => null])),
        );
    }

    /** `D-09`: a quotation uses one currency, so it cannot use none. */
    public function test_that_a_quotation_without_a_currency_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['currency_id' => null])),
        );
    }

    /** `D-03`: a line margin of NULL inherits this one, so the chain has to terminate. */
    public function test_that_a_quotation_without_a_default_margin_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['default_margin' => null])),
        );
    }

    public function test_that_an_unknown_deal_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['deal_id' => Uuid::uuid4()->toString()])),
        );
    }

    public function test_that_an_unknown_customer_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['customer_id' => Uuid::uuid4()->toString()])),
        );
    }

    public function test_that_an_unknown_currency_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['currency_id' => Uuid::uuid4()->toString()])),
        );
    }

    public function test_that_an_unknown_parent_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['parent_id' => Uuid::uuid4()->toString()])),
        );
    }

    // ─────────────────────────────────────────────────────────────────── §4.7

    public function test_that_a_quotation_without_a_code_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['code' => null])),
        );
    }

    public function test_that_two_quotations_cannot_share_a_code(): void
    {
        $this->insert(['code' => 'QT-2026-0001']);

        self::assertSame(
            self::UNIQUE_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['code' => 'QT-2026-0001'])),
        );
    }

    // ──────────────────────────────────────────────────── the validity window

    public function test_that_a_quotation_cannot_expire_before_it_is_written(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert([
                'quotation_date' => '2026-09-07',
                'valid_until' => '2026-09-06',
            ])),
        );
    }

    public function test_that_a_quotation_may_expire_on_the_day_it_is_written(): void
    {
        $this->insert(['quotation_date' => '2026-09-07', 'valid_until' => '2026-09-07']);

        self::assertSame(1, DB::table('quotations')->count());
    }

    // ─────────────────────────────────────────────────────────────── §13's indexes

    public function test_that_the_indexes_section_13_names_exist(): void
    {
        foreach (
            [
                'quotations_by_deal_version' => 'the version chain',
                'quotations_by_status_validity' => 'the approval queue and J-01',
                'quotations_version_unique_alive' => 'one v2 per parent, and the previous-quotations panel',
            ] as $index => $serves
        ) {
            self::assertNotSame(
                [],
                DB::select('select indexname from pg_indexes where tablename = ? and indexname = ?', [
                    'quotations',
                    $index,
                ]),
                "design §13 names {$serves} and `{$index}` is missing.",
            );
        }
    }

    /** `DB-01`: a list index that counts archived rows serves the wrong set. */
    public function test_that_the_list_indexes_exclude_archived_rows(): void
    {
        foreach (
            [
                'quotations_by_deal_version',
                'quotations_by_status_validity',
                'quotations_version_unique_alive',
            ] as $index
        ) {
            self::assertStringContainsString(
                'WHERE (deleted_at IS NULL)',
                self::indexDefinition($index),
                "`{$index}` counts archived rows — DB-01.",
            );
        }
    }

    /**
     * The code index is the deliberate exception, and it is asserted rather
     * than left to look like an oversight. §4.7 gives a document one number for
     * good: if the unique index were partial, archiving `QT-2026-0001` would
     * free that number for a second, different document, and two rows in the
     * audit history would answer to one code. `currencies.code` is partial for
     * the opposite reason — a retired currency *should* release `USD`.
     * `supplier_quotations` made the same call one module earlier.
     */
    public function test_that_an_archived_quotation_never_releases_its_code(): void
    {
        self::assertStringNotContainsString(
            'WHERE',
            self::indexDefinition('quotations_code_unique'),
            'the code index is partial, so an archived quotation frees its number (§4.7).',
        );

        $first = $this->insert(['code' => 'QT-2026-0001']);
        DB::table('quotations')->where('id', $first)->update(['deleted_at' => now()]);

        self::assertSame(
            self::UNIQUE_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['code' => 'QT-2026-0001'])),
        );
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);
        self::assertFalse(Schema::hasTable('quotations'), 'down() left `quotations` behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('quotations'), '`quotations` did not come back.');
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /**
     * A §5.2-consistent row: every total is zero, which satisfies all four of
     * Point 1.2's additive identities, so this point's tests survive that one.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insert(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('quotations')->insert(array_merge([
            'id' => $id,
            'code' => 'QT-2026-'.substr(str_replace('-', '', $id), -4),
            'deal_id' => $this->dealId,
            'customer_id' => $this->customerId,
            'currency_id' => $this->currencyId,
            'default_margin' => '20',
            'discount_percent' => '0',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'subtotal' => '0',
            'additional_total' => '0',
            'discount_amount' => '0',
            'tax_base' => '0',
            'net_amount' => '0',
            'total_before_round' => '0',
            'final_total' => '0',
            'rounding_diff' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    /** PostgreSQL returns `NUMERIC` as a string; this narrows it for strict analysis. */
    private function storedValue(string $column): string
    {
        $value = DB::table('quotations')->value($column);

        self::assertIsString($value, "`{$column}` did not come back as a numeric string.");

        return $value;
    }

    private static function indexDefinition(string $index): string
    {
        $definition = DB::table('pg_indexes')->where('indexname', $index)->value('indexdef');

        self::assertIsString($definition, "`{$index}` does not exist on `quotations`.");

        return $definition;
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

    /**
     * §6.1's status table, read from the master documentation. One row per
     * status, first cell is the name.
     *
     * @return list<string>
     */
    private static function documentedStatuses(): array
    {
        self::assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted. This fails rather than skips: without it '
            .'these checks would only be agreeing with the migration that wrote them.',
        );

        $body = (string) file_get_contents(self::MASTER_DOCUMENTATION);

        $start = strpos($body, '### 6.1 Statuses');
        self::assertIsInt($start, '§6.1 is no longer a heading in the master documentation.');

        $end = strpos($body, '### 6.2', $start);
        self::assertIsInt($end, '§6.2 no longer follows §6.1 — the slice would run to the end of the file.');

        $statuses = [];

        foreach (explode("\n", substr($body, $start, $end - $start)) as $line) {
            if (! str_starts_with(trim($line), '|')) {
                continue;
            }

            $cell = trim(explode('|', $line)[1] ?? '');

            if ($cell === '' || $cell === 'Status' || str_starts_with($cell, '--')) {
                continue;
            }

            $statuses[] = strtolower($cell);
        }

        return $statuses;
    }
}
