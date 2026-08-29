<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module 3, Point 3.3 — the extension §10.2's fuzzy match runs on.
 *
 * `pg_trgm` is available in `postgres:17.5-bookworm` but **not installed**:
 * checked with `pg_available_extensions` before the migration was written, not
 * assumed from the image name. This proves the migration installs it, that
 * `similarity()` then answers, and that `DEV-03`'s rollback actually works.
 */
final class TrigramExtensionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_30_000000_enable_pg_trgm.php';

    public function test_that_the_extension_is_installed(): void
    {
        self::assertNotNull(
            DB::table('pg_extension')->where('extname', 'pg_trgm')->first(),
            'pg_trgm is not installed, so §10.2 has no similarity function.',
        );
    }

    /** The folding {@see \App\Support\Search\ArabicNormalisation} owns, applied by PostgreSQL. */
    public function test_that_folded_arabic_names_score_as_identical(): void
    {
        $score = DB::scalar(
            'select similarity(translate(?, ?, ?), translate(?, ?, ?))',
            ['احمد للتجاره', 'أإآٱةى', 'اااا'.'هي', 'أحمد للتجارة', 'أإآٱةى', 'اااا'.'هي'],
        );

        // Narrowed, never cast blind: the pgsql driver may hand a `real` back
        // as a numeric string, and `(float) 'oops'` is 0.0 — a perfect score.
        self::assertIsNumeric($score);
        self::assertSame(1.0, round((float) $score, 4),
            'Hamza and taa marbuta must fold away before the score is taken (§10.2).');
    }

    /** Two unrelated company names must sit far below any usable threshold. */
    public function test_that_unrelated_names_score_low(): void
    {
        $score = DB::scalar('select similarity(?, ?)', ['مؤسسة الأمل', 'شركة الوفاء']);

        self::assertIsNumeric($score);
        self::assertLessThan(0.2, (float) $score,
            'Unrelated names scoring high would make every save a duplicate warning.');
    }

    // ────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertNull(
            DB::table('pg_extension')->where('extname', 'pg_trgm')->first(),
            'down() left pg_trgm installed (DEV-03).',
        );

        self::assertSame(0, Artisan::call('migrate'));

        self::assertNotNull(DB::table('pg_extension')->where('extname', 'pg_trgm')->first());
    }
}
