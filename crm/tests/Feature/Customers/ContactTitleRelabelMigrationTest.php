<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `D-104` as amended (F-38 · 1.2b): the titles' Arabic labels become «أستاذ» /
 * «أستاذة» so the employee can tell them apart when choosing. 1.2's migration
 * is applied and is not edited; this one relabels, and leaves a label an
 * administrator renamed alone (`ManagedListSeeder`'s rule: a renamed label
 * keeps its new name).
 */
final class ContactTitleRelabelMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_08_200000_relabel_contact_titles.php';

    // ────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(['mr' => 'أستاذ', 'mrs' => 'أستاذة'], self::arabicLabels());

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertSame(['mr' => 'أ.', 'mrs' => 'أ.'], self::arabicLabels(), 'down() did not restore «أ.».');

        self::assertSame(0, Artisan::call('migrate'));

        self::assertSame(['mr' => 'أستاذ', 'mrs' => 'أستاذة'], self::arabicLabels(), 'up() did not come back.');
    }

    public function test_that_an_edited_label_is_left_alone(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        DB::table('enum_lists')->where('list', 'contact_titles')->where('code', 'mr')->update(['label_ar' => 'السيد']);

        self::assertSame(0, Artisan::call('migrate'));

        self::assertSame(['mr' => 'السيد', 'mrs' => 'أستاذة'], self::arabicLabels(), 'up() overwrote a renamed label.');

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertSame(['mr' => 'السيد', 'mrs' => 'أ.'], self::arabicLabels(), 'down() overwrote a renamed label.');
    }

    /** @return array<string, string> code => label_ar */
    private static function arabicLabels(): array
    {
        /** @var array<string, string> */
        return DB::table('enum_lists')
            ->where('list', 'contact_titles')
            ->orderBy('position')
            ->pluck('label_ar', 'code')
            ->all();
    }
}
