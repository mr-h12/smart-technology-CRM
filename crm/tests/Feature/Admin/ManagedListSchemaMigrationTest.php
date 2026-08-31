<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Domain\Reference\ManagedList;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 2, Point 1.3 — `enum_lists`.
 *
 * **`DB-05` in one table:** *"Enum tables, not hard-coded enums — sectors ·
 * units · service types · delivery terms"*. What the rule forbids is the
 * membership living in code, which is also the module's acceptance criterion:
 * a new sector must appear in the customer form **without a deployment**.
 *
 * The set of *lists* is fixed and the set of *entries* is not — `ManagedList`
 * explains why, and the column that names the list is CHECKed against those
 * four values. **The check below compares the migration's literals with
 * `ManagedList::cases()`**, which is the point: the migration cannot read the
 * enum and the enum cannot read the migration, so a drift between them fails
 * here rather than at the first screen that renders an unknown list.
 *
 * **Both labels are NOT NULL.** §14.2 requires Arabic and English from the
 * first release and `ListEntry` already takes both as non-nullable strings. The
 * `roles.name_ar` precedent — nullable, and null for all eight rows — is the
 * outcome this avoids: a nullable label is an English word on an Arabic screen.
 */
final class ManagedListSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'enum_lists';

    private const MIGRATION = 'database/migrations/2026_08_27_020000_create_enum_lists.php';

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const UNIQUE_VIOLATION = '23505';

    private const CHECK_VIOLATION = '23514';

    private const NOT_NULL_VIOLATION = '23502';

    /** @return list<array{string}> */
    public static function lists(): array
    {
        return array_map(static fn (ManagedList $l): array => [$l->value], ManagedList::cases());
    }

    // ─────────────────────────────────────────────────────────────── the block

    public function test_that_the_table_exists(): void
    {
        self::assertTrue(Schema::hasTable(self::TABLE), 'Module 2 needs `enum_lists` (DB-05).');
    }

    public function test_that_the_table_carries_the_block_section_4_8_requires(): void
    {
        self::assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted. This fails rather than skips: without it '
            .'the audit block below would only be agreeing with the migration that wrote it.',
        );

        $rule = null;
        foreach (file(self::MASTER_DOCUMENTATION) ?: [] as $line) {
            if (str_contains($line, 'DB-02')) {
                $rule = $line;
                break;
            }
        }

        self::assertNotNull($rule, '§4.8 no longer states DB-02.');

        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            self::assertStringContainsString($column, $rule, "§4.8's DB-02 no longer names {$column}.");
            self::assertTrue(Schema::hasColumn(self::TABLE, $column), "`enum_lists` is missing {$column} (DB-02).");
        }

        self::assertTrue(Schema::hasColumn(self::TABLE, 'deleted_at'), '`enum_lists` is missing the DB-01 soft delete.');
    }

    public function test_that_no_column_stores_a_float(): void
    {
        self::assertTrue(Schema::hasTable(self::TABLE), '`enum_lists` does not exist, so this check proves nothing.');

        $floats = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            [self::TABLE, 'double precision', 'real'],
        );

        self::assertSame([], $floats, '`enum_lists` has a float column — DB-07 forbids it.');
    }

    // ────────────────────────────────────────────────────── the four DB-05 lists

    /**
     * Every list the domain declares must be storable. The migration writes its
     * four literals; this reads `ManagedList::cases()`. Neither can see the other.
     */
    #[DataProvider('lists')]
    public function test_that_the_list_the_domain_declares_is_accepted(string $list): void
    {
        $this->insertEntry($list, 'first');

        self::assertSame(1, DB::table(self::TABLE)->where('list', $list)->count());
    }

    public function test_that_a_list_the_domain_does_not_declare_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->insertEntry('payment_terms', 'net30')));
    }

    public function test_that_the_documentation_still_names_these_four_lists(): void
    {
        self::assertFileExists(self::MASTER_DOCUMENTATION);

        $rule = null;
        foreach (file(self::MASTER_DOCUMENTATION) ?: [] as $line) {
            if (str_contains($line, 'DB-05')) {
                $rule = $line;
                break;
            }
        }

        self::assertNotNull($rule, '§4.8 no longer states DB-05.');

        foreach (['sectors', 'units', 'service types', 'delivery terms'] as $named) {
            self::assertStringContainsString($named, $rule, "DB-05 no longer names {$named}.");
        }

        // `DB-05`'s four must all still be cases — that is the drift this guard
        // was written to catch. What it may no longer assert is that there are
        // *only* four: `companies` is a fifth, added by the owner's ruling of
        // 2026-08-31 and recorded in `CHECKLIST.md` awaiting a `D-xx`. So the
        // assertion is narrowed to "the documented four are present, and every
        // extra one is a name written down here", rather than widened to a
        // bare count that would stop catching a removal.
        $cases = array_map(static fn (ManagedList $l): string => $l->value, ManagedList::cases());

        foreach (['sectors', 'units', 'service_types', 'delivery_terms'] as $documented) {
            self::assertContains($documented, $cases, "ManagedList no longer keeps DB-05's {$documented}.");
        }

        self::assertSame(
            ['companies'],
            array_values(array_diff($cases, ['sectors', 'units', 'service_types', 'delivery_terms'])),
            'A list outside DB-05 was added without being named here.',
        );
    }

    // ─────────────────────────────────────────────────────────── the constraints

    public function test_that_a_duplicate_live_code_in_one_list_is_refused(): void
    {
        $this->insertEntry('sectors', 'oil_and_gas');

        self::assertSame(
            self::UNIQUE_VIOLATION,
            $this->refusedWith(fn () => $this->insertEntry('sectors', 'oil_and_gas')),
        );
    }

    /** The same word means different things in different lists. */
    public function test_that_the_same_code_may_appear_in_another_list(): void
    {
        $this->insertEntry('sectors', 'other');
        $this->insertEntry('units', 'other');

        self::assertSame(2, DB::table(self::TABLE)->where('code', 'other')->count());
    }

    public function test_that_an_archived_code_may_be_taken_again(): void
    {
        $this->insertEntry('units', 'metre');
        DB::table(self::TABLE)->where('code', 'metre')->update(['deleted_at' => now()]);

        $this->insertEntry('units', 'metre');

        self::assertSame(2, DB::table(self::TABLE)->where('code', 'metre')->count());
    }

    public function test_that_a_blank_code_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->insertEntry('sectors', '')));
    }

    public function test_that_a_negative_position_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insertEntry('sectors', 'banking', position: -1)),
        );
    }

    /** §14.2: Arabic from the first release, not from a later migration. */
    public function test_that_an_entry_without_an_arabic_label_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insertEntry('sectors', 'retail', labelAr: null)),
        );
    }

    // ───────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward_again(): void
    {
        // Named, not `--step 1`. A step is "whatever migrated last", which
        // pointed Point 1.1's test at Point 1.2's migration.
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertFalse(Schema::hasTable(self::TABLE), 'down() left `enum_lists` behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasTable(self::TABLE), '`enum_lists` did not come back.');
    }

    // ──────────────────────────────────────────────────────────────── helpers

    private function insertEntry(
        string $list,
        string $code,
        int $position = 0,
        ?string $labelAr = 'قطاع',
    ): void {
        DB::table(self::TABLE)->insert([
            'id' => Uuid::uuid7()->toString(),
            'list' => $list,
            'code' => $code,
            'label_en' => 'Label',
            'label_ar' => $labelAr,
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function refusedWith(callable $write): string
    {
        try {
            $write();
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        self::fail('The database accepted a write it should have refused.');
    }
}
