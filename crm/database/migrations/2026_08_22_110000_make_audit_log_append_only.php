<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `AUD-03` and `D-30` — the audit log is immutable and retained permanently,
 * and after this migration the database is what enforces that rather than an
 * intention nobody wrote down.
 *
 * Point 6.1 built the table and left `UPDATE`, `DELETE` and `TRUNCATE` working
 * on it. This is the guard, and it is deliberately the point before the writer
 * (6.4): a guard installed after there is something to protect has already
 * been unnecessary for a while.
 *
 * ── Three placements, each one measured ────────────────────────────────────
 *
 * **`UPDATE` and `DELETE` — one row trigger on the parent.** A row trigger
 * declared on a partitioned table is enforced on its partitions too, including
 * against a statement aimed straight at one, and PostgreSQL clones it onto
 * partitions created *afterwards*. That last part is why `J-15` will not have
 * to re-arm this half every month.
 *
 * **`TRUNCATE` — one statement trigger per relation.** This one does **not**
 * propagate, measured in both directions: the parent's trigger does not cover
 * its partitions, and a partition's does not cover the parent. So every
 * relation gets its own — and a partition created after this migration has
 * none until something arms it. That hole is real, it belongs to `J-15`
 * (Point 6.3), and `AuditLogImmutabilityTest` asserts it rather than trusting
 * this paragraph to be read.
 *
 * **Nothing here stops `DROP TABLE`.** The application connects as a PostgreSQL
 * superuser, so no trigger and no `REVOKE` can hold against it. What this
 * migration closes is the accident and the application bug; the deliberate
 * superuser is closed by giving the application a role that is not one, which
 * is a server task on the deployment-debt register.
 *
 * ── Why a custom SQLSTATE ──────────────────────────────────────────────────
 *
 * `AUD03` rather than PL/pgSQL's default `P0001`. `P0001` is what *every*
 * `RAISE EXCEPTION` in the system will eventually produce, so a caller cannot
 * tell this guard from any other, and a test pinned to it would keep passing
 * once it no longer meant anything. `AUD03` names the requirement, survives
 * every rewording of the message, and reaches PHP intact as
 * `QueryException::getCode()` — verified, not assumed.
 */
return new class extends Migration
{
    private const PARENT = 'audit_log';

    private const FUNCTION = 'audit_log_is_append_only';

    public function up(): void
    {
        // The message names the requirement rather than the row: an audit row
        // holds old and new values of whatever the business changed, and an
        // error text is the last place that should be repeated.
        //
        // The doubled %% is not a typo. plpgsql's RAISE takes % as its own
        // placeholder for TG_OP, and sprintf() reads the same character first —
        // it counted two specifiers, was handed one argument, and the migration
        // died with "3 arguments are required, 2 given" before any SQL ran.
        // CREATE OR REPLACE, and not for tidiness. `migrate:fresh` wipes the
        // schema by dropping tables, and a function is not a table: the
        // triggers die with the tables they were attached to, and this function
        // outlives both. A plain CREATE then fails the *second* time anyone
        // runs the suite — SQLSTATE 42723, `function "audit_log_is_append_only"
        // already exists` — which is how this was found, 137 tests deep.
        // Replacing an identical body loses nothing; the definition lives here
        // and nowhere else.
        DB::statement(sprintf(<<<'SQL'
            CREATE OR REPLACE FUNCTION %s() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'audit_log is append-only: %% is refused (AUD-03, D-30)', TG_OP
                    USING ERRCODE = 'AUD03';
            END;
            $$
        SQL, self::FUNCTION));

        // FOR EACH ROW is what makes this reach the partitions. A statement
        // trigger here would fire only for statements naming the parent, which
        // is the smaller half of the problem.
        DB::statement(sprintf(
            'CREATE TRIGGER audit_log_no_change BEFORE UPDATE OR DELETE ON %s
             FOR EACH ROW EXECUTE FUNCTION %s()',
            self::PARENT,
            self::FUNCTION,
        ));

        // TRUNCATE has no rows to trigger on, so it can only be a statement
        // trigger — and statement triggers do not propagate. One per relation,
        // the parent included.
        foreach (array_merge([self::PARENT], $this->partitions()) as $relation) {
            $this->armTruncateGuard($relation);
        }
    }

    public function down(): void
    {
        // One statement, and this time that is known before it is written
        // rather than discovered afterwards: PostgreSQL refuses to drop a
        // function a trigger depends on — `cannot drop function ... because
        // other objects depend on it` — and CASCADE drops exactly those
        // dependents. Every trigger this migration created depends on this
        // function, so naming them individually would repeat what CASCADE
        // already does and would go stale the moment J-15 adds one more.
        DB::statement('DROP FUNCTION IF EXISTS '.self::FUNCTION.'() CASCADE');
    }

    private function armTruncateGuard(string $relation): void
    {
        DB::statement(sprintf(
            'CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON %s
             FOR EACH STATEMENT EXECUTE FUNCTION %s()',
            $relation,
            self::FUNCTION,
        ));
    }

    /**
     * Every partition currently attached to the parent.
     *
     * Discovered rather than listed, and here that is load-bearing: this
     * migration runs on a database where 6.1 seeded two months and a default,
     * and it will run again on one where J-15 has been adding a month at a
     * time.
     *
     * @return list<string>
     */
    private function partitions(): array
    {
        /** @var list<object{relname: string}> $rows */
        $rows = DB::select(
            'select child.relname
             from pg_inherits
             join pg_class parent on parent.oid = pg_inherits.inhparent
             join pg_class child on child.oid = pg_inherits.inhrelid
             where parent.relname = ?',
            [self::PARENT],
        );

        return array_map(static fn (object $row): string => $row->relname, $rows);
    }
};
