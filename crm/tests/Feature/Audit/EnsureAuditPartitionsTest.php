<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Point 6.3 — `J-15 ensure_audit_partitions`.
 *
 * `D-72` seeded two months and a `DEFAULT` partition and stopped there. Nothing
 * created the third month, so this table had a working life of about eight
 * weeks: after that every row landed in `DEFAULT`, and a row in `DEFAULT` is
 * the one thing that **blocks** creating the partition it should have been in.
 * The failure is silent at the moment it starts and expensive by the time it is
 * noticed.
 *
 * ── What "idempotent" has to mean here ─────────────────────────────────────
 *
 * `§15` requires every job to be idempotent, and for this one the bar is higher
 * than "does not crash twice". A second run must not create, must not re-arm,
 * and must not report work it did not do — because the report is what an
 * operator reads to decide whether the audit log is healthy.
 *
 * ── The job heals, it does not only create ─────────────────────────────────
 *
 * Point 6.2 left a real hole: a `TRUNCATE` guard does not propagate, so any
 * partition created outside that migration has none. This job arms **every**
 * unguarded partition, not only the ones it made, which is what turns a
 * permanent hole into a window one day wide.
 */
final class EnsureAuditPartitionsTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'audit:ensure-partitions';

    /** The SQLSTATE Point 6.2's guard raises. */
    private const APPEND_ONLY = 'AUD03';

    // ───────────────────────────────────────────────────────────── creating

    public function test_it_creates_the_months_the_migration_did_not(): void
    {
        // D-72 seeds this month and the next. Everything beyond that is this
        // job's, and before it existed there was nothing beyond that.
        self::assertSame(
            [self::month(0), self::month(1), 'audit_log_default'],
            self::partitions(),
        );

        $this->job(3)->assertSuccessful();

        self::assertSame(
            [self::month(0), self::month(1), self::month(2), self::month(3), 'audit_log_default'],
            self::partitions(),
        );
    }

    public function test_the_partitions_it_creates_carry_utc_bounds(): void
    {
        $this->job(2)->assertSuccessful();

        $from = Date::now('UTC')->startOfMonth()->addMonths(2);
        $bound = self::partitionBound(self::month(2));

        self::assertStringContainsString($from->format('Y-m-d').' 00:00:00+00', $bound);
        self::assertStringContainsString(
            $from->copy()->addMonth()->format('Y-m-d').' 00:00:00+00',
            $bound,
        );
    }

    public function test_the_bounds_stay_utc_even_when_the_session_is_not(): void
    {
        // The same trap Point 6.1 fell into: an offsetless literal is read in
        // the session's zone, and on this stack the session is UTC, so the
        // check cannot fail on its own. Run it against a hostile session and
        // read the answer back in UTC, where the difference is visible.
        DB::statement("SET TimeZone = 'Asia/Riyadh'");

        $this->job(2)->assertSuccessful();

        DB::statement("SET TimeZone = 'UTC'");

        $from = Date::now('UTC')->startOfMonth()->addMonths(2)->format('Y-m-d');

        self::assertStringContainsString("{$from} 00:00:00+00", self::partitionBound(self::month(2)),
            'DB-08: a month starts at UTC midnight, not at midnight wherever the server sits.');
    }

    public function test_a_row_written_next_quarter_lands_in_its_own_month(): void
    {
        $this->job(3)->assertSuccessful();

        $id = self::insertAt(Date::now('UTC')->startOfMonth()->addMonths(3)->addDays(5));

        // The point of the whole job: not that partitions exist, but that rows
        // stop falling into DEFAULT.
        self::assertSame(self::month(3), self::partitionHolding($id));
    }

    // ────────────────────────────────────────────────────────── idempotency

    public function test_a_second_run_creates_nothing_and_reports_nothing(): void
    {
        $this->job(3)->assertSuccessful();
        $after = self::partitions();
        $triggers = self::guardedPartitions();

        // One expectation, not two: expectsOutputToContain() consumes a
        // separate output event per call, and both counts live on the same
        // line. Two calls demanded two lines and failed on a correct run.
        $this->job(3)
            ->expectsOutputToContain('created 0, armed 0')
            ->assertSuccessful();

        self::assertSame($after, self::partitions(), '§15: a second run must touch nothing.');
        self::assertSame($triggers, self::guardedPartitions());
    }

    public function test_the_first_run_reports_what_it_actually_did(): void
    {
        // The counterpart to the test above: "created 0" proves idempotency
        // only if a real run says something else.
        $this->job(3)
            ->expectsOutputToContain('created 2')
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────── arming the guard

    public function test_a_partition_it_creates_refuses_truncate(): void
    {
        $this->job(2)->assertSuccessful();

        // Behavioural, not the presence of a trigger name. A trigger attached
        // to the wrong function, or to the wrong event, still has a name.
        $this->assertRefused('truncate '.self::month(2));
    }

    public function test_it_arms_a_partition_somebody_else_created(): void
    {
        // Point 6.2's open hole, closed here. A partition made by hand, by a
        // restore, or by a future migration has no TRUNCATE guard, because that
        // kind of trigger does not propagate. This job does not care who made
        // it.
        // Settle first, so the count below is about this partition alone. A
        // fresh database also needs months creating, and those get armed too —
        // correct, but it would make "armed 1" mean nothing.
        $this->job()->assertSuccessful();

        DB::statement(
            "create table audit_log_2099_01 partition of audit_log
             for values from ('2099-01-01 00:00:00+00') to ('2099-02-01 00:00:00+00')",
        );
        self::assertNotContains('audit_log_2099_01', self::guardedPartitions());

        $this->job()
            ->expectsOutputToContain('created 0, armed 1')
            ->assertSuccessful();

        $this->assertRefused('truncate audit_log_2099_01');
    }

    public function test_it_arms_the_partitions_the_migration_left_unguarded(): void
    {
        // Defensive: if 6.2's migration is ever rolled back and forward while
        // partitions exist, the job is the thing that notices.
        DB::statement('DROP TRIGGER audit_log_no_truncate ON '.self::month(0));
        self::assertNotContains(self::month(0), self::guardedPartitions());

        $this->job()->assertSuccessful();

        $this->assertRefused('truncate '.self::month(0));
    }

    // ─────────────────────────────────────────── the default-partition alarm

    public function test_it_fails_when_the_default_partition_holds_rows(): void
    {
        // A row here means a month went uncreated, and it is worse than it
        // looks: while it sits there PostgreSQL refuses to create the range
        // partition that would have held it. The job's job is to shout.
        self::insertAt(Date::now('UTC')->startOfMonth()->subMonths(6));

        $this->job()
            ->expectsOutputToContain('audit_log_default')
            ->assertFailed();
    }

    public function test_it_succeeds_when_the_default_partition_is_empty(): void
    {
        self::assertSame(0, DB::table('audit_log_default')->count());

        $this->job()->assertSuccessful();
    }

    public function test_it_still_creates_and_arms_before_it_fails_on_a_stray_row(): void
    {
        // The alarm must not cost the maintenance. A run that refuses to do its
        // work because something else is wrong leaves next month uncreated too,
        // and then there are two problems.
        self::insertAt(Date::now('UTC')->startOfMonth()->subMonths(6));

        $this->job(3)->assertFailed();

        self::assertContains(self::month(3), self::partitions());
    }

    public function test_a_stray_row_blocks_its_own_month_without_stopping_the_run(): void
    {
        // The trap D-72 named, reached deliberately. A row dated two months out
        // lands in DEFAULT because that month has no partition yet — and while
        // it sits there PostgreSQL refuses to create that very partition:
        // `updated partition constraint for default partition ... would be
        // violated by some row`. The job must see this coming rather than die
        // on it, because dying stops it arming guards and creating every other
        // month too.
        $stranded = self::insertAt(Date::now('UTC')->startOfMonth()->addMonths(2)->addDays(3));
        self::assertSame('audit_log_default', self::partitionHolding($stranded));

        $this->job(3)
            ->expectsOutputToContain('cannot create '.self::month(2))
            ->assertFailed();

        // Blocked, and named.
        self::assertNotContains(self::month(2), self::partitions());

        // Everything else still happened. That is the whole point of checking
        // first instead of letting the statement fail.
        self::assertContains(self::month(3), self::partitions());
        $this->assertRefused('truncate '.self::month(3));
    }

    // ──────────────────────────────────────────────────────── the schedule

    public function test_the_job_is_scheduled_daily(): void
    {
        // §15: J-15 is a daily job. A command nobody runs is not a job.
        $commands = array_map(
            static fn (object $event): string => (string) ($event->command ?? ''),
            app(Schedule::class)->events(),
        );

        $scheduled = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (object $event): bool => str_contains((string) ($event->command ?? ''), self::COMMAND),
        ));

        self::assertCount(1, $scheduled,
            'Expected exactly one scheduled J-15; found: '.implode(', ', $commands));
        self::assertSame('0 0 * * *', $scheduled[0]->expression);
    }

    // ──────────────────────────────────────────────────────────── helpers

    /**
     * `artisan()` is typed `PendingCommand|int`, and level 10 refuses to call a
     * method on that union. Narrowed once here rather than suppressed at each
     * of the fourteen call sites — Coding Standards §5 forbids untyped escape
     * hatches, and a per-line ignore is one.
     */
    private function job(?int $monthsAhead = null): PendingCommand
    {
        $pending = $this->artisan(
            self::COMMAND,
            $monthsAhead === null ? [] : ['--months' => $monthsAhead],
        );

        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    private function assertRefused(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (QueryException $e) {
            self::assertSame(self::APPEND_ONLY, $e->getCode(),
                'Expected the append-only guard; got: '.$e->getMessage());

            return;
        }

        self::fail("AUD-03: the database must refuse this.\n  {$sql}");
    }

    private static function insertAt(\DateTimeInterface $at): string
    {
        $id = (string) Uuid::uuid7();

        DB::table('audit_log')->insert([
            'id' => $id,
            'event' => 'SELF_APPROVAL',
            'entity_type' => 'quotation',
            'entity_id' => (string) Uuid::uuid7(),
            'created_at' => $at->format('Y-m-d H:i:sP'),
        ]);

        return $id;
    }

    private static function month(int $ahead): string
    {
        return 'audit_log_'.Date::now('UTC')->startOfMonth()->addMonths($ahead)->format('Y_m');
    }

    private static function partitionHolding(string $id): string
    {
        /** @var object{partition: string}|null $row */
        $row = DB::selectOne(
            'select tableoid::regclass::text as partition from audit_log where id = ?',
            [$id],
        );

        self::assertNotNull($row);

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
            ['audit_log'],
        );

        return array_map(static fn (object $row): string => $row->relname, $rows);
    }

    /** Partitions that carry the TRUNCATE guard, by name. */
    /** @return list<string> */
    private static function guardedPartitions(): array
    {
        /** @var list<object{relname: string}> $rows */
        $rows = DB::select(
            "select c.relname
             from pg_trigger t
             join pg_class c on c.oid = t.tgrelid
             where t.tgname = 'audit_log_no_truncate' and not t.tgisinternal
             order by c.relname",
        );

        return array_map(static fn (object $row): string => $row->relname, $rows);
    }

    private static function partitionBound(string $partition): string
    {
        /** @var object{bound: string}|null $row */
        $row = DB::selectOne(
            'select pg_get_expr(c.relpartbound, c.oid) as bound from pg_class c where c.relname = ?',
            [$partition],
        );

        self::assertNotNull($row, "No such partition: {$partition}.");

        return $row->bound;
    }
}
