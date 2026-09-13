<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7, Point 3.7 — `idempotency_keys`, the store `OpenAPI §9.1` names.
 *
 * §9.1: "Persist the actor, route, key, request hash, final status, and
 * response." Six facts, six columns — `user_id`, `route`, `key`,
 * `request_hash`, `status`, `response` — and nothing the sentence does not
 * name. The key is `(user_id, route, key)` because §9.1 replays on "the same
 * actor + route + key"; the UNIQUE is what makes a concurrent duplicate lose
 * at the database rather than in application code.
 *
 * ── A row is claimed before the work and completed after it ────────────────
 *
 * `status` and `response` are nullable together: a row with neither is a
 * request in flight, claimed so that a second copy of the same request cannot
 * run beside it. The CHECK keeps the two from disagreeing — a status without
 * a body, or a body without a status, is a row nothing can replay.
 *
 * ── Not a business table ───────────────────────────────────────────────────
 *
 * No `standardColumns()`, on `user_sessions` and `audit_log`'s terms: `DB-01`
 * and `DB-02` describe rows that change and are retired, and this one is
 * written once, read by equality, and never edited by a person. `created_at`
 * is kept for the purge §9.1's "defined retention period" implies — a period
 * no decision has yet defined, so nothing here expires (recorded in
 * `CHECKLIST.md`'s debt register, not invented as a constant).
 *
 * `user_id` cascades on `users` delete exactly as `user_sessions` does: `DB-01`
 * forbids deleting a user, and this keeps the repairs it allows from leaving
 * a key pointing at nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('route', 255);
            $table->string('key', 255);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status')->nullable();
            $table->jsonb('response')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'route', 'key']);
        });

        DB::statement(
            'ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_completed_together '
            .'CHECK ((status IS NULL) = (response IS NULL))'
        );
    }

    public function down(): void
    {
        // DEV-03. The CHECK, the UNIQUE and the foreign key go with the table.
        Schema::dropIfExists('idempotency_keys');
    }
};
