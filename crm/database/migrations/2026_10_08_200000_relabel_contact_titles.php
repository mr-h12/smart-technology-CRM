<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `D-104` as amended (F-38 · 1.2b): the titles' Arabic labels become «أستاذ» /
 * «أستاذة», so the two can be told apart when choosing. 1.2's migration is
 * applied and is not edited (`DEV-03`); this one relabels.
 *
 * Only a label still at the value being replaced is touched: a label an
 * administrator renamed keeps its new name, `ManagedListSeeder`'s rule.
 */
return new class extends Migration
{
    private const LABELS = ['mr' => 'أستاذ', 'mrs' => 'أستاذة'];

    private const BEFORE = 'أ.';

    public function up(): void
    {
        foreach (self::LABELS as $code => $label) {
            self::relabel($code, self::BEFORE, $label);
        }
    }

    public function down(): void
    {
        foreach (self::LABELS as $code => $label) {
            self::relabel($code, $label, self::BEFORE);
        }
    }

    private static function relabel(string $code, string $from, string $to): void
    {
        DB::table('enum_lists')
            ->where('list', 'contact_titles')
            ->where('code', $code)
            ->where('label_ar', $from)
            ->update(['label_ar' => $to, 'updated_at' => now()]);
    }
};
