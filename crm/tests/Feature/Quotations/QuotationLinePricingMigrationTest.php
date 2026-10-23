<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;
use Throwable;

/**
 * QS.2a — migration B. `D-155`: a quotation line owns what was typed —
 * `quotation_items` gains `supplier_id` (NOT NULL, a foreign key to
 * `suppliers`), `unit_code` (a `units` code, no foreign key, like
 * `catalog_items.unit`), `entered_unit_price` (NUMERIC(18,6), `>= 0`) and
 * `tax_percent` (NUMERIC(6,3), NULL or `>= 0`), backfilled from each line's
 * supplier-offer line — a soft-deleted one too, since an offer edit
 * soft-deletes every line it replaces — and `supplier_quotation_item_id`
 * becomes nullable legacy provenance. `D-165`: every quotation gets a
 * `pricing_mode` (`legacy` on every existing row), `default_margin` is
 * non-null exactly when the mode is `legacy`, a new mode carries no quotation
 * rate, a `tax_exempt` one no tax amount (`D-63`), and
 * `quotations_tax_amount_matches_tax_percent` binds `legacy` only (`D-157`:
 * the tax is per line). Reversible (`DEV-03`) until the first line-owned save.
 */
final class QuotationLinePricingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_23_100000_add_line_pricing.php';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    /** E4 (QS.2a contract §3), stored the legacy way: margin 10 + 5, 14% on the quotation, discount 10%, delivery 50. */
    private const LEGACY = [
        'default_margin' => '10', 'discount_percent' => '10', 'tax_percent' => '14', 'tax_percents' => '["14.000"]',
        'rounding_unit' => '1', 'rounding_enabled' => false,
        'subtotal' => '345', 'additional_total' => '50', 'discount_amount' => '34.5', 'tax_base' => '310.5', 'tax_amount' => '43.47',
        'net_amount' => '360.5', 'total_before_round' => '403.97', 'final_total' => '403.97', 'rounding_diff' => '0',
    ];

    /** E1: cost 100 at 14%, margin 20, × 3 — no quotation margin, no quotation rate, yet a tax amount (`D-157`). */
    private const TAX_INCLUDED = [
        'default_margin' => null, 'tax_percent' => null, 'tax_percents' => '[]',
        'subtotal' => '360', 'discount_amount' => '36', 'tax_base' => '324', 'tax_amount' => '45.36',
        'net_amount' => '374', 'total_before_round' => '419.36', 'final_total' => '419.36',
    ];

    /** E3: typed at 100 with the toggle off — no tax line at all (`D-63`). */
    private const TAX_EXEMPT = [
        ...self::TAX_INCLUDED,
        'tax_amount' => null, 'total_before_round' => '374', 'final_total' => '374',
    ];

    private string $customerId;

    private string $dealId;

    private string $currencyId;

    private string $supplierA;

    private string $supplierB;

    /** @var array<string, array{line: string, item: string}> */
    private array $offerLines = [];

    private int $codes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerId = Uuid::uuid4()->toString();
        $this->dealId = Uuid::uuid4()->toString();
        $this->currencyId = Uuid::uuid4()->toString();
        $this->supplierA = Uuid::uuid4()->toString();
        $this->supplierB = Uuid::uuid4()->toString();
        $offerA = Uuid::uuid4()->toString();
        $offerB = Uuid::uuid4()->toString();

        DB::table('customers')->insert(['id' => $this->customerId, 'name' => 'Nile Trading', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('currencies')->insert([
            'id' => $this->currencyId, 'code' => 'EGP', 'rounding_unit' => '1', 'rounding_enabled' => true, 'is_base' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deals')->insert([
            'id' => $this->dealId, 'code' => 'DL-2026-0001', 'customer_id' => $this->customerId, 'last_activity_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('suppliers')->insert([
            ['id' => $this->supplierA, 'name' => 'Alpha Supplies', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->supplierB, 'name' => 'Beta Trading', 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Offer B's header is itself soft-deleted: the backfill filters neither side of the offer.
        DB::table('supplier_quotations')->insert([
            ['id' => $offerA, 'code' => 'SQ-2026-0001', 'supplier_id' => $this->supplierA, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $offerB, 'code' => 'SQ-2026-0002', 'supplier_id' => $this->supplierB, 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->offerLines = [
            'switch' => $this->offerLine($offerA, 'Switch 24-port', 'pcs', deleted: false),
            'router' => $this->offerLine($offerA, 'Router 8-port', 'box', deleted: true),
            'access point' => $this->offerLine($offerB, 'Access point', null, deleted: false),
        ];
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertTrue(Schema::hasColumn('quotation_items', 'supplier_id'), 'D-155: the line names its own supplier.');

        $this->rollBack();

        foreach (['supplier_id', 'unit_code', 'entered_unit_price', 'tax_percent'] as $column) {
            self::assertFalse(Schema::hasColumn('quotation_items', $column), "down() left `quotation_items.{$column}` behind (DEV-03).");
        }
        self::assertFalse(Schema::hasColumn('quotations', 'pricing_mode'), 'down() left `quotations.pricing_mode` behind (DEV-03).');
        self::assertSame('uuid not null', self::shape('quotation_items', 'supplier_quotation_item_id'));
        self::assertSame('numeric(6,3) not null', self::shape('quotations', 'default_margin'));
        self::assertSame('CHECK (((tax_percent IS NULL) = (tax_amount IS NULL)))', self::constraint('quotations_tax_amount_matches_tax_percent'),
            'down() must restore the unconditional pairing Point 1.2 wrote.');

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));

        self::assertSame(
            [
                'supplier_id' => 'uuid not null',
                'unit_code' => 'character varying(64) null',
                'entered_unit_price' => 'numeric(18,6) not null',
                'tax_percent' => 'numeric(6,3) null',
                'supplier_quotation_item_id' => 'uuid null',
            ],
            [
                'supplier_id' => self::shape('quotation_items', 'supplier_id'),
                'unit_code' => self::shape('quotation_items', 'unit_code'),
                'entered_unit_price' => self::shape('quotation_items', 'entered_unit_price'),
                'tax_percent' => self::shape('quotation_items', 'tax_percent'),
                'supplier_quotation_item_id' => self::shape('quotation_items', 'supplier_quotation_item_id'),
            ],
        );
        self::assertSame('numeric(6,3) null', self::shape('quotations', 'default_margin'));

        $mode = self::shape('quotations', 'pricing_mode');
        self::assertIsString($mode, '`quotations.pricing_mode` did not come back.');
        self::assertStringStartsWith('character varying', $mode);
        self::assertStringEndsWith(' not null', $mode);

        self::assertCount(1, self::foreignKeys('FOREIGN KEY (supplier_id) REFERENCES suppliers(id)%'), 'DB-04: the supplier is a foreign key to `suppliers`.');
        self::assertCount(1, self::foreignKeys('FOREIGN KEY (supplier_quotation_item_id) REFERENCES supplier_quotation_items(id)%'),
            'D-155: the offer line stays as provenance, still a foreign key.');
    }

    // ──────────────────────────────────────────────────────────── the backfill

    /** Every line, an archived one too, from its offer line and offer header whether or not either is alive. */
    public function test_that_every_line_is_backfilled_from_its_offer_line_even_a_soft_deleted_one(): void
    {
        $this->rollBack();

        $taxed = $this->quotation();
        $exempt = $this->quotation(['tax_percent' => null, 'tax_percents' => '[]', 'tax_amount' => null, 'total_before_round' => '360.5', 'final_total' => '360.5']);

        $onLive = $this->oldLine($taxed, 'switch', 1);
        // An FX line: the entered price is the converted `unit_cost_base`, never the supplier-currency `unit_cost`.
        $onDeleted = $this->oldLine($taxed, 'router', 2, [
            'unit_cost' => '100.000000', 'unit_cost_currency' => 'USD', 'unit_cost_fx_rate_at_time' => '48.50000000',
            'unit_cost_base' => '4850.000000', 'unit_price' => '5577.500000', 'line_total' => '5577.500000', 'line_cost' => '4850.000000',
        ]);
        $archived = $this->oldLine($exempt, 'access point', 1, [
            'unit_cost' => '250.500000', 'unit_cost_base' => '250.500000', 'unit_price' => '288.080000', 'line_total' => '288.080000',
            'line_cost' => '250.500000', 'deleted_at' => now(),
        ]);

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertTrue(Schema::hasColumn('quotation_items', 'supplier_id'), 'up() did not add `quotation_items.supplier_id`.');

        self::assertSame(
            [
                'on a live offer line' => ['supplier_id' => $this->supplierA, 'unit_code' => 'pcs', 'entered_unit_price' => '1000.000000', 'tax_percent' => '14.000'],
                'on a soft-deleted offer line' => ['supplier_id' => $this->supplierA, 'unit_code' => 'box', 'entered_unit_price' => '4850.000000', 'tax_percent' => '14.000'],
                'archived, exempt, on a soft-deleted offer' => ['supplier_id' => $this->supplierB, 'unit_code' => null, 'entered_unit_price' => '250.500000', 'tax_percent' => null],
            ],
            [
                'on a live offer line' => self::backfilled($onLive),
                'on a soft-deleted offer line' => self::backfilled($onDeleted),
                'archived, exempt, on a soft-deleted offer' => self::backfilled($archived),
            ],
        );
    }

    public function test_that_every_existing_quotation_becomes_legacy_and_keeps_its_margin_and_rate(): void
    {
        $this->rollBack();

        $taxed = $this->quotation();
        $exempt = $this->quotation(['tax_percent' => null, 'tax_percents' => '[]', 'tax_amount' => null, 'total_before_round' => '360.5', 'final_total' => '360.5']);

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertTrue(Schema::hasColumn('quotations', 'pricing_mode'), 'up() did not add `quotations.pricing_mode`.');

        // `D-165`: an issued legacy quotation is never re-priced and keeps its margin and rate as history.
        self::assertSame(
            [[$taxed, 'legacy', '10.000', '14.000'], [$exempt, 'legacy', '10.000', null]],
            DB::table('quotations')->whereIn('id', [$taxed, $exempt])->orderBy('code')->get(['id', 'pricing_mode', 'default_margin', 'tax_percent'])
                ->map(static fn (stdClass $row): array => array_values((array) $row))->all(),
        );
    }

    // ───────────────────────────────────────────── D-165: one CHECK per mode

    public function test_that_a_legacy_row_keeps_its_margin_and_its_paired_rate(): void
    {
        $id = $this->quotationIn('legacy');

        self::assertSame('legacy', DB::table('quotations')->where('id', $id)->value('pricing_mode'));
    }

    public function test_that_a_legacy_row_without_a_quotation_margin_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->quotationIn('legacy', ['default_margin' => null])));
    }

    public function test_that_a_legacy_row_with_a_tax_amount_and_no_rate_is_still_refused(): void
    {
        // `quotations_tax_amount_matches_tax_percent` still binds `legacy` (`D-63`).
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->quotationIn('legacy', ['tax_percent' => null, 'tax_percents' => '[]'])));
    }

    public function test_that_a_tax_included_row_carries_a_tax_amount_with_no_quotation_rate(): void
    {
        // E1: `tax_percent` NULL and `tax_amount` 45.36 — the pairing no longer binds a new mode (`D-157`).
        $id = $this->quotationIn('tax_included');

        self::assertSame(
            ['tax_included', null, null, '45.360000'],
            array_values((array) DB::table('quotations')->where('id', $id)->first(['pricing_mode', 'default_margin', 'tax_percent', 'tax_amount'])),
        );
    }

    public function test_that_a_tax_exempt_row_is_accepted(): void
    {
        $id = $this->quotationIn('tax_exempt');

        self::assertSame('tax_exempt', DB::table('quotations')->where('id', $id)->value('pricing_mode'));
    }

    public function test_that_a_new_mode_row_with_a_quotation_margin_is_refused(): void
    {
        // `D-165`: `default_margin` is non-null exactly when the mode is `legacy`.
        // One refusal per test: a refused write aborts the test's transaction.
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->quotationIn('tax_included', ['default_margin' => '10'])));
    }

    public function test_that_a_tax_included_row_with_a_quotation_rate_is_refused(): void
    {
        // `D-157`: tax is per line; a new mode carries no quotation rate.
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->quotationIn('tax_included', ['tax_percent' => '14', 'tax_percents' => '["14.000"]'])),
        );
    }

    public function test_that_a_tax_exempt_row_with_a_quotation_rate_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->quotationIn('tax_exempt', ['tax_percent' => '14', 'tax_percents' => '["14.000"]'])),
        );
    }

    public function test_that_a_tax_exempt_row_with_a_tax_amount_is_refused(): void
    {
        // `D-63`: an exempt quotation has no tax line at all. The money identities still hold.
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->quotationIn('tax_exempt', ['tax_amount' => '45.36', 'total_before_round' => '419.36', 'final_total' => '419.36'])),
        );
    }

    public function test_that_an_unknown_pricing_mode_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->quotationIn('retail')));
    }

    public function test_that_a_quotation_without_a_pricing_mode_is_refused(): void
    {
        self::assertSame(self::NOT_NULL_VIOLATION, $this->refusedWith(fn () => $this->quotationIn('legacy', ['pricing_mode' => null])));
    }

    // ─────────────────────────────────────────────── D-155: the line's own facts

    public function test_that_a_line_needs_no_offer_line_and_may_be_taxed_at_zero_or_not_at_all(): void
    {
        $quotation = $this->quotationIn('tax_included');

        $this->ownedLine($quotation);
        $this->ownedLine($quotation, ['line_no' => 2, 'tax_percent' => '0']);
        $this->ownedLine($quotation, ['line_no' => 3, 'tax_percent' => null]);

        self::assertSame(3, DB::table('quotation_items')->where('quotation_id', $quotation)->whereNull('supplier_quotation_item_id')->count());
    }

    public function test_that_a_line_without_a_supplier_is_refused(): void
    {
        $quotation = $this->quotationIn('tax_included');

        self::assertSame(self::NOT_NULL_VIOLATION, $this->refusedWith(fn () => $this->ownedLine($quotation, ['supplier_id' => null])));
    }

    public function test_that_a_line_naming_an_unknown_supplier_is_refused(): void
    {
        $quotation = $this->quotationIn('tax_included');

        self::assertSame(self::FOREIGN_KEY_VIOLATION, $this->refusedWith(fn () => $this->ownedLine($quotation, ['supplier_id' => Uuid::uuid4()->toString()])));
    }

    public function test_that_a_line_without_an_entered_price_is_refused(): void
    {
        $quotation = $this->quotationIn('tax_included');

        self::assertSame(self::NOT_NULL_VIOLATION, $this->refusedWith(fn () => $this->ownedLine($quotation, ['entered_unit_price' => null])));
    }

    public function test_that_a_negative_entered_price_is_refused(): void
    {
        $quotation = $this->quotationIn('tax_included');

        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->ownedLine($quotation, ['entered_unit_price' => '-0.000001'])));
    }

    public function test_that_a_negative_line_tax_is_refused(): void
    {
        $quotation = $this->quotationIn('tax_included');

        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->ownedLine($quotation, ['tax_percent' => '-1'])));
    }

    // ─────────────────────────────────────── down(), once a line owns its facts

    public function test_that_down_refuses_while_a_line_has_no_offer_line_to_restore(): void
    {
        // ponytail ceiling (contract §1): reversible only before the first
        // line-owned save, and it says so rather than half-restoring.
        $quotation = $this->quotationIn('legacy');
        $this->ownedLine($quotation);

        $refusal = null;
        try {
            Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);
        } catch (Throwable $exception) {
            $refusal = $exception;
        }

        self::assertInstanceOf(Throwable::class, $refusal, 'down() rolled back over a line it cannot give an offer line.');
        self::assertStringContainsString('supplier_quotation_item_id', $refusal->getMessage());
        self::assertTrue(Schema::hasColumn('quotation_items', 'supplier_id'), 'A refused down() must leave the schema as it was.');
        self::assertSame(1, DB::table('quotation_items')->where('quotation_id', $quotation)->count());
    }

    // ───────────────────────────────────────────────────────────────── helpers

    private function rollBack(): void
    {
        self::assertFileExists(base_path(self::MIGRATION), 'QS.2a: migration B does not exist yet.');
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));
    }

    /** @return array{line: string, item: string} a catalog product with its unit, and an offer line on it */
    private function offerLine(string $offerId, string $name, ?string $unit, bool $deleted): array
    {
        $item = Uuid::uuid4()->toString();
        $line = Uuid::uuid4()->toString();

        DB::table('catalog_items')->insert(['id' => $item, 'kind' => 'product', 'name' => $name, 'unit' => $unit, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $line, 'supplier_quotation_id' => $offerId, 'catalog_item_id' => $item,
            'unit_price' => '1000.000000', 'quantity' => '5.0000',
            'deleted_at' => $deleted ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['item' => $item, 'line' => $line];
    }

    /**
     * A quotation row; with no `pricing_mode` it is written the way every row was before migration B.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function quotation(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('quotations')->insert(array_merge([
            'id' => $id, 'code' => sprintf('QT-2026-%04d', ++$this->codes), 'deal_id' => $this->dealId, 'customer_id' => $this->customerId,
            'currency_id' => $this->currencyId, 'created_at' => now(), 'updated_at' => now(),
        ], self::LEGACY, $overrides));

        return $id;
    }

    /** @param  array<string, mixed>  $overrides */
    private function quotationIn(string $mode, array $overrides = []): string
    {
        self::assertTrue(Schema::hasColumn('quotations', 'pricing_mode'), 'D-165: `quotations.pricing_mode` does not exist yet.');

        $figures = match ($mode) {
            'tax_included' => self::TAX_INCLUDED,
            'tax_exempt' => self::TAX_EXEMPT,
            default => [],
        };

        return $this->quotation(array_merge($figures, ['pricing_mode' => $mode], $overrides));
    }

    /**
     * A line written the way every line was before migration B.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function oldLine(string $quotationId, string $offerLine, int $lineNo, array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('quotation_items')->insert(array_merge([
            'id' => $id, 'quotation_id' => $quotationId, 'line_no' => $lineNo,
            'supplier_quotation_item_id' => $this->offerLines[$offerLine]['line'], 'catalog_item_id' => $this->offerLines[$offerLine]['item'],
            'unit_cost' => '1000.000000', 'unit_cost_currency' => 'EGP', 'unit_cost_fx_rate_at_time' => '1', 'unit_cost_base' => '1000.000000',
            'margin_percent' => '5', 'unit_price' => '1150.000000', 'quantity' => '1.0000', 'line_total' => '1150.000000', 'line_cost' => '1000.000000',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    /**
     * E1's line, owning its facts (`D-155`): no offer line, typed 100 at 14%, margin 20, × 3 (`D-167`).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function ownedLine(string $quotationId, array $overrides = []): void
    {
        self::assertTrue(Schema::hasColumn('quotation_items', 'supplier_id'), 'D-155: `quotation_items.supplier_id` does not exist yet.');

        DB::table('quotation_items')->insert(array_merge([
            'id' => Uuid::uuid4()->toString(), 'quotation_id' => $quotationId, 'line_no' => 1, 'supplier_quotation_item_id' => null,
            'supplier_id' => $this->supplierA, 'catalog_item_id' => $this->offerLines['switch']['item'], 'unit_code' => 'pcs',
            'entered_unit_price' => '100', 'tax_percent' => '14',
            'unit_cost' => '100.000000', 'unit_cost_currency' => 'EGP', 'unit_cost_fx_rate_at_time' => '1', 'unit_cost_base' => '100.000000',
            'margin_percent' => '20', 'unit_price' => '120.000000', 'quantity' => '3.0000', 'line_total' => '360.000000', 'line_cost' => '300.000000',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    /** @return array<mixed> the four backfilled columns of one line */
    private static function backfilled(string $lineId): array
    {
        return (array) DB::table('quotation_items')->where('id', $lineId)->first(['supplier_id', 'unit_code', 'entered_unit_price', 'tax_percent']);
    }

    /** `format_type` and nullability, e.g. `numeric(18,6) not null`; null when the column is absent. */
    private static function shape(string $table, string $column): mixed
    {
        $row = DB::selectOne(
            "select format_type(atttypid, atttypmod) || case when attnotnull then ' not null' else ' null' end as shape "
            .'from pg_attribute where attrelid = to_regclass(?) and attname = ? and attnum > 0 and not attisdropped',
            [$table, $column],
        );

        return $row instanceof stdClass ? $row->shape : null;
    }

    private static function constraint(string $name): mixed
    {
        $row = DB::selectOne('select pg_get_constraintdef(oid) as definition from pg_constraint where conname = ?', [$name]);

        return $row instanceof stdClass ? $row->definition : null;
    }

    /** @return array<mixed> */
    private static function foreignKeys(string $definition): array
    {
        return DB::select(
            'select conname from pg_constraint where conrelid = to_regclass(?) '
            ."and contype = 'f' and pg_get_constraintdef(oid) like ?",
            ['quotation_items', $definition],
        );
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

        self::fail('The database accepted a row it should have refused.');
    }
}
