<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 1's `users`, and the active-device list `SEC-05` asks for.
 *
 * **The scaffolded table is replaced, not altered.** `design/DATABASE.md §4`
 * says so in as many words — *"Laravel's default `users` table meets none of
 * this… It is replaced in Module 1, not extended"* — and the reason is its key:
 * `$table->id()` is a bigint auto-increment, while `D-61` fixes UUIDv7 and
 * `standardActorForeignKeys()` is waiting to point `created_by` at a `uuid`.
 * That is a column type, so there is no ALTER that reconciles them.
 *
 * **This is a correcting migration, not an edit.** `DEV-03` and `CLAUDE.md`
 * forbid touching a migration that has already run, so `0001_01_01_000000`
 * stays exactly as it is and this one drops what it made and builds the real
 * thing. `down()` puts the scaffold back, which is what makes the pair
 * reversible rather than merely forward-compatible.
 *
 * **`user_sessions` is a device list, not a session store.** `SEC-05` reads
 * "8-hour session timeout + **active device list** + force logout", and §14.2
 * puts the session driver on Redis — `SESSION_DRIVER=redis` in both `.env` and
 * `.env.example`, checked. So the framework's session payload lives in Redis
 * and this table records *which devices are signed in*, so they can be listed
 * and revoked. It deliberately carries **no `payload` column**: a payload here
 * would either be dead weight or a second copy of state Redis already owns,
 * and the second is worse than the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The scaffold. `password_reset_tokens` and `sessions` come from the
        // same migration and are deliberately left alone: neither is in this
        // point's scope, dropping them is its own decision, and `sessions` in
        // particular is inert only because the driver is Redis. Both are
        // recorded on the debt register instead of being removed in passing.
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            // D-61 UUIDv7 key · DB-02 actor and timestamps · DB-01 soft delete.
            $table->standardColumns();

            $table->string('name', 255);
            $table->string('email', 255);

            // SEC-02: Argon2 or bcrypt. 255 holds either — bcrypt is 60 and
            // Argon2id runs to roughly 100 — with room for a future cost or
            // algorithm change, which is the whole reason not to size it tight.
            $table->string('password', 255);

            // §3.1 gives every user exactly one role, so this is NOT NULL.
            $table->uuid('role_id');

            // D-34: accounts are deactivated, never deleted, and the deals stay
            // attached. `is_active` is that switch; `deleted_at` is archival.
            // They are different states and both are needed — a deactivated
            // user must still be visible to the Team Leader who reassigns their
            // work, which a soft delete would hide.
            $table->boolean('is_active')->default(true);

            // §3.12 rule 6: "The Super Admin is hidden — never listed in any
            // user list, for any role." A row-level flag rather than a filter
            // on the role slug, so the rule survives a role being renamed.
            $table->boolean('is_hidden')->default(false);

            // SEC-03: lock after 5 failures. smallInteger rather than integer —
            // the counter resets on success and the threshold is 5.
            $table->smallInteger('failed_login_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();

            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();

            // RESTRICT, emphatically not CASCADE. Cascading here would mean
            // deleting a role deletes its people, which is the exact opposite
            // of D-34 — accounts are never deleted, and a role that still has
            // holders must not be removable until they are moved.
            //
            // Scoped index: every user list filters live rows, and §3.2 gives
            // most roles a scope that starts from "who am I".
            $table->scopeIndex('role_id');

            // §3.12 rule 6 again — the hidden Super Admin is excluded from
            // every user list, so the exclusion is on the hot path.
            $table->scopeIndex('is_hidden');
        });

        // D-34 + DB-01: an archived account must release its address, or the
        // person can never be re-created and the row can never be restored
        // beside a replacement. Same reasoning, same shape, as Point 1.1.
        DB::statement('CREATE UNIQUE INDEX users_email_unique_alive ON users (email) WHERE deleted_at IS NULL');

        // SEC-03's counter is a count; a negative one is not a smaller number
        // of failures, it is a lockout that can never trigger.
        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_failed_login_attempts_check '
            .'CHECK (failed_login_attempts >= 0)'
        );

        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->standardColumns();

            $table->uuid('user_id');

            // The framework's session identifier, which is what lets a row here
            // be matched to the session Redis holds and actually revoked.
            // Without it the device list can be displayed and not acted on.
            $table->string('session_id', 255);

            // 45 characters is the widest textual IPv6 — an IPv4-mapped address
            // such as ::ffff:255.255.255.255 — so this holds either family.
            // Nullable because a request behind a misconfigured proxy has none
            // that can be trusted, and a fabricated address in an audit-adjacent
            // table is worse than an absent one.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // D-29: eight hours idle. A timestamptz rather than Laravel's epoch
            // integer, because DB-08 puts every timestamp in UTC as a real type
            // and the nightly sweep compares it against a real interval.
            $table->timestampTz('last_activity_at');

            // Deleting a user physically is forbidden by DB-01, so this cascade
            // is for the repairs and migrations the rules do allow — it cannot
            // leave a session pointing at nobody.
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // "Which devices is this person signed in on" — SEC-05's screen.
            $table->scopeIndex('user_id');

            // The D-29 sweep walks by age across all users.
            $table->index('last_activity_at');
        });

        // One live row per framework session. Revoking sets deleted_at, and the
        // same identifier may legitimately appear again afterwards.
        DB::statement(
            'CREATE UNIQUE INDEX user_sessions_session_id_unique_alive '
            .'ON user_sessions (session_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // DEV-03, and CI runs it: migrate → reset → migrate before every suite.
        //
        // Sessions first — they hold the foreign key into users, and dropping a
        // referenced table while a constraint points at it fails. Indexes and
        // CHECKs belong to their tables and go with them.
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('users');

        // Put the scaffold back exactly as 0001_01_01_000000 left it. Without
        // this, rolling back past this migration and forward again would hit
        // that migration's own down() dropping a table nothing had created,
        // and would leave the sequence unreversible in the middle.
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
};
