<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Module 16 · 6 (`D-153`) — a job-failed alert's subject is the job's class name,
 * and `RaiseQuotationExpiryAlertsJob`'s is 68 characters: 64 lost that alert (the
 * insert failed; the listener logged it). 255, the length a class name is held to
 * everywhere else in this schema. The partial unique index stays as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE alerts ALTER COLUMN subject_id TYPE varchar(255)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE alerts ALTER COLUMN subject_id TYPE varchar(64)');
    }
};
