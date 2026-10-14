<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-48 · 2 — `D-118`: "the system setting `defaults.tax_percent` is removed".
 * `DB-01`: the stored row is archived, not deleted; `down()` brings the latest
 * archived one back. The settings cache needs no flush: the repository lists
 * only `SystemSetting`'s cases, and the case is gone.
 */
return new class extends Migration
{
    private const KEY = 'defaults.tax_percent';

    public function up(): void
    {
        DB::table('settings')->where('key', self::KEY)->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $latest = DB::table('settings')->where('key', self::KEY)->whereNotNull('deleted_at')->orderByDesc('deleted_at')->value('id');

        if ($latest !== null && ! DB::table('settings')->where('key', self::KEY)->whereNull('deleted_at')->exists()) {
            DB::table('settings')->where('id', $latest)->update(['deleted_at' => null, 'updated_at' => now()]);
        }
    }
};
