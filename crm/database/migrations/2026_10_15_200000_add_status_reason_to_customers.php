<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `D-123` (F-50 · 4) — the reason a customer's status was set by hand. `null`
 * while the status is derived (§4.5); its presence is what marks the status as
 * set by hand, so no second flag column is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('status_reason', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('status_reason');
        });
    }
};
