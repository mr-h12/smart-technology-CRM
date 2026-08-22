<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Point 6.1 — `audit_log`, in the shape `D-72` chose when it closed `Q-3`.
 *
 * `DB-10` required partitioning and named no granularity; `D-72` answers month,
 * `RANGE` on `created_at`. Three of the four documented indexes end in
 * `created_at DESC`, so the questions this table is asked are about recent
 * windows, and a yearly partition would read a year to answer one about a
 * fortnight.
 *
 * **What is worth testing here is not the column list.** A schema dump proves
 * the names; it proves nothing about whether a row lands in the right
 * partition, whether an index reached the partitions or only the parent, or
 * whether `down()` still works once a partition exists that this migration
 * never created. Those are the three ways a partitioned table goes wrong
 * quietly, so those are what these tests measure.
 *
 * ── Why `down()` discovers partitions instead of naming them ───────────────
 *
 * `J-15` (Point 6.3) creates future partitions. By the time anyone rolls this
 * migration back there will be partitions whose names this file never knew, and
 * a `down()` that drops a hard-coded list would leave them behind and fail.
 * `test_down_also_drops_a_partition_this_migration_never_created` is that
 * requirement written where a machine can check it, ahead of the job that
 * makes it matter.
 */
final class AuditLogMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'audit_log';

    /** `§14.8` `AUD-02` plus the correlation ID from `Coding Standards §10`. */
    private const DOCUMENTED_COLUMNS = [
        'id', 'user_id', 'event', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'ip_address', 'user_agent',
        'request_id', 'correlation_id', 'created_at',
    ];

    /**
     * `AUD-03` and `D-30`: immutable, retained permanently. A column that
     * records a change or a deletion says the row can have one.
     */
    private const FORBIDDEN_COLUMNS = ['updated_at', 'deleted_at', 'created_by', 'updated_by'];

    /** `D-69` bounds the caller-supplied correlation ID at 128 characters. */
    private const CORRELATION_MAX_LENGTH = 128;

    // ────────────────────────────────────────────── the table and its shape

    public function test_the_table_exists_and_is_partitioned_by_range_on_created_at(): void
    {
        self::assertTrue(Schema::hasTable(self::TABLE));

        // relkind 'p' is a partitioned table; 'r' is an ordinary one. A plain
        // table with the right columns passes every column assertion in this
        // file and satisfies none of DB-10, so this is asserted first.
        self::assertSame('p', self::relKind(self::TABLE),
            'DB-10 and D-72 require a partitioned table, not an ordinary one.');

        /** @var object{strategy: string, key: string}|null $row */
        $row = DB::selectOne(
            'select partstrat as strategy,
                    pg_get_partkeydef(c.oid) as key
             from pg_partitioned_table p
             join pg_class c on c.oid = p.partrelid
             where c.relname = ?',
            [self::TABLE],
        );

        self::assertNotNull($row);
        self::assertSame('r', $row->strategy, 'D-72 says RANGE, not LIST or HASH.');
        self::assertSame('RANGE (created_at)', $row->key);
    }

    public function test_the_table_carries_exactly_the_documented_columns(): void
    {
        $expected = self::DOCUMENTED_COLUMNS;
        sort($expected);

        $actual = Schema::getColumnListing(self::TABLE);
        sort($actual);

        // As a whole set, so a surplus column is as visible as a missing one:
        // an undocumented column in a permanently retained table is one nobody
        // can ever remove.
        self::assertSame($expected, $actual);
    }

    #[DataProvider('forbiddenColumns')]
    public function test_the_table_has_none_of_the_mutable_business_columns(string $column): void
    {
        // Measured at RED: without this line the whole data set passed against
        // a database with no audit_log in it, because hasColumn() on a missing
        // table is false for every column anyone can name. A check that holds
        // when its subject does not exist is not a check.
        self::assertTrue(Schema::hasTable(self::TABLE));

        self::assertFalse(Schema::hasColumn(self::TABLE, $column),
            "AUD-03 and D-30: '{$column}' would imply an audit row can change or be retired.");
    }

    /** @return array<string, array{string}> */
    public static function forbiddenColumns(): array
    {
        return array_combine(
            self::FORBIDDEN_COLUMNS,
            array_map(static fn (string $c): array => [$c], self::FORBIDDEN_COLUMNS),
        );
    }

    #[DataProvider('documentedTypes')]
    public function test_the_column_types_are_the_ones_the_documentation_names(
        string $column,
        string $type,
    ): void {
        self::assertSame($type, self::columnType($column));
    }

    /** @return array<string, array{string, string}> */
    public static function documentedTypes(): array
    {
        return [
            // D-61: UUID keys.
            'id is a uuid' => ['id', 'uuid'],
            'user_id is a uuid' => ['user_id', 'uuid'],
            'entity_id is a uuid' => ['entity_id', 'uuid'],
            // DATABASE.md: JSONB, so the values can be queried rather than only stored.
            'old_values is jsonb' => ['old_values', 'jsonb'],
            'new_values is jsonb' => ['new_values', 'jsonb'],
            // INET rather than a string: the database validates the address.
            'ip_address is inet' => ['ip_address', 'inet'],
            // DB-08: stored UTC. timestamp WITHOUT time zone loses the offset.
            'created_at is timestamptz' => ['created_at', 'timestamp with time zone'],
        ];
    }

    public function test_the_primary_key_is_the_composite_of_id_and_created_at(): void
    {
        // Measured, not chosen: PostgreSQL refuses a unique constraint on a
        // partitioned table that omits a partitioning column, so `id` alone is
        // not available. D-72 records the consequence.
        self::assertSame(
            'PRIMARY KEY (id, created_at)',
            self::constraintDefinition('audit_log_pkey'),
        );
    }

    #[DataProvider('requiredColumns')]
    public function test_a_required_column_rejects_null(string $column): void
    {
        // Measured at RED: expectException(QueryException::class) passed for
        // every column against a database with no audit_log, because
        // "relation does not exist" is a QueryException too. 23502 is
        // not_null_violation specifically, and nothing else raises it.
        //
        // One column depends on the DEFAULT partition to reach 23502 at all:
        // routing runs before the NOT NULL check, so a null created_at with no
        // DEFAULT raises 23514, `no partition of relation "audit_log" found for
        // row`. Measured by deleting the default partition. It is one more
        // reason D-72 keeps it — without it the database reports a partitioning
        // problem for what is really a missing timestamp.
        try {
            DB::table(self::TABLE)->insert(self::row([$column => null]));
        } catch (QueryException $e) {
            self::assertSame('23502', $e->getCode(),
                "Expected a NOT NULL violation on '{$column}'; got: ".$e->getMessage());

            return;
        }

        self::fail("AUD-02 lists '{$column}' among what is recorded, so NULL must be refused.");
    }

    /** @return array<string, array{string}> */
    public static function requiredColumns(): array
    {
        // entity_type and entity_id are required on the strict reading of
        // AUD-02, which lists the entity among what is recorded. A system
        // failure with no entity belongs in the error centre (AUD-06), which is
        // a different destination, not an audit row with empty columns.
        $columns = ['id', 'event', 'entity_type', 'entity_id', 'created_at'];

        return array_combine(
            $columns,
            array_map(static fn (string $c): array => [$c], $columns),
        );
    }

    public function test_the_optional_columns_accept_null(): void
    {
        // user_id has no actor until Module 1, and a scheduled job has no IP,
        // no user agent and no inbound request. A NOT NULL here would make the
        // audit write fail exactly when the system acts on its own behalf.
        $id = (string) Uuid::uuid7();

        DB::table(self::TABLE)->insert(self::row([
            'id' => $id,
            'user_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => null,
            'user_agent' => null,
            'request_id' => null,
            'correlation_id' => null,
        ]));

        self::assertSame(1, DB::table(self::TABLE)->where('id', $id)->count());
    }

    public function test_the_correlation_column_admits_the_full_d69_length(): void
    {
        $id = (string) Uuid::uuid7();
        $correlation = str_repeat('a', self::CORRELATION_MAX_LENGTH);

        DB::table(self::TABLE)->insert(self::row([
            'id' => $id,
            'correlation_id' => $correlation,
        ]));

        /** @var object{correlation_id: string}|null $row */
        $row = DB::table(self::TABLE)->where('id', $id)->first();

        self::assertNotNull($row);
        // Not truncated. D-69 discards an overlong value rather than cutting it,
        // and a column shorter than the rule would cut it here instead.
        self::assertSame($correlation, $row->correlation_id);
    }

    // ─────────────────────────────────────────────────────────────── indexes

    #[DataProvider('documentedIndexes')]
    public function test_the_documented_index_exists_on_the_parent(
        string $name,
        string $columns,
    ): void {
        $definition = self::indexDefinition($name);

        self::assertNotNull($definition, "DATABASE.md names this index: {$name}.");
        self::assertStringContainsString($columns, $definition);
    }

    /** @return array<string, array{string, string}> */
    public static function documentedIndexes(): array
    {
        return [
            'the record-history panel' => [
                'audit_log_entity_index', '(entity_type, entity_id, created_at DESC)',
            ],
            'what did this person do' => [
                'audit_log_user_index', '(user_id, created_at DESC)',
            ],
            'the mandatory critical events' => [
                'audit_log_event_index', '(event, created_at DESC)',
            ],
            'tracing one request across modules' => [
                'audit_log_correlation_index', '(correlation_id)',
            ],
        ];
    }

    public function test_every_parent_index_is_inherited_by_every_partition(): void
    {
        $partitions = self::partitions();
        self::assertNotSame([], $partitions, 'The scanner read no partitions, so it proved nothing.');

        foreach ($partitions as $partition) {
            $local = DB::table('pg_indexes')
                ->where('tablename', $partition)
                ->count();

            // Four documented indexes plus the primary key. An index that only
            // reached the parent is an index that answers nothing, because
            // every scan runs against a partition.
            self::assertSame(5, $local,
                "Partition {$partition} must carry the four indexes and the primary key.");
        }
    }

    // ──────────────────────────────────────────────────── partitions and routing

    public function test_the_current_and_next_month_partitions_exist(): void
    {
        foreach ([self::monthName(0), self::monthName(1)] as $name) {
            self::assertContains($name, self::partitions(),
                'D-72 seeds this month and the next; J-15 takes over from there.');
        }
    }

    public function test_the_month_partitions_carry_utc_bounds(): void
    {
        $bound = self::partitionBound(self::monthName(0));

        $from = Date::now('UTC')->startOfMonth()->format('Y-m-d');
        $to = Date::now('UTC')->startOfMonth()->addMonth()->format('Y-m-d');

        // The offset is the point. A bound literal with no zone is read in the
        // session's TimeZone, which would silently shift every boundary by
        // however many hours the server happens to be from UTC (DB-08).
        self::assertStringContainsString("{$from} 00:00:00+00", $bound);
        self::assertStringContainsString("{$to} 00:00:00+00", $bound);
    }

    public function test_the_month_bounds_stay_utc_even_when_the_session_is_not(): void
    {
        // The assertion above cannot fail on its own, and that was measured:
        // removing the `+00` from the bound literals changed nothing, because
        // this stack connects with `TimeZone = UTC` and an offsetless literal
        // is then read as UTC anyway. So the check was worth exactly nothing
        // against the defect it was written for.
        //
        // A bound literal without an offset is interpreted in the *session's*
        // TimeZone. On any connection that is not UTC, every month boundary
        // moves by that offset — silently, against DB-08 — and rows land in
        // the wrong month. This runs the real migration under a hostile
        // session so the offset has something to be load-bearing against.
        DB::statement("SET TimeZone = 'Asia/Riyadh'");

        Artisan::call('migrate:reset', ['--force' => true]);
        Artisan::call('migrate', ['--force' => true]);

        // Read it back as UTC. pg_get_expr renders a timestamptz bound in the
        // *session's* zone, so leaving the session on Riyadh would print the
        // correct instant as '03:00:00+03' and the wrong one as '00:00:00+03' —
        // two different values that both look like a month boundary. Rendering
        // both in UTC is what makes the difference visible: the correct bound
        // is 00:00 on the first, the broken one is 21:00 on the last of the
        // month before.
        DB::statement("SET TimeZone = 'UTC'");

        $from = Date::now('UTC')->startOfMonth()->format('Y-m-d');
        $to = Date::now('UTC')->startOfMonth()->addMonth()->format('Y-m-d');
        $bound = self::partitionBound(self::monthName(0));

        self::assertStringContainsString("{$from} 00:00:00+00", $bound,
            'DB-08: the month starts at UTC midnight, not at midnight wherever the server is.');
        self::assertStringContainsString("{$to} 00:00:00+00", $bound);
    }

    public function test_a_default_partition_exists(): void
    {
        self::assertContains('audit_log_default', self::partitions());
        self::assertSame('DEFAULT', self::partitionBound('audit_log_default'));
    }

    #[DataProvider('routableMonths')]
    public function test_a_row_routes_to_the_partition_for_its_month(int $monthsAhead): void
    {
        $id = (string) Uuid::uuid7();
        $at = Date::now('UTC')->startOfMonth()->addMonths($monthsAhead)->addDays(3);

        DB::table(self::TABLE)->insert(self::row(['id' => $id, 'created_at' => $at]));

        self::assertSame(self::monthName($monthsAhead), self::partitionHolding($id));
    }

    /** @return array<string, array{int}> */
    public static function routableMonths(): array
    {
        return ['this month' => [0], 'next month' => [1]];
    }

    #[DataProvider('unroutableDates')]
    public function test_a_row_outside_every_range_lands_in_the_default_partition(int $monthsAway): void
    {
        // Without a DEFAULT this insert fails, and because AUD-01 and DB-11 put
        // the audit write inside the business transaction, the business
        // operation fails with it. D-72 accepts a partition that must be
        // watched over an audit that can take the system down.
        $id = (string) Uuid::uuid7();
        $at = Date::now('UTC')->startOfMonth()->addMonths($monthsAway);

        DB::table(self::TABLE)->insert(self::row(['id' => $id, 'created_at' => $at]));

        self::assertSame('audit_log_default', self::partitionHolding($id));
    }

    /** @return array<string, array{int}> */
    public static function unroutableDates(): array
    {
        return [
            'a backdated row from last month' => [-1],
            'a row two months ahead, past the seeded pair' => [2],
            'a row years ahead' => [60],
        ];
    }

    public function test_the_default_partition_is_empty_when_the_migration_has_just_run(): void
    {
        // The health check J-15 will run (Point 6.3), asserted here at the one
        // moment it is trivially true, so a migration that seeds the wrong
        // bounds is caught immediately rather than at the first alert.
        self::assertSame(0, DB::table('audit_log_default')->count());
    }

    public function test_created_at_round_trips_as_utc(): void
    {
        $id = (string) Uuid::uuid7();
        $at = Date::now('UTC')->startOfMonth()->addDays(2)->setTime(23, 30);

        DB::table(self::TABLE)->insert(self::row(['id' => $id, 'created_at' => $at]));

        /** @var object{at: string}|null $row */
        $row = DB::selectOne(
            "select to_char(created_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as at
             from audit_log where id = ?",
            [$id],
        );

        self::assertNotNull($row);
        self::assertSame($at->format('Y-m-d H:i:s'), $row->at);
    }

    // ───────────────────────────────────────────────────────── the down path

    public function test_down_drops_every_partition_and_the_parent(): void
    {
        // migrate:reset, not `migrate:rollback --step 1`. For rollback, --step
        // is a count of MIGRATIONS, not batches, so `--step 1` reverts only the
        // newest one — and these tests passed only because audit_log happened
        // to be it. Point 6.2 added a migration on top and both down tests went
        // red without either down() having changed. A test whose meaning
        // depends on nothing else being added after it is a test with a
        // shelf life.
        self::assertNotSame([], self::partitions());

        Artisan::call('migrate:reset', ['--force' => true]);

        self::assertFalse(Schema::hasTable(self::TABLE), 'down() must drop the parent.');
        self::assertSame([], self::tablesNamedLikeAuditLog(),
            'A left-behind partition collides on the next migrate.');
    }

    public function test_down_also_drops_a_partition_this_migration_never_created(): void
    {
        // This is the J-15 case: every month adds a partition whose name the
        // migration never knew, and a rollback still has to leave nothing
        // behind.
        //
        // **What this test can and cannot tell.** It pins PostgreSQL's
        // behaviour — dropping a partitioned parent drops its partitions, which
        // is the whole of down(). It cannot distinguish that from a down() that
        // also enumerates partitions first, and it did not: an earlier version
        // of this migration looped over pg_inherits, and this test passed
        // identically with the loop deleted. That loop is gone, and this
        // comment is here so the absence reads as measured rather than missed.
        DB::statement(
            "create table audit_log_2099_01 partition of audit_log
             for values from ('2099-01-01 00:00:00+00') to ('2099-02-01 00:00:00+00')",
        );

        self::assertContains('audit_log_2099_01', self::partitions());

        Artisan::call('migrate:reset', ['--force' => true]);

        self::assertSame([], self::tablesNamedLikeAuditLog());
    }

    // ──────────────────────────────────────────────────────────────── helpers

    /**
     * A complete, valid row, with the given fields overridden.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Uuid::uuid7(),
            'user_id' => (string) Uuid::uuid7(),
            'event' => 'SELF_APPROVAL',
            'entity_type' => 'quotation',
            'entity_id' => (string) Uuid::uuid7(),
            'old_values' => json_encode(['margin' => '10.000']),
            'new_values' => json_encode(['margin' => '12.500']),
            'ip_address' => '10.0.0.7',
            'user_agent' => 'Mozilla/5.0',
            'request_id' => (string) Uuid::uuid7(),
            'correlation_id' => (string) Uuid::uuid7(),
            'created_at' => Date::now('UTC')->startOfMonth()->addDays(1),
        ], $overrides);
    }

    /** The partition a row actually landed in, read from the row itself. */
    private static function partitionHolding(string $id): string
    {
        /** @var object{partition: string}|null $row */
        $row = DB::selectOne(
            'select tableoid::regclass::text as partition from audit_log where id = ?',
            [$id],
        );

        self::assertNotNull($row, "No audit row with id {$id}.");

        return $row->partition;
    }

    /** @return list<string> */
    private static function partitions(): array
    {
        /** @var list<object{relname: string}> $rows */
        $rows = DB::select(
            'select child.relname
             from pg_inherits
             join pg_class parent on parent.oid = pg_inherits.inhparent
             join pg_class child on child.oid = pg_inherits.inhrelid
             where parent.relname = ?
             order by child.relname',
            [self::TABLE],
        );

        return array_map(static fn (object $row): string => $row->relname, $rows);
    }

    /**
     * Every table whose name starts with the parent's, partitions included.
     *
     * @return list<string>
     */
    private static function tablesNamedLikeAuditLog(): array
    {
        /** @var list<object{tablename: string}> $rows */
        $rows = DB::select(
            "select tablename from pg_tables
             where schemaname = current_schema() and tablename like 'audit\\_log%'
             order by tablename",
        );

        return array_map(static fn (object $row): string => $row->tablename, $rows);
    }

    /** The definition Postgres actually stored, or null when there is no such index. */
    private static function indexDefinition(string $name): ?string
    {
        /** @var object{indexdef: string}|null $row */
        $row = DB::selectOne('select indexdef from pg_indexes where indexname = ?', [$name]);

        return $row?->indexdef;
    }

    private static function partitionBound(string $partition): string
    {
        /** @var object{bound: string}|null $row */
        $row = DB::selectOne(
            'select pg_get_expr(c.relpartbound, c.oid) as bound
             from pg_class c where c.relname = ?',
            [$partition],
        );

        self::assertNotNull($row, "No such partition: {$partition}.");

        return $row->bound;
    }

    private static function monthName(int $monthsAhead): string
    {
        return 'audit_log_'.Date::now('UTC')->startOfMonth()->addMonths($monthsAhead)->format('Y_m');
    }

    private static function relKind(string $table): string
    {
        /** @var object{relkind: string}|null $row */
        $row = DB::selectOne('select relkind from pg_class where relname = ?', [$table]);

        self::assertNotNull($row, "No such relation: {$table}.");

        return $row->relkind;
    }

    private static function columnType(string $column): string
    {
        /** @var object{data_type: string}|null $row */
        $row = DB::selectOne(
            'select data_type from information_schema.columns
             where table_name = ? and column_name = ?',
            [self::TABLE, $column],
        );

        self::assertNotNull($row, "No such column: {$column}.");

        return $row->data_type;
    }

    private static function constraintDefinition(string $name): string
    {
        /** @var object{def: string}|null $row */
        $row = DB::selectOne(
            'select pg_get_constraintdef(oid) as def from pg_constraint where conname = ?',
            [$name],
        );

        self::assertNotNull($row, "No such constraint: {$name}.");

        return $row->def;
    }
}
