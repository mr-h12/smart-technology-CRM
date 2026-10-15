<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-50 · 2 — `D-121`: a customer carries five optional links (the location,
 * the website, three free social links). Nullable, so every existing customer
 * stays valid; 2048 is `D-121`'s ceiling. No index: nothing lists or filters
 * customers by a link.
 */
return new class extends Migration
{
    /** Spelled out, not read from `CustomerDraft::LINKS`: an applied migration never changes. */
    private const COLUMNS = ['location_url', 'website_url', 'social_url_1', 'social_url_2', 'social_url_3'];

    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->string($column, 2048)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
