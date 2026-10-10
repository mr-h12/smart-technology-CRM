<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-40 · 1.3 — `D-107` ruling (2): the signer's job title, an optional field
 * on the user in Arabic and English. Nullable, so every existing account stays
 * valid; the customer PDF prints it under the signer's name (F-40 · 1.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('job_title_en', 255)->nullable();
            $table->string('job_title_ar', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['job_title_en', 'job_title_ar']);
        });
    }
};
