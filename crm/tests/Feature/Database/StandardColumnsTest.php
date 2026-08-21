<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * The standard column block, exercised rather than inspected.
 *
 * Asserting that columns exist would pass on a table that cannot actually hold
 * a row. These tests write and read, because DB-01 and DB-02 are behaviours:
 * a delete that removes the row, or a key that is not time-ordered, both look
 * fine in a schema dump.
 */
final class StandardColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('standard_column_probes', function (Blueprint $table): void {
            $table->standardColumns();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('standard_column_probes');
        parent::tearDown();
    }

    public function test_the_key_is_a_time_ordered_uuid(): void
    {
        // D-61: a UUID, and time-ordered so inserts stay sequential and the
        // index does not fragment. Version 7 carries the timestamp in its high
        // bits, which is what makes consecutive values sort in creation order.
        $first = StandardColumnProbe::create(['name' => 'first']);
        usleep(2000);
        $second = StandardColumnProbe::create(['name' => 'second']);

        self::assertTrue(Uuid::isValid($first->id));
        self::assertSame(7, Uuid::fromString($first->id)->getVersion());
        self::assertLessThan($second->id, $first->id,
            'D-61 requires time-ordered keys: a later row must sort after an earlier one.');
    }

    public function test_the_database_stores_the_key_as_a_uuid_type(): void
    {
        // Not a string that happens to look like one — PostgreSQL's uuid type
        // is what keeps it 16 bytes and comparable.
        self::assertSame('uuid', self::columnType('id'));
    }

    public function test_deleting_is_soft_and_the_row_survives(): void
    {
        // DB-01: no physical DELETE. The row must still be in the table.
        $probe = StandardColumnProbe::create(['name' => 'keep me']);
        $probe->delete();

        self::assertSame(0, StandardColumnProbe::count());
        self::assertSame(1, StandardColumnProbe::withTrashed()->count());
        self::assertSame(1, (int) DB::table('standard_column_probes')->count());
        $trashed = StandardColumnProbe::withTrashed()->first();
        self::assertNotNull($trashed);
        self::assertNotNull($trashed->deleted_at);
    }

    public function test_the_audit_columns_exist_and_accept_an_actor(): void
    {
        // DB-02. They are nullable because Module 1 has not built the users
        // table yet and Module 0 step 6 wires the actor in with the audit layer.
        $actor = (string) \Illuminate\Support\Str::uuid7();
        $probe = StandardColumnProbe::create(['name' => 'with actor', 'created_by' => $actor]);

        $stored = $probe->fresh();
        self::assertNotNull($stored);
        self::assertSame($actor, $stored->created_by);
        foreach (['created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            self::assertTrue(
                Schema::hasColumn('standard_column_probes', $column),
                "DB-02 requires {$column}."
            );
        }
    }

    public function test_timestamps_are_stored_with_a_timezone(): void
    {
        // DB-08: UTC in storage. timestamptz is what makes that unambiguous;
        // a plain timestamp would silently record a local instant.
        foreach (['created_at', 'updated_at', 'deleted_at'] as $column) {
            self::assertSame('timestamp with time zone', self::columnType($column),
                "{$column} must be timestamptz.");
        }
    }

    public function test_scope_index_creates_a_real_partial_index(): void
    {
        // §3.2 gives every role but Manager and CEO a row scope, so effectively
        // every list query filters on an owner column *and* excludes soft-
        // deleted rows. Measured on 20k rows, pairing them in one partial index
        // took an owner-scoped count from a 1.30 ms sequential scan to a 0.22 ms
        // bitmap index scan.
        //
        // The assertion checks the WHERE clause specifically. Laravel's
        // index()->where() is silently ignored and yields an ordinary index,
        // which looks correct in the migration and is not.
        //
        // This is the Schema::table() path, on a table that already exists. It
        // was the only path covered, and it was the only path that worked.
        Schema::table('standard_column_probes', function (Blueprint $table): void {
            $table->scopeIndex('created_by');
        });

        $definition = self::indexDefinition('standard_column_probes_created_by_alive_index');

        self::assertNotNull($definition, 'scopeIndex must produce an index.');
        self::assertStringContainsString('WHERE (deleted_at IS NULL)', $definition,
            'It must be a partial index, not a plain one.');
        self::assertStringContainsString('created_by', $definition);
    }

    // ── The Schema::create() path — regression for the 42P01 crash ───────────

    public function test_scope_index_works_inside_schema_create(): void
    {
        // The defect, as a test. A Blueprint records commands and the connection
        // runs them after the closure returns, CREATE TABLE first; the original
        // macro called DB::statement() inside the closure, so the index was
        // created before its own table existed. Every real migration declares
        // its indexes exactly this way, so this path is the one that mattered
        // and it was the one nothing covered.
        //
        // Failure mode if it regresses:
        //   SQLSTATE[42P01]: Undefined table: 7 ERROR: relation
        //   "scope_index_create_probes" does not exist
        Schema::dropIfExists('scope_index_create_probes');

        Schema::create('scope_index_create_probes', function (Blueprint $table): void {
            $table->standardColumns();
            $table->uuid('owner_id');
            $table->scopeIndex('owner_id');
        });

        self::assertTrue(Schema::hasTable('scope_index_create_probes'),
            'The table must exist: an index created too early aborts the whole create.');

        $definition = self::indexDefinition('scope_index_create_probes_owner_id_alive_index');

        self::assertNotNull($definition, 'The scope index must exist after Schema::create().');
        self::assertStringContainsString('WHERE (deleted_at IS NULL)', $definition);

        Schema::dropIfExists('scope_index_create_probes');
    }

    // ── Failure and edge cases ──────────────────────────────────────────────

    public function test_a_scope_index_over_several_columns_keeps_their_order(): void
    {
        // (owner_id, status) is the pipeline board's filter — DATABASE.md lists
        // it for deals. Column order decides whether the index can serve a query
        // on owner_id alone, so it is asserted, not assumed.
        Schema::dropIfExists('scope_index_multi_probes');

        Schema::create('scope_index_multi_probes', function (Blueprint $table): void {
            $table->standardColumns();
            $table->uuid('owner_id');
            $table->string('status', 24);
            $table->scopeIndex('owner_id', 'status');
        });

        $definition = self::indexDefinition('scope_index_multi_probes_owner_id_status_alive_index');

        self::assertNotNull($definition);
        self::assertMatchesRegularExpression('/\(owner_id,\s*status\)/', $definition,
            'Both columns must be in the index, in the order they were declared.');
        self::assertStringContainsString('WHERE (deleted_at IS NULL)', $definition);

        Schema::dropIfExists('scope_index_multi_probes');
    }

    public function test_declaring_the_same_scope_index_twice_fails_loudly(): void
    {
        // IF NOT EXISTS was removed with the immediate execution that needed it.
        // Swallowing a duplicate would leave whichever index already existed in
        // place — possibly over different columns — while the migration
        // reported success.
        // Wrapped in a savepoint, the same way PrecisionTest wraps its overflow
        // check: PostgreSQL aborts the surrounding transaction on error, so the
        // cleanup DROP would fail with "current transaction is aborted" and the
        // test would report 25P02 instead of the duplicate it is about.
        $rejected = false;

        DB::statement('savepoint duplicate_scope_index_probe');
        try {
            Schema::create('scope_index_dupe_probes', function (Blueprint $table): void {
                $table->standardColumns();
                $table->uuid('owner_id');
                $table->scopeIndex('owner_id');
                $table->scopeIndex('owner_id');
            });
        } catch (QueryException $e) {
            $rejected = true;
            self::assertStringContainsString('already exists', $e->getMessage());
            self::assertStringContainsString('scope_index_dupe_probes_owner_id_alive_index', $e->getMessage());
        } finally {
            DB::statement('rollback to savepoint duplicate_scope_index_probe');
        }

        self::assertTrue($rejected, 'A duplicate scope index must not be accepted silently.');
        self::assertFalse(Schema::hasTable('scope_index_dupe_probes'),
            'The failed create must leave no table behind.');
    }

    public function test_a_scope_index_with_no_columns_is_rejected_before_any_sql(): void
    {
        // Coding Standards §5: validate at the boundary rather than letting a
        // bad value become invalid SQL. Unchecked this compiles to
        // `create index "t__alive_index" on "t" () where ...`, whose syntax
        // error names a paren rather than the mistake.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scopeIndex() needs at least one column');

        Schema::create('scope_index_empty_probes', function (Blueprint $table): void {
            $table->standardColumns();
            $table->scopeIndex();
        });
    }

    // ── Behavioural: Laravel's real migration path ──────────────────────────

    public function test_the_migration_path_creates_and_rolls_back_the_scope_index(): void
    {
        // Schema::create() from a test is not the migration path. This runs the
        // real binary's migrator over a fixture migration, then its down path —
        // DEV-03 requires the rollback to be tested, not assumed.
        $database = DB::connection()->getDatabaseName();
        self::assertStringEndsWith('_test', $database,
            "Refusing to migrate against '{$database}'.");

        $path = 'tests/Fixtures/migrations';

        try {
            Artisan::call('migrate', ['--path' => $path, '--force' => true]);

            self::assertTrue(Schema::hasTable('scope_index_probes'),
                Artisan::output());

            $single = self::indexDefinition('scope_index_probes_owner_id_alive_index');
            $composite = self::indexDefinition('scope_index_probes_owner_id_status_alive_index');

            self::assertNotNull($single, 'migrate must create the single-column scope index.');
            self::assertNotNull($composite, 'migrate must create the composite scope index.');
            self::assertStringContainsString('WHERE (deleted_at IS NULL)', $single);
            self::assertStringContainsString('WHERE (deleted_at IS NULL)', $composite);

            Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);

            self::assertFalse(Schema::hasTable('scope_index_probes'),
                'down() must drop the table.');
            self::assertNull(self::indexDefinition('scope_index_probes_owner_id_alive_index'),
                'The indexes must go with it — a left-behind index collides on the next migrate.');
        } finally {
            Schema::dropIfExists('scope_index_probes');
        }
    }

    /** The definition Postgres actually stored, or null when there is no such index. */
    private static function indexDefinition(string $name): ?string
    {
        /** @var object{indexdef: string}|null $row */
        $row = DB::selectOne('select indexdef from pg_indexes where indexname = ?', [$name]);

        return $row?->indexdef;
    }

    /**
     * information_schema through selectOne returns mixed, which level 10 will
     * not let us dereference. Narrowed once here rather than cast at each use.
     */
    private static function columnType(string $column): string
    {
        $row = DB::selectOne(
            'select data_type from information_schema.columns
             where table_name = ? and column_name = ?',
            ['standard_column_probes', $column],
        );

        self::assertIsObject($row);
        self::assertObjectHasProperty('data_type', $row);

        return (string) $row->data_type;   // @phpstan-ignore-line cast.string
    }
}

/**
 * Larastan reads Eloquent's magic from a model's documented shape, and this
 * table exists only for the duration of the test, so the columns the standard
 * block adds are declared here.
 *
 * @property string $id
 * @property string $name
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
final class StandardColumnProbe extends Model
{
    use HasStandardColumns;

    protected $table = 'standard_column_probes';

    protected $guarded = [];
}
