<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 16, Point 1 (`D-153`) — the alerts a person reads under the bell.
 *
 * One row is one alert addressed to one user. `D-153`'s five kinds are the
 * only ones accepted. While an alert is open (`cleared_at` null) there is one
 * per user, kind and record: a repeat raises `occurrences` on that row (§18.3's
 * aggregation). A cleared alert stays as history — no soft delete, because an
 * alert is cleared, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table): void {
            $table->standardId();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('kind', 40);
            // The record the alert is about, as text: a quotation's id, a failed job's uuid; null for
            // storage and backup, which have none.
            $table->string('subject_id', 64)->nullable();
            // What the sentence under the bell needs (a quotation's number and date), kept with the alert.
            $table->jsonb('details')->default('{}');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestampTz('first_raised_at');
            $table->timestampTz('last_raised_at');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('cleared_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_kind_check CHECK (kind IN ('quotation_expiring', 'account_locked', 'job_failed', 'storage_over_limit', 'backup_failed'))");
        DB::statement('ALTER TABLE alerts ADD CONSTRAINT alerts_occurrences_check CHECK (occurrences >= 1)');
        // One open alert per user, kind and record; COALESCE so two record-less alerts collide too.
        DB::statement("CREATE UNIQUE INDEX alerts_one_open ON alerts (user_id, kind, COALESCE(subject_id, '')) WHERE cleared_at IS NULL");
        // The bell's list and count: a user's open alerts, newest first.
        DB::statement('CREATE INDEX alerts_open_by_user ON alerts (user_id, last_raised_at DESC) WHERE cleared_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
