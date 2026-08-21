<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fixture, deliberately not in database/migrations.
 *
 * scopeIndex() broke in exactly one place — inside Schema::create(), which is
 * where every real migration calls it — and a test that calls Schema::create()
 * itself does not prove the migration path works. This is a real migration,
 * run by `php artisan migrate --path`, so the up and down paths DEV-03 requires
 * are the ones actually exercised.
 *
 * It lives outside database/migrations so it can never run against development
 * or production: `migrate` without --path will not see it.
 *
 * Shape mirrors a Module 1+ business table — the standard block from DB-01 and
 * DB-02, an owner column for the §3.2 row scope, and a status the pipeline
 * filters on — because a probe that does not look like the real thing proves
 * something about the probe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scope_index_probes', function (Blueprint $table): void {
            $table->standardColumns();
            $table->uuid('owner_id');
            $table->string('status', 24);

            // DB-09: indexes on owner and status. Both paired with deleted_at,
            // since every scoped list query also excludes soft-deleted rows.
            $table->scopeIndex('owner_id');
            $table->scopeIndex('owner_id', 'status');
        });
    }

    public function down(): void
    {
        // DEV-03: the down path is tested, not assumed. Dropping the table takes
        // its indexes with it — asserted rather than trusted.
        Schema::dropIfExists('scope_index_probes');
    }
};
