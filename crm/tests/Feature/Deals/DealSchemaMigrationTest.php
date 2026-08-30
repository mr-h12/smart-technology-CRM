<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 5, Point 1.1 — the `deals` table.
 *
 * §4.3's field list is read out of the documentation the same way Module 3
 * Point 1.1 read §4.2 — a column dropped from both the migration and a
 * hand-written list here would pass a check that only agrees with itself.
 *
 * See the migration's own docblock for the reasoning this file pins: why
 * nothing in §4.3 is "Required" except `customer_id` (forced by §4.1's entity
 * map, not by an annotation), why `approval_status` is nullable with NULL
 * meaning "not applicable" rather than a fourth named value, why
 * `rejection_reason` is mandatory only for `approval_status = 'rejected'` and
 * not for the separately-documented `Lost` status, and why closing the
 * `deal_files.deal_id → deals` debt belongs to this point.
 */
final class DealSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const MIGRATION = 'database/migrations/2026_08_31_000000_create_deals.php';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    private const UNIQUE_VIOLATION = '23505';

    /** §4.4's states, as the column stores them. */
    private const STATUSES = [
        'lead', 'contacted', 'waiting_customer_request', 'supplier_rfq',
        'supplier_quotation', 'quotation_sent', 'negotiations', 'won',
        'purchasing', 'delivery', 'delivery_complete', 'lost',
    ];

    /** §4.3's three documented sources. */
    private const SOURCES = ['outlook_whatsapp', 'outdoor_visit', 'employee_entry'];

    /** §4.3's two-way split of the request itself. */
    private const SERVICE_TYPES = ['product', 'service'];

    /** §4.3's three named approval outcomes. */
    private const APPROVAL_STATUSES = ['pending', 'approved', 'rejected'];

    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerId = Uuid::uuid4()->toString();

        DB::table('customers')->insert(array_merge(self::customerAttributes(), [
            'id' => $this->customerId,
        ]));
    }

    /** @return list<array{string}> */
    public static function statuses(): array
    {
        return array_map(static fn (string $s): array => [$s], self::STATUSES);
    }

    /** @return list<array{string}> */
    public static function sources(): array
    {
        return array_map(static fn (string $s): array => [$s], self::SOURCES);
    }

    /** @return list<array{string}> */
    public static function serviceTypes(): array
    {
        return array_map(static fn (string $s): array => [$s], self::SERVICE_TYPES);
    }

    /** @return list<array{string}> */
    public static function approvalStatuses(): array
    {
        return array_map(static fn (string $s): array => [$s], self::APPROVAL_STATUSES);
    }

    // ─────────────────────────────────────────────────────────────── the block

    public function test_that_the_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('deals'), 'Module 5 needs `deals`.');
    }

    public function test_that_the_table_carries_the_block_section_4_8_requires(): void
    {
        $rule = self::documentationLineContaining('DB-02');

        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            self::assertStringContainsString($column, $rule, "§4.8's DB-02 no longer names {$column}.");
            self::assertTrue(Schema::hasColumn('deals', $column), "`deals` is missing {$column} (DB-02).");
        }

        self::assertTrue(Schema::hasColumn('deals', 'deleted_at'), '`deals` is missing the DB-01 soft delete.');
    }

    /** `DB-07`. No money on this table, but the rule is about the table, not the module. */
    public function test_that_no_column_stores_a_float(): void
    {
        self::assertTrue(Schema::hasTable('deals'), '`deals` does not exist, so this check proves nothing.');

        $floats = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            ['deals', 'double precision', 'real'],
        );

        self::assertSame([], $floats, '`deals` has a float column — DB-07 forbids it.');
    }

    // ──────────────────────────────────────────────────────────── §4.3's fields

    public function test_that_every_field_section_4_3_publishes_has_a_column(): void
    {
        $fields = self::documentedDealFields();

        self::assertGreaterThanOrEqual(
            12,
            count($fields),
            '§4.3 published fewer fields than expected — the parser is reading the wrong table.',
        );

        foreach ($fields as $field) {
            self::assertTrue(
                Schema::hasColumn('deals', $field),
                "§4.3 publishes `{$field}` and `deals` has no such column.",
            );
        }
    }

    // ──────────────────────────────────────────────────── `code`, DL-2026-0001

    public function test_that_a_deal_without_a_code_is_refused(): void
    {
        self::assertSame(self::NOT_NULL_VIOLATION, $this->refusedWith(fn () => $this->insert(['code' => null])));
    }

    public function test_that_two_deals_cannot_share_a_code(): void
    {
        $this->insert(['code' => 'DL-2026-0001']);

        self::assertSame(
            self::UNIQUE_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['code' => 'DL-2026-0001'])),
        );
    }

    // ──────────────────────────────────────────────────────────── `customer_id`

    /** §4.1's entity map, not a "Required" annotation §4.3 does not carry. */
    public function test_that_a_deal_without_a_customer_is_refused(): void
    {
        self::assertSame(self::NOT_NULL_VIOLATION, $this->refusedWith(fn () => $this->insert(['customer_id' => null])));
    }

    public function test_that_an_unknown_customer_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['customer_id' => Uuid::uuid4()->toString()])),
        );
    }

    // ───────────────────────────────────────────── fields §4.3 names nothing on

    public function test_that_title_source_service_type_and_owner_may_all_be_absent(): void
    {
        $id = $this->insert([
            'title' => null,
            'source' => null,
            'service_type' => null,
            'owner_id' => null,
        ]);

        $row = DB::table('deals')->where('id', $id)->first();

        self::assertNotNull($row);
        self::assertNull($row->title);
        self::assertNull($row->source);
        self::assertNull($row->service_type);
        self::assertNull($row->owner_id);
    }

    // ───────────────────────────────────────────────────────────────── `status`

    /** Flow 1 step 2: "Team Leader … enters the deal" → Lead. */
    public function test_that_a_new_deal_starts_at_lead(): void
    {
        $id = $this->insert([]);

        self::assertSame('lead', DB::table('deals')->where('id', $id)->value('status'));
    }

    #[DataProvider('statuses')]
    public function test_that_each_status_section_4_4_draws_is_accepted(string $status): void
    {
        $id = $this->insert(['status' => $status]);

        self::assertSame($status, DB::table('deals')->where('id', $id)->value('status'));
    }

    public function test_that_a_status_section_4_4_does_not_draw_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->insert(['status' => 'archived'])));
    }

    /** The documentation still names the terminal states this column stores. */
    public function test_that_section_4_4_still_names_won_and_lost(): void
    {
        $body = self::documentation();

        foreach (['Won', 'Lost'] as $label) {
            self::assertStringContainsString($label, $body, "§4.4 no longer names the `{$label}` status.");
        }
    }

    // ───────────────────────────────────────────────────────────────── `source`

    #[DataProvider('sources')]
    public function test_that_each_documented_source_is_accepted(string $source): void
    {
        $id = $this->insert(['source' => $source]);

        self::assertSame($source, DB::table('deals')->where('id', $id)->value('source'));
    }

    public function test_that_an_undocumented_source_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->insert(['source' => 'referral'])));
    }

    // ────────────────────────────────────────────────────────── `service_type`

    #[DataProvider('serviceTypes')]
    public function test_that_each_documented_service_type_is_accepted(string $type): void
    {
        $id = $this->insert(['service_type' => $type]);

        self::assertSame($type, DB::table('deals')->where('id', $id)->value('service_type'));
    }

    public function test_that_an_undocumented_service_type_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['service_type' => 'both'])),
        );
    }

    // ───────────────────────────────────────────────────────── `approval_status`

    public function test_that_a_deal_with_no_approval_status_is_accepted(): void
    {
        $id = $this->insert(['approval_status' => null]);

        self::assertNull(DB::table('deals')->where('id', $id)->value('approval_status'));
    }

    #[DataProvider('approvalStatuses')]
    public function test_that_each_documented_approval_status_is_accepted(string $status): void
    {
        // `rejected` carries its own mandatory-reason CHECK — see the
        // dedicated tests below — so this one supplies a reason unconditionally
        // rather than special-casing the one status that needs it.
        $id = $this->insert([
            'approval_status' => $status,
            'rejection_reason' => $status === 'rejected' ? 'Customer withdrew the request.' : null,
        ]);

        self::assertSame($status, DB::table('deals')->where('id', $id)->value('approval_status'));
    }

    public function test_that_an_undocumented_approval_status_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['approval_status' => 'escalated'])),
        );
    }

    // ──────────────────────────────────────────────────────── `rejection_reason`

    /** §4.3: "Mandatory on rejection" — tied to `approval_status = 'rejected'`. */
    public function test_that_a_rejection_without_a_reason_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['approval_status' => 'rejected', 'rejection_reason' => null])),
        );
    }

    public function test_that_a_blank_rejection_reason_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['approval_status' => 'rejected', 'rejection_reason' => '   '])),
        );
    }

    public function test_that_a_rejection_with_a_reason_is_accepted(): void
    {
        $id = $this->insert(['approval_status' => 'rejected', 'rejection_reason' => 'Customer withdrew the request.']);

        self::assertSame('rejected', DB::table('deals')->where('id', $id)->value('approval_status'));
    }

    public function test_that_a_pending_deal_needs_no_rejection_reason(): void
    {
        $id = $this->insert(['approval_status' => 'pending', 'rejection_reason' => null]);

        self::assertSame('pending', DB::table('deals')->where('id', $id)->value('approval_status'));
    }

    // ─────────────────────────────────────────────────────────── `last_activity_at`

    /** §4.3: drives `J-03`, and a deal is never without one — see the migration docblock. */
    public function test_that_a_deal_without_last_activity_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['last_activity_at' => null])),
        );
    }

    // ──────────────────────────────────────────────────────────────── DB-04 keys

    public function test_that_an_unknown_owner_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['owner_id' => Uuid::uuid4()->toString()])),
        );
    }

    public function test_that_an_unknown_creator_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['created_by' => Uuid::uuid4()->toString()])),
        );
    }

    // ─────────────────────────────────────────────────────────────── DB-09 indexes

    public function test_that_the_customer_column_is_indexed(): void
    {
        self::assertIndexCovers('deals', 'customer_id');
    }

    public function test_that_the_owner_column_is_indexed(): void
    {
        self::assertIndexCovers('deals', 'owner_id');
    }

    public function test_that_the_status_column_is_indexed(): void
    {
        self::assertIndexCovers('deals', 'status');
    }

    public function test_that_the_last_activity_column_is_indexed(): void
    {
        self::assertIndexCovers('deals', 'last_activity_at');
    }

    // ──────────────────────────────────────────────── `deal_files.deal_id → deals`

    /**
     * The debt `2026_08_22_000000_create_files_and_attachment_pivots` recorded,
     * closed in this migration. `FilesMigrationTest` asserts the debt register
     * itself; this asserts the constraint it describes actually refuses.
     *
     * The `file_id` here is real, deliberately — `deal_files.file_id` carries
     * its own foreign key onto `files`, and a random one there would raise the
     * same `23503` for a different reason. A random `file_id` alongside a
     * random `deal_id` cannot tell the two constraints apart, which is exactly
     * the gap a deliberate break found: removing the `deal_id` key here left
     * this test passing, because the `file_id` key alone still refused the
     * insert.
     */
    public function test_that_deal_files_refuses_a_deal_that_does_not_exist(): void
    {
        $fileId = self::insertFile();

        try {
            DB::table('deal_files')->insert([
                'deal_id' => Uuid::uuid4()->toString(),
                'file_id' => $fileId,
            ]);
        } catch (QueryException $e) {
            self::assertSame(self::FOREIGN_KEY_VIOLATION, $e->getCode());

            return;
        }

        self::fail('deal_files accepted a deal_id that references nothing.');
    }

    /** @return string the id of a real, insertable `files` row. */
    private static function insertFile(): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('files')->insert([
            'id' => $id,
            'original_name' => 'quotation.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_path' => '2026/08/deals/'.$id.'/'.$id.'.pdf',
            'scan_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertFalse(Schema::hasTable('deals'), 'down() left `deals` behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasTable('deals'), '`deals` did not come back.');
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert(array_merge([
            'id' => $id,
            'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -4),
            'customer_id' => $this->customerId,
            'last_activity_at' => now(),
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

    private static function assertIndexCovers(string $table, string $column): void
    {
        $covered = DB::scalar(
            'select count(*)::int from pg_indexes '
            .'where tablename = ? and indexdef like ?',
            [$table, "%{$column}%"],
        );

        self::assertIsInt($covered);
        self::assertGreaterThan(0, $covered, "DB-09 requires an index on `{$column}`.");
    }

    /** @return array<string, mixed> */
    private static function customerAttributes(): array
    {
        return [
            'name' => 'Test Customer for Deals',
            'created_at' => now(),
            'updated_at' => now(),
        ];
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
     * §4.3's first column, read out of the document.
     *
     * The section is a Markdown table of `| Field | Notes |`, and a field cell
     * may name several columns separated by `·` — "created_by · created_at" is
     * one row and two columns.
     *
     * @return list<string>
     */
    private static function documentedDealFields(): array
    {
        $body = self::documentation();
        $start = strpos($body, '### 4.3 Deals');

        self::assertIsInt($start, '§4.3 is no longer a heading in the master documentation.');

        $end = strpos($body, '### 4.4', $start);
        self::assertIsInt($end, '§4.4 no longer follows §4.3 — the slice would run to the end of the file.');

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
