<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `SEC-10` — "Login As restricted to Super Admin, **with mandatory logging**".
 *
 * Two columns, one on each side of what that sentence needs.
 *
 * ── `user_sessions.impersonator_id` ────────────────────────────────────────
 *
 * An impersonation session belongs to the person being impersonated — it
 * carries their role and their permissions, which is the whole point — while
 * the human at the keyboard is somebody else. Without a column for that second
 * identity there is nowhere to keep it: the bearer token is a digest, and
 * putting the answer in a cache entry would mean a session whose accountability
 * expires separately from the session itself.
 *
 * ── `audit_log.impersonated_user_id` ───────────────────────────────────────
 *
 * `AUD-02` records "user · event · entity · …" on the assumption that there is
 * one user. During impersonation there are two, and both matter: the Super
 * Admin is who did it, and the impersonated account is what it was done as.
 * `user_id` keeps the first — it is the actor, and §3.12 rule 4 makes Login As
 * a mandatory entry precisely so the real person is named — and this column
 * keeps the second.
 *
 * A **column** rather than a key inside `new_values`, because `new_values`
 * describes the operation and this is ambient context, the same distinction
 * `AuditContext` already draws for IP and user agent. It is also the shape the
 * question actually takes: "what was done while impersonating this employee" is
 * a `WHERE`, not a JSON scan across a partitioned table.
 *
 * ── Adding a column to a partitioned table ─────────────────────────────────
 *
 * `ALTER TABLE … ADD COLUMN` on the parent propagates to every existing
 * partition and to every future one (`D-72` creates them from the parent), and
 * the `AUD-03` append-only trigger guards `UPDATE`/`DELETE`/`TRUNCATE` on rows
 * rather than DDL, so it does not block this. Both facts were checked against
 * this database rather than recalled.
 *
 * **No foreign key on `audit_log`.** `D-72` records that `user_id` carries none
 * either — the audit table is not a business table and a constraint there would
 * couple a permanent log to the lifetime of rows it outlives. This column
 * follows the column it sits beside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_sessions', function (Blueprint $table): void {
            // No `after()`: it is a MySQL hint and PostgreSQL ignores it, so
            // the column is appended whatever this says. Measured against this
            // database rather than recalled — writing it would have been a
            // comment that lies about the resulting column order.
            $table->uuid('impersonator_id')->nullable();

            // CASCADE, matching `user_id` on the same table and for the same
            // reason: DB-01 forbids deleting a user, so this is only reachable
            // by the repairs and migrations the rules do allow — and a session
            // pointing at an impersonator who no longer exists is worse than no
            // session at all.
            $table->foreign('impersonator_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // "Is this Super Admin currently impersonating anybody" — the question
        // the start endpoint asks to refuse nesting, and the only one that
        // reads this column as a filter. Partial, because the overwhelming
        // majority of sessions are not impersonations and an index that holds
        // a million NULLs to find three rows is paid for on every login.
        DB::statement(
            'CREATE INDEX user_sessions_impersonator_index ON user_sessions (impersonator_id) '
            .'WHERE impersonator_id IS NOT NULL AND deleted_at IS NULL'
        );

        DB::statement('ALTER TABLE audit_log ADD COLUMN impersonated_user_id uuid');

        // Declared on the parent so it reaches every partition, existing and
        // future — the arrangement the audit_log migration already relies on.
        DB::statement(
            'CREATE INDEX audit_log_impersonated_index ON audit_log (impersonated_user_id, created_at DESC)'
        );
    }

    public function down(): void
    {
        // DEV-03, and CI runs it: migrate → reset → migrate before every suite.
        DB::statement('DROP INDEX IF EXISTS audit_log_impersonated_index');
        DB::statement('ALTER TABLE audit_log DROP COLUMN IF EXISTS impersonated_user_id');

        DB::statement('DROP INDEX IF EXISTS user_sessions_impersonator_index');

        Schema::table('user_sessions', function (Blueprint $table): void {
            // The constraint is named by convention; dropping the foreign key
            // before the column, because PostgreSQL refuses to drop a column a
            // live constraint depends on.
            $table->dropForeign(['impersonator_id']);
            $table->dropColumn('impersonator_id');
        });
    }
};
