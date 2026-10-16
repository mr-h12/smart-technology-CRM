<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `D-135`: "`limits.daily_report_deadline` (17:00) is removed and its row archived".
 * `DB-01`: archived, not deleted; `down()` brings the latest archived one back.
 */
return new class extends Migration
{
    private const KEY = 'limits.daily_report_deadline';

    public function up(): void
    {
        DB::table('system_limits')->where('key', self::KEY)->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $latest = DB::table('system_limits')->where('key', self::KEY)->whereNotNull('deleted_at')->orderByDesc('deleted_at')->value('id');

        if ($latest !== null && ! DB::table('system_limits')->where('key', self::KEY)->whereNull('deleted_at')->exists()) {
            DB::table('system_limits')->where('id', $latest)->update(['deleted_at' => null, 'updated_at' => now()]);
        }
    }
};
