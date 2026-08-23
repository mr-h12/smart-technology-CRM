<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 1's permission storage — `roles`, `permissions`, `role_permissions`.
 *
 * **A permission is a triple, not a pair.** `§3.2` is explicit: *"Permission =
 * Resource + Action + Scope"*, with `customer.view.own` as its own example. So
 * the scope is part of the permission's identity and belongs in `permissions`,
 * not on the link. `role_permissions` then says only which triples a role
 * holds, which is what `SEC-07`'s "dynamic RBAC stored in the database" means:
 * changing what a role may do is an INSERT or a DELETE, never a deployment.
 *
 * The in-memory registry from Point 7.2 groups the matrix the other way — a
 * `Permission` of resource + action carrying a `Grant` per role — because that
 * is the convenient shape for reading `§3.3`…`§3.12` as tables. The two agree:
 * expanding that registry produces **143 distinct triples and 212 links**,
 * measured against `PermissionMatrix::all()` before this file was written.
 *
 * **Every unique constraint here is partial, `WHERE deleted_at IS NULL`.**
 * `DB-01` soft-deletes everything and `D-34` archives rather than deletes, so a
 * plain UNIQUE would let one archived role permanently reserve its slug —
 * nothing could ever take that name again, and the archived row could not be
 * restored alongside a replacement either. Laravel's schema builder has no
 * partial-unique API (`unique()->where()` is silently ignored, the same trap
 * `scopeIndex` documents), so these are raw DDL.
 */
return new class extends Migration
{
    /**
     * `§3.2`'s five scopes. Written as literals because a migration is history
     * and must not change meaning when an enum is edited later; `RbacSchemaMigrationTest`
     * pins this list against `Scope::cases()` so the two cannot drift silently.
     */
    private const SCOPES = ['own', 'team', 'all', 'out', 'asgn'];

    public function up(): void
    {
        // ── roles ────────────────────────────────────────────────────────────
        Schema::create('roles', function (Blueprint $table): void {
            // D-61 UUIDv7 key · DB-02 actor and timestamps · DB-01 soft delete.
            $table->standardColumns();

            // The label a screen shows. Separate from the slug because §3.1's
            // names are English prose and §14.2 requires Arabic too — the
            // display name is translatable, the slug never is.
            $table->string('name', 128);

            // The machine key. `Role`'s own case values are the eight §3.1
            // roles, the longest being `outdoor_supervisor` at 18 characters.
            $table->string('slug', 64);

            // §3.1's eight roles are the system's own and may not be deleted or
            // renamed; anything an administrator adds later may be. The flag is
            // the record of which is which — enforcing it is Module 1's policy
            // work, not a database constraint, because "may not be deleted" is
            // about who is acting rather than about the row.
            $table->boolean('is_system')->default(false);

            $table->string('description', 255)->nullable();
        });

        // §3.12 rule 6 hides Super Admin from every list, which is a query on
        // the slug on effectively every user screen.
        DB::statement('CREATE UNIQUE INDEX roles_slug_unique_alive ON roles (slug) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX roles_name_unique_alive ON roles (name) WHERE deleted_at IS NULL');

        // ── permissions ──────────────────────────────────────────────────────
        Schema::create('permissions', function (Blueprint $table): void {
            $table->standardColumns();

            // Measured against the Point 7.2 registry: the widest resource is 18
            // characters and the widest action 24. 64 leaves room without being
            // unbounded — `DB-04` wants the database to have an opinion.
            $table->string('resource', 64);
            $table->string('action', 64);
            $table->string('scope', 16);

            $table->string('description', 255)->nullable();
        });

        // The identity §3.2 defines. Partial, for the reason in the class note:
        // an archived permission must not reserve its triple forever.
        DB::statement(
            'CREATE UNIQUE INDEX permissions_triple_unique_alive '
            .'ON permissions (resource, action, scope) WHERE deleted_at IS NULL'
        );

        // A scope outside the five is not a narrower permission, it is a
        // permission that no scope check will ever match — which fails open or
        // closed depending on the caller, and silently either way.
        DB::statement(
            'ALTER TABLE permissions ADD CONSTRAINT permissions_scope_check '
            ."CHECK (scope IN ('".implode("', '", self::SCOPES)."'))"
        );

        // ── role_permissions ─────────────────────────────────────────────────
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->standardColumns();

            $table->uuid('role_id');
            $table->uuid('permission_id');

            // ON DELETE CASCADE is safe only because DB-01 forbids physically
            // deleting business data: revoking sets deleted_at and this row
            // stays. The cascade covers what the rules do allow — a repair or a
            // migration — so it cannot leave a grant pointing at nothing.
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();

            // "Which roles hold this permission" — the reverse of the common
            // read, and the question an administrator's permission screen asks.
            // The forward direction is served by the composite index below,
            // whose leading column is role_id.
            $table->scopeIndex('permission_id');
        });

        // A role either holds a permission or does not; granting it twice is a
        // data error, not a stronger grant.
        DB::statement(
            'CREATE UNIQUE INDEX role_permissions_pair_unique_alive '
            .'ON role_permissions (role_id, permission_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // DEV-03 wants a down path that actually runs, and CI runs it: migrate
        // → reset → migrate before every suite.
        //
        // The link table first — it holds foreign keys into both others, and
        // dropping a referenced table while a constraint points at it fails.
        // Every index and CHECK created above belongs to one of these three
        // tables and is dropped with it; naming them again here would fail on
        // the second rollback rather than being tidy.
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
