<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `roles.name_ar` — the Arabic display label a custom role may carry.
 *
 * ── Why the table needed a second label column ─────────────────────────────
 *
 * `2026_08_23_000000_create_rbac_tables` gave `roles` one display column and
 * said why in its own comment: *"§3.1's names are English prose and §14.2
 * requires Arabic too — the display name is translatable, the slug never is."*
 * It then shipped the English half and left the Arabic half unbuilt, because
 * §3.1's eight roles are seeded from `Role::label()` and the documentation
 * gives no Arabic table to seed from.
 *
 * §3.12 rule 5 changes the shape of that gap. A ninth role is a configuration
 * change made by an administrator at runtime, so its label is **data**, not
 * documentation — there is nobody to ask for a translation later and no lang
 * file it could live in. `CLAUDE.md` requires every screen to work in Arabic
 * and English, so the person creating the role is the only one who can supply
 * both, and the column is where their answer goes.
 *
 * ── Nullable, and why that is not a loophole ───────────────────────────────
 *
 * The eight seeded roles get `NULL` and keep rendering their English name in
 * both languages, exactly as they do today. Inventing Arabic names for them
 * here would be transcribing §3.1 into a language the master documentation does
 * not contain — a new requirement wearing a migration as a costume. The gap is
 * unchanged by this file and recorded in `CHECKLIST.md` instead.
 *
 * The unique index is partial for the reason the original migration gives: a
 * plain UNIQUE would let one archived role reserve a label forever, and `DB-01`
 * archives rather than deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            // Same width as `name`. Arabic is stored as UTF-8 and PostgreSQL
            // counts `varchar` in characters, not bytes, so the two columns
            // hold the same number of letters.
            $table->string('name_ar', 128)->nullable()->after('name');
        });

        // Partial, and NULL-tolerant: PostgreSQL treats NULLs as distinct in a
        // unique index, so the eight seeded roles can all carry NULL together.
        DB::statement(
            'CREATE UNIQUE INDEX roles_name_ar_unique_alive ON roles (name_ar) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // DEV-03 wants a down path that actually runs, and CI runs it.
        // The index is dropped with the column it covers, but naming it makes
        // the rollback readable rather than relying on that.
        DB::statement('DROP INDEX IF EXISTS roles_name_ar_unique_alive');

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('name_ar');
        });
    }
};
