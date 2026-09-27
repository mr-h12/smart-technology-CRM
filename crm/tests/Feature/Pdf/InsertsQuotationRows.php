<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The rows a Module 9 test needs under a quotation, inserted directly: the
 * customer, deal, currency and quotation Yousef's modules own, and a `files`
 * row. Moved out of `QuotationFilesTest` (1.3) when 3.3 became the second
 * caller, so every Step 3–5 test builds the same quotation the same way.
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
        $currencyId = DB::table('currencies')->where('code', 'EGP')->value('id');

        if (! is_string($currencyId)) {
            $currencyId = Uuid::uuid7()->toString();

            DB::table('currencies')->insert([
                'id' => $currencyId,
                'code' => 'EGP',
                'rounding_unit' => '1',
                'rounding_enabled' => true,
                'is_base' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
}
