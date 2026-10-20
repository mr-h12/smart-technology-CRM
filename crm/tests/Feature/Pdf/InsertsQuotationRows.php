<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The rows a Module 9 test needs under a quotation, inserted directly: the
 * customer, deal, currency and quotation Yousef's modules own, and a `files`
 * row. Moved out of `QuotationFilesTest` (1.3) when 3.3 became the second
 * caller, so every Step 3–5 test builds the same quotation the same way; 4.2
 * brought `insertGeneration` here from `PdfGenerationsTableTest` for the same reason,
 * and Module 13 · 8.2 `pricedQuotation` — a priced version with lines — for RPT-MN's
 * money in both `MonthlyReportsGenerationTest` and `ReportPdfGenerationTest`.
 */
trait InsertsQuotationRows
{
    /**
     * @param  string|null  $ownerId  the deal's `owner_id` — §3.5's "own" for its quotations
     */
    private function insertQuotation(?string $ownerId = null): string
    {
        $customerId = Uuid::uuid7()->toString();
        $dealId = Uuid::uuid7()->toString();
        $quotationId = Uuid::uuid7()->toString();

        DB::table('customers')->insert(['id' => $customerId, 'name' => 'Nile Trading', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('deals')->insert([
            'id' => $dealId,
            'code' => 'DL-2026-'.substr(str_replace('-', '', $dealId), -4),
            'customer_id' => $customerId,
            'owner_id' => $ownerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // One base currency however many quotations a test builds.
        $currencyId = $this->currencyId('EGP');

        DB::table('quotations')->insert([
            'id' => $quotationId,
            'code' => 'QT-2026-'.substr(str_replace('-', '', $quotationId), -4),
            'deal_id' => $dealId,
            'customer_id' => $customerId,
            'currency_id' => $currencyId,
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
        ]);

        return $quotationId;
    }

    private function insertFile(): string
    {
        $id = Uuid::uuid7()->toString();

        DB::table('files')->insert([
            'id' => $id,
            'original_name' => 'QT-2026-0001.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_path' => '2026/09/quotation/'.Uuid::uuid7()->toString().'/'.$id.'.pdf',
            'scan_status' => 'clean',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * One `pdf_generations` row (3.3), `queued` in English under a new
     * quotation unless `$overrides` says otherwise.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertGeneration(array $overrides = []): string
    {
        $id = Uuid::uuid7()->toString();

        DB::table('pdf_generations')->insert($overrides + [
            'id' => $id,
            'quotation_id' => $overrides['quotation_id'] ?? $this->insertQuotation(),
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * A quotation version with §5.1's stored money, kept to the table's own checks (net = subtotal
     * + additional − discount; final = net + tax + rounding_diff), and its lines' `line_cost`. A
     * `$parentId` makes it that version's copy: its next version, on the same deal (`D-08`), in its own currency.
     *
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $additional
     * @param  numeric-string  $discount
     * @param  numeric-string|null  $tax
     * @param  numeric-string  $roundingDiff
     * @param  list<numeric-string>  $lineCosts
     * @param  list<numeric-string>  $deletedLineCosts  soft-deleted lines
     */
    private function pricedQuotation(string $status, ?string $sentAt, string $currency, string $subtotal, string $additional = '0', string $discount = '0',
        ?string $tax = null, string $roundingDiff = '0', array $lineCosts = [], array $deletedLineCosts = [], ?string $parentId = null): string
    {
        $currencyId = $this->currencyId($currency);
        $id = $this->insertQuotation();
        $net = bcadd(bcsub($subtotal, $discount, 6), $additional, 6);
        $beforeRound = bcadd($net, $tax ?? '0', 6);
        /** @var object{deal_id: string, customer_id: string, version: int}|null $parent */
        $parent = $parentId === null ? null : DB::table('quotations')->where('id', $parentId)->first(['deal_id', 'customer_id', 'version']);

        DB::table('quotations')->where('id', $id)->update([
            'status' => $status, 'sent_at' => $sentAt, 'currency_id' => $currencyId,
            'rejection_reason' => in_array($status, ['rejected', 'counter'], true) ? 'Too dear.' : null,
            'subtotal' => $subtotal, 'additional_total' => $additional, 'discount_amount' => $discount,
            'tax_base' => bcsub($subtotal, $discount, 6), 'tax_percent' => $tax === null ? null : '14', 'tax_amount' => $tax,
            'net_amount' => $net, 'total_before_round' => $beforeRound,
            'final_total' => bcadd($beforeRound, $roundingDiff, 6), 'rounding_diff' => $roundingDiff,
            ...($parent === null ? [] : ['parent_id' => $parentId, 'version' => $parent->version + 1, 'deal_id' => $parent->deal_id, 'customer_id' => $parent->customer_id]),
        ]);

        if (bccomp($additional, '0', 6) !== 0) {
            DB::table('quotation_additional_items')->insert([
                'id' => Uuid::uuid7()->toString(), 'quotation_id' => $id, 'description' => 'Delivery', 'amount' => $additional, 'line_no' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $supplierLine = $this->supplierLine();
        foreach ([...array_map(static fn (string $c): array => [$c, null], $lineCosts), ...array_map(static fn (string $c): array => [$c, now()], $deletedLineCosts)] as $i => [$cost, $deletedAt]) {
            DB::table('quotation_items')->insert([
                'id' => Uuid::uuid7()->toString(), 'quotation_id' => $id, 'supplier_quotation_item_id' => $supplierLine, 'line_no' => $i + 1,
                'unit_cost' => $cost, 'unit_cost_currency' => $currency, 'unit_cost_fx_rate_at_time' => '1', 'unit_cost_base' => $cost,
                'unit_price' => $cost, 'quantity' => '1', 'line_total' => $cost, 'line_cost' => $cost,
                'deleted_at' => $deletedAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** EGP is the base currency `insertQuotation()` also uses; any other code is added beside it. */
    private function currencyId(string $code): string
    {
        $id = DB::table('currencies')->where('code', $code)->value('id');
        if (is_string($id)) {
            return $id;
        }
        $id = Uuid::uuid7()->toString();
        DB::table('currencies')->insert([
            'id' => $id, 'code' => $code, 'rounding_unit' => $code === 'EGP' ? '1' : '0.01', 'rounding_enabled' => $code === 'EGP',
            'is_base' => $code === 'EGP', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** The supplier line a `quotation_items` row must name (a NOT NULL foreign key), made once. */
    private function supplierLine(): string
    {
        $line = DB::table('supplier_quotation_items')->value('id');
        if (is_string($line)) {
            return $line;
        }
        $supplier = Uuid::uuid7()->toString();
        $item = Uuid::uuid7()->toString();
        $offer = Uuid::uuid7()->toString();
        $line = Uuid::uuid7()->toString();
        DB::table('suppliers')->insert(['id' => $supplier, 'name' => 'Alpha Supplies', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog_items')->insert(['id' => $item, 'kind' => 'product', 'name' => 'Widget', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_quotations')->insert([
            'id' => $offer, 'code' => 'SQ-2026-0001', 'supplier_id' => $supplier, 'total_price' => '1000', 'currency_id' => $this->currencyId('EGP'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $line, 'supplier_quotation_id' => $offer, 'catalog_item_id' => $item, 'unit_price' => '1000', 'quantity' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $line;
    }
}
