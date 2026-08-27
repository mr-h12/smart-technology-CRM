<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module 2, Point 2.1 — §5.3's currencies as rows, and the domain reading them.
 *
 * **The units are read out of §5.3, not out of `Currencies`.** That table is
 * the source both the seeder and this test are meant to agree with, and a test
 * that compared the seeder against the class the seeder loads would be the
 * fourth defect on this project's list: self-consistent, and blind to the two
 * of them drifting from the document together.
 *
 * **The seeder creates and does not overwrite.** `IdempotentSeeder` spells out
 * what repeatable means — "must not duplicate a row, bump a counter, or
 * overwrite an edit somebody made deliberately" — and §5.3 makes the unit
 * *editable under System Settings*. A second run that reset an administrator's
 * unit would undo a documented action, which is why the assertion below changes
 * a unit and requires it to survive.
 *
 * **The repository must read the table.** `AP-08` and §3.12 rule 5 are the same
 * argument as `PermissionRepositoryInterface`'s: configuration a class
 * re-derives from code is configuration that does not work. So one test edits
 * the row and requires the repository to report the edit.
 */
final class CurrencySeedingTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    /**
     * §5.3's table, as the document writes it: `| EGP | 1 pound |`.
     *
     * @return array<string, numeric-string>
     */
    private function documentedUnits(): array
    {
        self::assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted. This fails rather than skips: the units below '
            .'would otherwise be compared against the class that produced them.',
        );

        $units = [];

        foreach (file(self::MASTER_DOCUMENTATION) ?: [] as $line) {
            if (preg_match('/^\|\s*(EGP|USD|EUR)\s*\|\s*([0-9.]+)\s/u', $line, $found) === 1) {
                // Narrowed rather than asserted: `assertTrue(is_numeric(...))`
                // reads the same to a human and tells PHPStan nothing, and the
                // string reaches bccomp, which does not accept "probably".
                if (! is_numeric($found[2])) {
                    self::fail("§5.3 gives {$found[1]} a unit that is not a number: {$found[2]}.");
                }

                $units[$found[1]] = $found[2];
            }
        }

        self::assertCount(3, $units, '§5.3 no longer states one unit for each of the three currencies.');

        return $units;
    }

    public function test_that_seeding_creates_every_currency_the_domain_declares(): void
    {
        $this->seed(CurrencySeeder::class);

        $stored = DB::table('currencies')->orderBy('code')->pluck('code')->all();
        $declared = array_map(static fn (CurrencyCode $c): string => $c->value, CurrencyCode::cases());
        sort($declared);

        self::assertSame($declared, $stored);
    }

    /** The module's acceptance criterion: EGP `1` · USD `0.01` · EUR `0.01`. */
    public function test_that_the_rounding_units_are_the_ones_section_5_3_publishes(): void
    {
        $this->seed(CurrencySeeder::class);

        foreach ($this->documentedUnits() as $code => $unit) {
            self::assertSame(
                true,
                DB::scalar('select rounding_unit = ? from currencies where code = ?', [$unit, $code]),
                "{$code} was not seeded with §5.3's unit of {$unit}.",
            );
        }
    }

    /** `D-65` makes rounding optional; §5.3 describes switching it *off*, so on is the start. */
    public function test_that_rounding_starts_switched_on(): void
    {
        $this->seed(CurrencySeeder::class);

        self::assertSame(0, DB::table('currencies')->where('rounding_enabled', false)->count());
    }

    public function test_that_exactly_one_currency_is_the_base_and_it_is_the_egyptian_pound(): void
    {
        $this->seed(CurrencySeeder::class);

        self::assertSame(
            ['EGP'],
            DB::table('currencies')->where('is_base', true)->pluck('code')->all(),
        );
    }

    public function test_that_a_second_run_changes_nothing(): void
    {
        $this->seed(CurrencySeeder::class);
        $first = DB::table('currencies')->orderBy('code')->get(['id', 'created_at'])->toArray();

        $this->seed(CurrencySeeder::class);
        $second = DB::table('currencies')->orderBy('code')->get(['id', 'created_at'])->toArray();

        self::assertEquals($first, $second, 'The second run re-created rows rather than leaving them alone.');
        self::assertSame(3, DB::table('currencies')->count());
    }

    /** `IdempotentSeeder`: a second run may not overwrite a deliberate edit. */
    public function test_that_a_second_run_keeps_an_administrators_edit(): void
    {
        $this->seed(CurrencySeeder::class);

        DB::table('currencies')->where('code', 'USD')->update([
            'rounding_unit' => '0.05',
            'rounding_enabled' => false,
        ]);

        $this->seed(CurrencySeeder::class);

        self::assertSame(true, DB::scalar("select rounding_unit = 0.05 from currencies where code = 'USD'"));
        self::assertSame(false, DB::scalar("select rounding_enabled from currencies where code = 'USD'"));
    }

    /** `DEV-08`: currencies belong in production, unlike the test users. */
    public function test_that_the_seeder_is_not_test_data(): void
    {
        self::assertFalse(app(CurrencySeeder::class)->seedsTestData());
    }

    // ─────────────────────────────────────────────────────── the read path

    public function test_that_the_repository_returns_what_was_seeded(): void
    {
        $this->seed(CurrencySeeder::class);

        $currencies = app(CurrencyRepositoryInterface::class)->all();

        self::assertCount(3, $currencies);

        $documented = $this->documentedUnits();

        foreach ($currencies as $currency) {
            $code = $currency->code()->value;
            $unit = $documented[$code] ?? null;

            if ($unit === null) {
                self::fail("The repository offers {$code}, which §5.3 does not publish a unit for.");
            }

            self::assertSame(
                0,
                bccomp($unit, $currency->rounding()->unit(), 8),
                "The repository reports the wrong unit for {$code}.",
            );
        }
    }

    public function test_that_the_repository_answers_which_currency_is_the_base(): void
    {
        $this->seed(CurrencySeeder::class);

        self::assertSame(CurrencyCode::Egp, app(CurrencyRepositoryInterface::class)->base()?->code());
    }

    /**
     * `AP-08`. If the repository consulted `Currencies` it would pass every
     * test above and still be wrong: an administrator's change would not exist.
     */
    public function test_that_the_repository_reads_the_table_and_not_the_class(): void
    {
        $this->seed(CurrencySeeder::class);

        DB::table('currencies')->where('code', 'EUR')->update([
            'rounding_unit' => '0.25',
            'rounding_enabled' => false,
        ]);

        $eur = null;
        foreach (app(CurrencyRepositoryInterface::class)->all() as $currency) {
            if ($currency->code() === CurrencyCode::Eur) {
                $eur = $currency;
            }
        }

        self::assertNotNull($eur);
        self::assertSame(0, bccomp('0.25', $eur->rounding()->unit(), 8));
        self::assertFalse($eur->rounding()->isEnabled());
    }

    /** An archived currency is not a currency the system offers. */
    public function test_that_the_repository_skips_archived_rows(): void
    {
        $this->seed(CurrencySeeder::class);

        DB::table('currencies')->where('code', 'EUR')->update(['deleted_at' => now()]);

        self::assertCount(2, app(CurrencyRepositoryInterface::class)->all());
    }
}
