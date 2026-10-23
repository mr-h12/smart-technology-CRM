<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * QS.2a — the quotation the save takes since `D-155`…`D-157`, and the legacy one it replaces (`D-165`).
 *
 * A line names its supplier, its product and its unit, and carries what was typed: the entered price, its own
 * tax and its own margin. `e1Body()` and `e3Body()` are `D-156`'s worked examples 1 and 3, the figures every
 * QS.2a test uses. `legacyQuotation()` is example 4 inserted directly, as migration B leaves a quotation saved
 * before it: the old body is refused from QS.2a on, so the endpoint can no longer make one.
 */
trait QuotationLineFixtures
{
    /**
     * One line on a supplier and a product of its own, in the unit `piece` — example 1's line by default:
     * cost 100 with 14 % added on top (`D-167`), at 20 %, × 3. A null `$tax` leaves the key out, as a `tax_exempt` body must.
     *
     * @return array<string, string>
     */
    private function lineBody(string $entered = '100', ?string $tax = '14', string $margin = '20', string $quantity = '3'): array
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert(['id' => $supplierId, 'name' => 'Alpha Supplies', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog_items')->insert([
            'id' => $catalogItemId, 'kind' => 'product', 'name' => 'Widget', 'unit' => $this->unitCode(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'supplier_id' => $supplierId,
            'catalog_item_id' => $catalogItemId,
            'unit_code' => $this->unitCode(),
            'entered_unit_price' => $entered,
            ...($tax === null ? [] : ['tax_percent' => $tax]),
            'margin_percent' => $margin,
            'quantity' => $quantity,
        ];
    }

    /**
     * Example 1 (`D-156`), toggle on: 10 % off, delivery 50 → 360.00 − 36.00 + 45.36 VAT + 50.00 = 419.36.
     * The POST adds the deal and the customer.
     *
     * @param  array<string, string>|null  $line
     * @return array<string, mixed>
     */
    private function e1Body(?array $line = null): array
    {
        return [
            'currency' => 'EGP',
            'pricing_mode' => 'tax_included',
            'discount_percent' => '10',
            'lines' => [$line ?? $this->lineBody()],
            'additional_items' => [['description' => 'Delivery', 'amount' => '50']],
        ];
    }

    /**
     * Example 3, toggle off (`D-157`): 100 used as typed, at 20 % × 3, 10 % off, delivery 50, no tax → 374.00.
     *
     * @return array<string, mixed>
     */
    private function e3Body(): array
    {
        return [...$this->e1Body($this->lineBody(entered: '100', tax: null)), 'pricing_mode' => 'tax_exempt'];
    }

    /**
     * Example 4, as migration B leaves a quotation priced before it: `legacy`, quotation margin 10, the line's
     * own 5, 100 net from `$offerLineId`'s offer × 3 at 14 % on the quotation, 10 % off, delivery 50 →
     * 115.00 · 345.00 − 34.50 + 43.47 + 50 = 403.97. Untaxed (`$taxed` false) it is 360.50. The line keeps its
     * offer line, and carries what the backfill gives it: the offer's supplier and product, `entered_unit_price`
     * = `unit_cost_base`, the quotation's tax.
     */
    private function legacyQuotation(string $dealId, string $customerId, string $offerLineId, string $status = 'draft', bool $taxed = true): string
    {
        $offer = DB::table('supplier_quotation_items')
            ->join('supplier_quotations', 'supplier_quotations.id', '=', 'supplier_quotation_items.supplier_quotation_id')
            ->where('supplier_quotation_items.id', $offerLineId)
            ->first(['supplier_quotations.supplier_id', 'supplier_quotation_items.catalog_item_id']);
        self::assertNotNull($offer);

        $id = Uuid::uuid4()->toString();
        $total = $taxed ? '403.97' : '360.5';

        DB::table('quotations')->insert([
            'id' => $id,
            'code' => 'QT-2026-'.substr(str_replace('-', '', $id), -4),
            'deal_id' => $dealId,
            'customer_id' => $customerId,
            'currency_id' => DB::table('currencies')->where('code', 'EGP')->value('id'),
            'status' => $status,
            'sent_at' => $status === 'draft' ? null : now(),
            'pricing_mode' => 'legacy',
            'default_margin' => '10',
            'discount_percent' => '10',
            'tax_percent' => $taxed ? '14' : null,
            'tax_percents' => $taxed ? '["14.000"]' : '[]',
            'rounding_unit' => '1',
            'rounding_enabled' => false,
            'subtotal' => '345',
            'additional_total' => '50',
            'discount_amount' => '34.5',
            'tax_base' => '310.5',
            'tax_amount' => $taxed ? '43.47' : null,
            'net_amount' => '360.5',
            'total_before_round' => $total,
            'final_total' => $total,
            'rounding_diff' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('quotation_items')->insert([
            'id' => Uuid::uuid4()->toString(),
            'quotation_id' => $id,
            'line_no' => 1,
            'supplier_quotation_item_id' => $offerLineId,
            'supplier_id' => $offer->supplier_id,
            'catalog_item_id' => $offer->catalog_item_id,
            'unit_code' => $this->unitCode(),
            'entered_unit_price' => '100',
            'tax_percent' => $taxed ? '14' : null,
            'unit_cost' => '100',
            'unit_cost_currency' => 'EGP',
            'unit_cost_fx_rate_at_time' => '1',
            'unit_cost_base' => '100',
            'margin_percent' => '5',
            'unit_price' => '115',
            'quantity' => '3',
            'line_total' => '345',
            'line_cost' => '300',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('quotation_additional_items')->insert([
            'id' => Uuid::uuid4()->toString(), 'quotation_id' => $id, 'line_no' => 1, 'description' => 'Delivery', 'amount' => '50',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** `piece`, a code of the `units` managed list (`DB-05`) — the one unit these fixtures use, added once. */
    private function unitCode(): string
    {
        if (! DB::table('enum_lists')->where('list', 'units')->where('code', 'piece')->whereNull('deleted_at')->exists()) {
            DB::table('enum_lists')->insert([
                'id' => Uuid::uuid4()->toString(), 'list' => 'units', 'code' => 'piece', 'label_en' => 'Piece', 'label_ar' => 'قطعة',
                'position' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return 'piece';
    }
}
