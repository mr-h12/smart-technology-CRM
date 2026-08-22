<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * `§14.8` the audit log, in the shape `D-72` chose when it closed `Q-3`.
 *
 * **Written as raw DDL rather than through `Schema::create()`**, because
 * Laravel's Blueprint has no `PARTITION BY` and `DB-10` requires one. A
 * Blueprint here would produce an ordinary table that satisfies every column
 * the specification names and none of the rule that matters.
 *
 * Three things in this file are measured facts about PostgreSQL 17.5 rather
 * than preferences, and `D-72` records each:
 *
 * 1. **The primary key is `(id, created_at)`.** A unique constraint on a
 *    partitioned table must contain every partitioning column — otherwise
 *    `unique constraint on partitioned table must include all partitioning
 *    columns`. `id` alone was not available.
 * 2. **A `DEFAULT` partition is kept**, even though a row that lands in it
 *    blocks creating the range partition that would have held it. Without one,
 *    an insert outside every range fails outright — and `AUD-01` with `DB-11`
 *    put the audit write inside the business transaction, so a partition
 *    nobody created would take the business operation down with it. `J-15`
 *    (Point 6.3) both creates ahead and asserts this partition stays empty.
 * 3. **The indexes are declared on the parent**, which creates them on every
 *    existing partition and on every future one. An index that reached only
 *    the parent answers nothing, because every scan runs against a partition.
 *
 * ── `audit_log` is not a business table ────────────────────────────────────
 *
 * No `standardColumns()`. `DB-01` and `DB-02` describe rows that change and are
 * retired; `AUD-03` and `D-30` say this one never does either. So there is no
 * `updated_at`, no `deleted_at`, and no `created_by`/`updated_by` — the actor
 * is `user_id`, which carries no foreign key until Module 1 replaces the users
 * table, the same arrangement `standardActorForeignKeys()` already waits on.
 */
return new class extends Migration
{
    private const TABLE = 'audit_log';

    /**
     * How many months to seed. `J-15` takes over from here; this is only enough
     * that the table works the moment it exists, including for a row written a
     * few seconds before midnight on the last day of the month.
     */
    private const SEED_MONTHS = 2;

    public function up(): void
    {
        // AUD-02: user · event · entity · old · new · time · IP · device, plus
        // the correlation ID from Coding Standards §10.
        //
        // Nullability is not uniform and the split is deliberate. entity_type
        // and entity_id are required on the strict reading of AUD-02, which
        // lists the entity among what is recorded; a system failure with no
        // entity belongs in the error centre (AUD-06), which is a different
        // destination rather than an audit row with empty columns. Everything
        // optional is optional for one reason: a scheduled job has no actor, no
        // IP, no user agent and no inbound request, and a NOT NULL there would
        // make the audit write fail exactly when the system acts on its own.
        DB::statement(<<<'SQL'
            CREATE TABLE audit_log (
                id             UUID         NOT NULL,
                user_id        UUID             NULL,
                event          VARCHAR(64)  NOT NULL,
                entity_type    VARCHAR(64)  NOT NULL,
                entity_id      UUID         NOT NULL,
                old_values     JSONB            NULL,
                new_values     JSONB            NULL,
                ip_address     INET             NULL,
                user_agent     VARCHAR(512)     NULL,
                request_id     VARCHAR(64)      NULL,
                correlation_id VARCHAR(128)     NULL,
                created_at     TIMESTAMPTZ  NOT NULL,
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
        SQL);

        // 128 is D-69's bound on the caller-supplied correlation ID, expressed
        // here so an overlong value cannot reach a permanently retained row.
        // 512 on user_agent is the same idea applied to the other header a
        // caller controls; the writer in Point 6.4 truncates rather than
        // letting an oversized agent string fail a business transaction.

        for ($month = 0; $month < self::SEED_MONTHS; $month++) {
            $this->createMonthPartition(self::monthStart($month));
        }

        // The safety net (2) above.
        DB::statement('CREATE TABLE '.self::TABLE.'_default PARTITION OF '.self::TABLE.' DEFAULT');

        // DATABASE.md's four. Three end in created_at DESC because the
        // questions this table is asked are about recent windows — which is
        // also the argument D-72 used to choose months over years.
        DB::statement('CREATE INDEX audit_log_entity_index
                       ON audit_log (entity_type, entity_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_log_user_index
                       ON audit_log (user_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_log_event_index
                       ON audit_log (event, created_at DESC)');

        // The one lookup with no time bound, so it prunes to nothing and probes
        // every partition. Named in D-72 as an accepted, growing cost rather
        // than left to be rediscovered as a mystery.
        DB::statement('CREATE INDEX audit_log_correlation_index ON audit_log (correlation_id)');
    }

    public function down(): void
    {
        // DEV-03 wants a down path that actually runs, and on a database that
        // has been alive for a while rather than only on a fresh one: by the
        // time anyone rolls this back, J-15 will have created partitions whose
        // names this file never knew.
        //
        // **One statement is enough, and that is measured rather than assumed.**
        // Dropping a partitioned parent drops every partition attached to it —
        // no CASCADE, no enumeration. The first version of this method looped
        // over pg_inherits and dropped each partition first; that loop was dead
        // code, and the test written to protect it passed just as happily
        // without it, which is how the loop was found. Do not add it back: an
        // explicit list works exactly once, on a database nobody has used.
        //
        // The indexes belong to the table and go with it; naming them again
        // would fail on a second rollback.
        DB::statement('DROP TABLE IF EXISTS '.self::TABLE);
    }

    private function createMonthPartition(DateTimeImmutable $start): void
    {
        $end = $start->modify('+1 month');

        // The +00 offset on both literals is load-bearing. created_at is
        // TIMESTAMPTZ, and a bound written without a zone is read in the
        // session's TimeZone — which would shift every month boundary by
        // however far the server happens to sit from UTC, quietly, against
        // DB-08. The partition name is UTC for the same reason.
        DB::statement(sprintf(
            'CREATE TABLE %s_%s PARTITION OF %s FOR VALUES FROM (\'%s\') TO (\'%s\')',
            self::TABLE,
            $start->format('Y_m'),
            self::TABLE,
            $start->format('Y-m-d H:i:sP'),
            $end->format('Y-m-d H:i:sP'),
        ));
    }

    private static function monthStart(int $monthsAhead): DateTimeImmutable
    {
        return Date::now('UTC')->startOfMonth()->addMonths($monthsAhead)->toDateTimeImmutable();
    }
};
