<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 1.2 — §5.2's money identities as database constraints.
 *
 * Every row here is written **raw**, bypassing any application code, because
 * the claim under test is that the *database* refuses arithmetic §5 forbids.
 * A test that went through a use case would be testing the use case.
 *
 * The amounts are carried at full scale-6 precision, not at the four decimals
 * §5.2 prints. The worked example displays `8,315.9988`; the stored value is
 * `8315.998812`, and `D-68` is explicit that "only the persisted snapshot is
 * at scale 6" while the arithmetic above it runs wider. Rounding the fixture
 * to match the printed figure would be inventing a precision the decision
 * forbids.
 */
final class QuotationMoneyInvariantTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK_VIOLATION = '23514';

    private string $customerId;

    private string $dealId;

    private string $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerId = Uuid::uuid4()->toString();
        $this->dealId = Uuid::uuid4()->toString();
        $this->currencyId = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $this->customerId,
            'name' => 'Nile Trading',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('currencies')->insert([
            'id' => $this->currencyId,
            'code' => 'EGP',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'is_base' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deals')->insert([
            'id' => $this->dealId,
            'code' => 'DL-2026-0001',
            'customer_id' => $this->customerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ──────────────────────────────────────────────── §5.2's worked example

    /**
     * §5.2: "subtotal 7,368.42 · discount 1% → 73.6842 · tax base 7,294.7358 ·
     * tax 14% → 1,021.2630 · total_before_round 8,315.9988 → final_total 8,316
     * with EGP rounding on (unit 1)".
     */
    public function test_that_the_section_5_2_worked_example_is_storable(): void
    {
        $this->insert([
            'subtotal' => '7368.420000',
            'additional_total' => '0',
            'discount_percent' => '1',
            'discount_amount' => '73.684200',
            'tax_base' => '7294.735800',
            'tax_percent' => '14',
            'tax_amount' => '1021.263012',
            'net_amount' => '7294.735800',
            'total_before_round' => '8315.998812',
            'final_total' => '8316.000000',
            'rounding_diff' => '0.001188',
        ]);

        self::assertSame('8316.000000', $this->storedValue('final_total'));
    }

    /** §5.2's other half: "or 8,315.9988 with rounding off (`D-65`)". */
    public function test_that_the_same_example_with_rounding_off_keeps_full_precision(): void
    {
        $this->insert([
            'subtotal' => '7368.420000',
            'discount_amount' => '73.684200',
            'tax_base' => '7294.735800',
            'tax_percent' => '14',
            'tax_amount' => '1021.263012',
            'net_amount' => '7294.735800',
            'total_before_round' => '8315.998812',
            'final_total' => '8315.998812',
            'rounding_diff' => '0',
            'rounding_enabled' => false,
        ]);

        self::assertSame('8315.998812', $this->storedValue('final_total'));
    }

    // ────────────────────────────── `D-64` and `D-62`, the criterion that matters

    /**
     * "Items 10,000 + delivery 1,000, discount 1%, tax 14% → tax base 9,900,
     * tax 1,386" — discount first (`D-64`), delivery outside the base (`D-62`).
     */
    public function test_that_delivery_stays_outside_the_tax_base(): void
    {
        $this->insert(self::TEN_THOUSAND_PLUS_DELIVERY);

        self::assertSame('9900.000000', $this->storedValue('tax_base'));
        self::assertSame('1386.000000', $this->storedValue('tax_amount'));
    }

    /**
     * The same figures with delivery folded into the base — 10,000 + 1,000 −
     * 100 = 10,900 — which is the overcharge `OD-01` was opened to prevent.
     * The database, not a test assertion, is what refuses it.
     */
    public function test_that_a_tax_base_including_delivery_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(array_merge(self::TEN_THOUSAND_PLUS_DELIVERY, [
                'tax_base' => '10900.000000',
                'tax_amount' => '1526.000000',
                'total_before_round' => '12426.000000',
                'final_total' => '12426.000000',
            ]))),
        );
    }

    /** `D-64`: tax before discount is the ordering the PO used and `D-64` overruled. */
    public function test_that_a_tax_base_ignoring_the_discount_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(array_merge(self::TEN_THOUSAND_PLUS_DELIVERY, [
                'tax_base' => '10000.000000',
            ]))),
        );
    }

    // ─────────────────────────────────────────────── each identity, broken once

    public function test_that_a_net_amount_omitting_additional_items_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(array_merge(self::TEN_THOUSAND_PLUS_DELIVERY, [
                'net_amount' => '9900.000000',
            ]))),
        );
    }

    public function test_that_a_total_omitting_the_tax_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(array_merge(self::TEN_THOUSAND_PLUS_DELIVERY, [
                'total_before_round' => '10900.000000',
            ]))),
        );
    }

    public function test_that_a_final_total_not_explained_by_rounding_diff_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(array_merge(self::TEN_THOUSAND_PLUS_DELIVERY, [
                'final_total' => '12290.000000',
                'rounding_diff' => '0',
            ]))),
        );
    }

    // ────────────────────────────────────────────── the rounding acceptance rows

    /** "Rounding on: total 1234.67 EGP → final total 1235, rounding_diff 0.33". */
    public function test_that_the_egp_rounding_row_is_storable(): void
    {
        $this->totalling('1234.670000', '1235.000000', '0.330000');

        self::assertSame('1235.000000', $this->storedValue('final_total'));
        self::assertSame('0.330000', $this->storedValue('rounding_diff'));
    }

    /** "Rounding on: total 1234.678 USD → final total 1234.68 (rounding unit 0.01)". */
    public function test_that_the_usd_rounding_row_is_storable(): void
    {
        $this->totalling('1234.678000', '1234.680000', '0.002000', ['rounding_unit' => '0.01']);

        self::assertSame('1234.680000', $this->storedValue('final_total'));
    }

    /** Rounding down: the difference is negative, and nothing forbids that. */
    public function test_that_rounding_down_records_a_negative_difference(): void
    {
        $this->totalling('1234.230000', '1234.000000', '-0.230000');

        self::assertSame('-0.230000', $this->storedValue('rounding_diff'));
    }

    /** "Rounding off for the currency → full precision, rounding_diff 0 (`D-65`)". */
    public function test_that_rounding_off_keeps_full_precision(): void
    {
        $this->totalling('1234.678000', '1234.678000', '0', ['rounding_enabled' => false]);

        self::assertSame('1234.678000', $this->storedValue('final_total'));
    }

    /** `D-65`: with rounding off there is no difference to record. */
    public function test_that_a_difference_with_rounding_off_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->totalling(
                '1234.678000',
                '1235.000000',
                '0.322000',
                ['rounding_enabled' => false],
            )),
        );
    }

    // ──────────────────────────────────────────────────────── `D-63`'s no-tax

    /** An exempt quotation: no percentage, no amount, and NULL adds nothing. */
    public function test_that_an_exempt_quotation_needs_no_tax_line(): void
    {
        $this->insert([
            'subtotal' => '10000.000000',
            'additional_total' => '1000.000000',
            'discount_percent' => '1',
            'discount_amount' => '100.000000',
            'tax_base' => '9900.000000',
            'tax_percent' => null,
            'tax_amount' => null,
            'net_amount' => '10900.000000',
            'total_before_round' => '10900.000000',
            'final_total' => '10900.000000',
            'rounding_diff' => '0',
        ]);

        self::assertNull(DB::table('quotations')->value('tax_amount'));
    }

    /**
     * `COALESCE`, not a bare `+`. A bare `+` makes the whole comparison NULL
     * for an exempt row, and PostgreSQL passes a CHECK that evaluates to NULL
     * — so every exempt quotation would slip through unchecked.
     */
    public function test_that_an_exempt_quotation_is_still_held_to_its_total(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert([
                'subtotal' => '10000.000000',
                'tax_base' => '10000.000000',
                'tax_percent' => null,
                'tax_amount' => null,
                'net_amount' => '10000.000000',
                'total_before_round' => '99999.000000',
                'final_total' => '99999.000000',
                'rounding_diff' => '0',
            ])),
        );
    }

    public function test_that_a_tax_percent_without_an_amount_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(array_merge(self::TEN_THOUSAND_PLUS_DELIVERY, [
                'tax_amount' => null,
            ]))),
        );
    }

    public function test_that_a_tax_amount_without_a_percent_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(array_merge(self::TEN_THOUSAND_PLUS_DELIVERY, [
                'tax_percent' => null,
            ]))),
        );
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);
        self::assertSame(0, self::invariantCount(), 'down() left a CHECK behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertSame(6, self::invariantCount(), '§5.2 lost an identity on the way back.');
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /**
     * The acceptance row `D-62` and `D-64` share: items 10,000, delivery
     * 1,000, discount 1%, tax 14%.
     */
    private const TEN_THOUSAND_PLUS_DELIVERY = [
        'subtotal' => '10000.000000',
        'additional_total' => '1000.000000',
        'discount_percent' => '1',
        'discount_amount' => '100.000000',
        'tax_base' => '9900.000000',
        'tax_percent' => '14',
        'tax_amount' => '1386.000000',
        'net_amount' => '10900.000000',
        'total_before_round' => '12286.000000',
        'final_total' => '12286.000000',
        'rounding_diff' => '0',
    ];

    /**
     * A row with no lines, no tax and no discount, whose only interesting
     * figures are the three the rounding acceptance rows name.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function totalling(string $before, string $final, string $diff, array $overrides = []): void
    {
        $this->insert(array_merge([
            'subtotal' => $before,
            'tax_base' => $before,
            'net_amount' => $before,
            'total_before_round' => $before,
            'final_total' => $final,
            'rounding_diff' => $diff,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): void
    {
        $id = Uuid::uuid4()->toString();

        DB::table('quotations')->insert(array_merge([
            'id' => $id,
            'code' => 'QT-2026-'.substr(str_replace('-', '', $id), -4),
            'deal_id' => $this->dealId,
            'customer_id' => $this->customerId,
            'currency_id' => $this->currencyId,
            'default_margin' => '20',
            'discount_percent' => '0',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'subtotal' => '0',
            'additional_total' => '0',
            'discount_amount' => '0',
            'tax_base' => '0',
            'net_amount' => '0',
            'total_before_round' => '0',
            'final_total' => '0',
            'rounding_diff' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function storedValue(string $column): string
    {
        $value = DB::table('quotations')->value($column);

        self::assertIsString($value, "`{$column}` did not come back as a numeric string.");

        return $value;
    }

    private function refusedWith(callable $write): string
    {
        try {
            $write();
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;

            self::assertIsString($state);

            return $state;
        }

        self::fail('The database accepted arithmetic §5.2 forbids.');
    }

    /**
     * The six constraints Point 1.2 adds, read back from `pg_constraint`.
     * Named explicitly rather than pattern-matched, so Point 1.1's CHECKs on
     * the same table cannot be counted as these.
     */
    private static function invariantCount(): int
    {
        return count(DB::select(
            "select conname from pg_constraint where conrelid = to_regclass('quotations') "
            .'and conname in (?, ?, ?, ?, ?, ?)',
            [
                'quotations_final_total_carries_rounding_diff',
                'quotations_net_amount_includes_additional',
                'quotations_rounding_diff_zero_when_disabled',
                'quotations_tax_amount_matches_tax_percent',
                'quotations_tax_base_follows_discount',
                'quotations_total_before_round_adds_tax',
            ],
        ));
    }
}
