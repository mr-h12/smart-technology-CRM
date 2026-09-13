<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            // The owner's Q2 ruling (2026-09-12, Module 7 Point 4.2): the moment
            // a quotation entered `pending`, which `D-11`'s "days waiting" needs
            // and §6.2's Tracking group does not list. The audit row carries the
            // same instant, but a list screen cannot read a partitioned audit
            // table per row. Nullable: a draft has not been submitted, and a
            // returned one (§6.4 `pending → draft`) is no longer waiting.
            // `timestampTz` as `sent_at` is — `DB-08`, an instant in UTC.
            $table->timestampTz('submitted_at')->nullable();
        });
    }

    public function down(): void
    {
        // `DEV-03`: dropping the column returns `quotations` to Step 1's shape.
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn('submitted_at');
        });
    }
};
