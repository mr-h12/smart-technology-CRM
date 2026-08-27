<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2's configuration storage — `settings` and `system_limits`.
 *
 * **Key/value, because `AP-08` is "config over code" and `D-75` is the proof.**
 * `identity.lockout_minutes` lives in `config/identity.php` today and `D-75`
 * says Module 2 moves it here "where an administrator changes it without a
 * deployment". A wide table with one column per setting would need a migration
 * for every new key, which is the deployment `D-75` exists to avoid. §3.12
 * rule 5 makes the same point about the permission matrix.
 *
 * **Two tables, because §3.11 lists them as two permissions.** `system
 * settings` and `system limits (SLAs, thresholds)` are separate rows there and
 * separate screens in §13 — 4 and 6. Both are Super Admin only today, and a
 * matrix row may be regranted without a deployment, so the split is what keeps
 * the authorisation check at the table instead of inside a `WHERE`.
 *
 * **`value` is text and never a numeric type.** `DB-07` forbids float anywhere
 * near money, and the moment one limit wants a fraction, a `double precision`
 * column would be the obvious and wrong reach. Text plus a declared
 * `value_type` keeps the type in the row and lets BCMath read the string as
 * `Decimal` does everywhere else. `SettingsSchemaMigrationTest` asserts that
 * neither table has a float column at all.
 *
 * **The uniqueness is partial, `WHERE deleted_at IS NULL`**, for the reason
 * `create_rbac_tables` records: `DB-01` soft-deletes, so a plain UNIQUE would
 * let one archived row reserve its key permanently. Laravel's schema builder
 * silently ignores `unique()->where()`, so this is raw DDL.
 */
return new class extends Migration
{
    /**
     * How the string in `value` is to be read. Written as literals because a
     * migration is history and must not change meaning when an enum is edited
     * later — the same reasoning as `create_rbac_tables`'s scope list. Point
     * 2.3 introduces the enum and pins it against these values.
     *
     * `decimal` rather than `float` is not a naming preference: `DB-07`.
     */
    private const VALUE_TYPES = ['string', 'integer', 'decimal', 'boolean', 'json'];

    public function up(): void
    {
        foreach (['settings' => false, 'system_limits' => true] as $table => $carriesUnit) {
            Schema::create($table, function (Blueprint $blueprint) use ($carriesUnit): void {
                // D-61 UUIDv7 key · DB-02 actor and timestamps · DB-01 soft delete.
                $blueprint->standardColumns();

                // Dotted namespace — `company.name`, `identity.lockout_minutes`
                // (D-75). 128 is the roles table's name length; no documented
                // key approaches it.
                $blueprint->string('key', 128);

                // Nullable: §13 screen 4 lists a logo and a PDF template among
                // the settings, and "not configured yet" is a real state that
                // must not be confused with the empty string.
                $blueprint->text('value')->nullable();

                $blueprint->string('value_type', 16);

                if ($carriesUnit) {
                    // §13 screen 6 mixes days, hours and megabytes in one
                    // screen — a threshold in days and an SLA in hours cannot
                    // be compared, or rendered, without knowing which is which.
                    $blueprint->string('unit', 16)->nullable();
                }
            });

            $types = "'".implode("', '", self::VALUE_TYPES)."'";

            DB::statement("CREATE UNIQUE INDEX {$table}_key_unique_alive ON {$table} (key) WHERE deleted_at IS NULL");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_value_type_declared CHECK (value_type IN ({$types}))");

            // A blank key is not a key. Without this the partial unique index
            // would happily hold one '' row, and every screen would show a
            // nameless setting nobody can address.
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_key_not_blank CHECK (btrim(key) <> '')");
        }
    }

    public function down(): void
    {
        // DEV-03: rollback must actually work. The constraints and the index go
        // with the table; nothing here outlives it.
        Schema::dropIfExists('system_limits');
        Schema::dropIfExists('settings');
    }
};
