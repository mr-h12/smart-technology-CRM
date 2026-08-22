<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Point 6.2 — `audit_log` is append-only, and the database is what says so.
 *
 * `AUD-03` and `D-30`: immutable, retained permanently. Point 6.1 built the
 * table and left `UPDATE` and `DELETE` working on it, which made "immutable" a
 * description of nobody's intention rather than a property of the system.
 *
 * ── Why the guard is where it is ───────────────────────────────────────────
 *
 * Three placements, and the split between them is measured rather than chosen:
 *
 * - **Row triggers on the parent** cover `UPDATE` and `DELETE`, including a
 *   statement aimed straight at a partition, and PostgreSQL clones them onto
 *   partitions created **after** the trigger — so `J-15` inherits this half for
 *   free.
 * - **A statement trigger for `TRUNCATE` does not propagate**, in either
 *   direction: the parent's does not cover its partitions, and a partition's
 *   does not cover the parent. So one is attached per relation, and every
 *   partition `J-15` creates later needs its own — which is a real hole until
 *   Point 6.3, pinned below rather than left in a comment.
 * - **Nothing stops a superuser `DROP TABLE`**, and this application connects
 *   as one. That ceiling is stated in the report and on the debt register; it
 *   is not something a trigger can close.
 *
 * The guard raises a custom SQLSTATE, `AUD03`, so these tests pin a code rather
 * than a message. Message matching breaks the moment anyone rewords the text,
 * and the text is the part that should be free to change.
 */
