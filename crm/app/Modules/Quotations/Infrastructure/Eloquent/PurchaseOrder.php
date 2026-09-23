<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The framework binding for Module 10 · 1.6's `purchase_orders` (§4.6).
 * `po_number` is not fillable: it is allocated, never supplied.
 *
 * @property string $id
 * @property string $quotation_id
 * @property string $po_number
 * @property string $customer_po_reference
 * @property string $po_date
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[Fillable(['quotation_id', 'customer_po_reference', 'po_date'])]
class PurchaseOrder extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'purchase_orders';
}