final class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    /** The SQLSTATE the guard raises. Nothing else in this system raises it. */
    private const APPEND_ONLY = 'AUD03';

    private const PARENT = 'audit_log';

    // ─────────────────────────────────────────────── the operations refused

    #[DataProvider('mutations')]
    public function test_a_mutation_is_refused(string $sql): void
    {
        // A row has to exist first. The UPDATE/DELETE guard is a row trigger,
        // so a statement matching nothing never reaches it — see the no-op test
        // at the bottom, which pins that deliberately.
        $id = $this->insertOne();

        $this->assertRefused(str_replace('{id}', $id, $sql));
    }

    /** @return array<string, array{string}> */
    public static function mutations(): array
    {
        return [
            // The statements anyone reaches the parent with. The
            // partition-aimed forms get their own tests below, because they
            // need the partition's name looked up from the row.
            'update through the parent' => ["update audit_log set event = 'FORGED' where id = '{id}'"],
            'update every row' => ["update audit_log set event = 'FORGED'"],
            'delete through the parent' => ["delete from audit_log where id = '{id}'"],
            'delete every row' => ['delete from audit_log'],
            'truncate the parent' => ['truncate audit_log'],
        ];
    }

    public function test_an_update_aimed_straight_at_a_partition_is_refused(): void
    {
        $id = $this->insertOne();
        $partition = self::partitionHolding($id);

        // The parent is not a gate anyone has to pass through. A partition is
        // an ordinary table with an ordinary name, and anything with a
        // connection can write to it directly.
        $this->assertRefused("update {$partition} set event = 'FORGED' where id = '{$id}'");
    }

    public function test_a_delete_aimed_straight_at_a_partition_is_refused(): void
    {
        $id = $this->insertOne();
        $partition = self::partitionHolding($id);

        $this->assertRefused("delete from {$partition} where id = '{$id}'");
    }

    public function test_a_truncate_of_a_partition_is_refused(): void
    {
        // The one the parent's trigger does not cover, measured: a statement
        // trigger on a partitioned table does not reach its partitions, so
        // without a trigger of its own this emptied the month in one word.
        $id = $this->insertOne();
        $partition = self::partitionHolding($id);

        $this->assertRefused("truncate {$partition}");
    }

    public function test_a_truncate_of_the_default_partition_is_refused(): void
    {
        // The default partition holds whatever arrived before J-15 created its
        // month. It is the most likely one to look empty and disposable.
        $this->assertRefused('truncate audit_log_default');
    }

    public function test_an_upsert_cannot_smuggle_an_update_past_the_guard(): void
    {
        $id = $this->insertOne();

        // ON CONFLICT DO UPDATE is an UPDATE wearing an INSERT's clothes, and
        // it is the path a well-meaning writer reaches for to make an audit
        // write idempotent.
        $this->assertRefused(
            "insert into audit_log (id, event, entity_type, entity_id, created_at)
             values ('{$id}', 'SELF_APPROVAL', 'quotation', '{$id}', '".self::when()."')
             on conflict (id, created_at) do update set event = 'FORGED'",
        );
    }

    // ───────────────────────────────────────────── what must keep working

    public function test_an_insert_still_succeeds(): void
    {
        // The guard exists to protect an append-only table, so the one
        // operation it must never touch is the append.
        $id = $this->insertOne();

        self::assertSame(1, DB::table(self::PARENT)->where('id', $id)->count());
    }

    public function test_many_inserts_in_one_transaction_still_succeed(): void
    {
        // AUD-01 and DB-11 put the audit write inside the business
        // transaction, so a guard that made writes fail intermittently would
        // take business operations down with it.
        $ids = [];

        DB::transaction(function () use (&$ids): void {
            for ($i = 0; $i < 5; $i++) {
                $ids[] = $this->insertOne();
            }
        });

        self::assertSame(5, DB::table(self::PARENT)->whereIn('id', $ids)->count());
    }

    public function test_an_update_matching_no_rows_is_a_no_op_rather_than_an_error(): void
    {
        // Pinned because it surprises people, not because it is a problem: the
        // guard is a row trigger, so a statement that touches no row never
        // reaches it. Nothing was changed, so nothing was made mutable — but a
        // reader who probes with a WHERE that matches nothing gets silence and
        // may read it as permission.
        $affected = DB::update("update audit_log set event = 'FORGED' where id = ?", [
            (string) Uuid::uuid7(),
        ]);

        self::assertSame(0, $affected);
    }

    // ──────────────────────────────────────── partitions created afterwards

    public function test_a_partition_created_after_the_guard_inherits_the_row_guard(): void
    {
        // Measured: PostgreSQL clones a parent's row triggers onto partitions
        // created later. This is what lets J-15 create a month without
        // remembering to re-arm UPDATE and DELETE.
        DB::statement(
            "create table audit_log_2099_01 partition of audit_log
             for values from ('2099-01-01 00:00:00+00') to ('2099-02-01 00:00:00+00')",
        );

        $id = $this->insertOne('2099-01-15 00:00:00+00');
        self::assertSame('audit_log_2099_01', self::partitionHolding($id));

        $this->assertRefused("update audit_log_2099_01 set event = 'FORGED' where id = '{$id}'");
    }

    public function test_a_partition_created_after_the_guard_is_not_truncate_guarded_until_j15_runs(): void
    {
        // ⚠️ This asserts a WINDOW, not a feature, and it is here so the window
        // is machine-visible instead of living in a comment.
        //
        // A TRUNCATE trigger does not propagate, so a partition that appears
        // after this migration — by hand, by a restore, by a future migration —
        // carries no guard at the moment it is created. Point 6.3 did not close
        // that; it bounded it. `J-15` arms every unguarded partition on its
        // next daily run, whoever made it, which turns a permanent hole into a
        // window one day wide. `EnsureAuditPartitionsTest` holds the other half
        // of this statement: the same partition, after the job.
        //
        // If this ever starts failing, something armed the partition at
        // creation — an event trigger, most likely — and the window is gone.
        // That is good news; rewrite this test rather than making it pass.
        DB::statement(
            "create table audit_log_2099_02 partition of audit_log
             for values from ('2099-02-01 00:00:00+00') to ('2099-03-01 00:00:00+00')",
        );

        $id = $this->insertOne('2099-02-15 00:00:00+00');

        DB::statement('truncate audit_log_2099_02');

        self::assertSame(0, DB::table(self::PARENT)->where('id', $id)->count(),
            'If this now fails, something arms partitions at creation — rewrite, do not patch.');
    }

    // ───────────────────────────────────────────────── the guard itself

    public function test_the_guard_is_attached_to_the_parent_and_to_every_partition(): void
    {
        $relations = array_merge([self::PARENT], self::partitions());
        self::assertGreaterThan(3, count($relations),
            'The scanner read almost nothing, so it proved almost nothing.');

        foreach ($relations as $relation) {
            self::assertContains('audit_log_no_truncate', self::triggersOn($relation),
                "TRUNCATE does not propagate, so {$relation} needs its own guard.");
        }

        // The row guard is declared once and cloned, so it is asserted on the
        // parent and on a partition rather than on the parent alone.
        self::assertContains('audit_log_no_change', self::triggersOn(self::PARENT));
        self::assertContains('audit_log_no_change', self::triggersOn(self::partitions()[0]));
    }

    public function test_rolling_back_removes_every_trigger_and_the_function(): void
    {
        // Measured at RED: without this first assertion the test passed against
        // a database where the function had never been created at all, because
        // "no such function" and "down() removed the function" look identical
        // from the far side. It has to be there before its absence means
        // anything.
        self::assertSame(['audit_log_is_append_only'], self::appendOnlyFunctions());
        self::assertContains('audit_log_no_change', self::triggersOn(self::PARENT));

        Artisan::call('migrate:reset', ['--force' => true]);

        self::assertSame([], self::appendOnlyFunctions(),
            'DEV-03: down() must remove the function, not only the triggers.');
    }

    // ──────────────────────────────────────────────────────────── helpers

    private function assertRefused(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (QueryException $e) {
            self::assertSame(self::APPEND_ONLY, $e->getCode(),
                'Expected the append-only guard; got: '.$e->getMessage());

            return;
        }

        self::fail("AUD-03 and D-30: the database must refuse this.\n  {$sql}");
    }

    private function insertOne(?string $at = null): string
    {
        $id = (string) Uuid::uuid7();

        DB::table(self::PARENT)->insert([
            'id' => $id,
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
            'created_at' => $at ?? self::when(),
        ]);

        return $id;
    }

    /** A timestamp inside the current month's partition. */
    private static function when(): string
    {
        return Date::now('UTC')->startOfMonth()->addDay()->format('Y-m-d H:i:sP');
    }

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
            [self::PARENT],
        );

        return array_map(static fn (object $row): string => $row->relname, $rows);
    }

    /** @return list<string> */
    private static function triggersOn(string $relation): array
    {
        /** @var list<object{tgname: string}> $rows */
        $rows = DB::select(
            'select tgname from pg_trigger
             where tgrelid = ?::regclass and not tgisinternal
             order by tgname',
            [$relation],
        );

        return array_map(static fn (object $row): string => $row->tgname, $rows);
    }

    /** @return list<string> */
    private static function appendOnlyFunctions(): array
    {
        /** @var list<object{proname: string}> $rows */
        $rows = DB::select(
            "select proname from pg_proc where proname = 'audit_log_is_append_only'",
        );

        return array_map(static fn (object $row): string => $row->proname, $rows);
    }
}
