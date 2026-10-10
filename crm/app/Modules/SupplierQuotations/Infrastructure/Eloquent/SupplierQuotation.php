<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Infrastructure\Eloquent;

use App\Support\Database\Precision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The framework binding for the table Point 1.1 built — `Deal`'s shape
 * (Module 5 Point 1.1), on the same reasoning. The module's rules live in
 * `app/Modules/SupplierQuotations/Domain`; a model here carrying business logic
 * is what `CLAUDE.md` names first.
 *
 * `code` is fillable-by-omission on purpose: `EloquentSupplierQuotationDirectory`
 * sets it directly from `document_sequences`, because it is allocated rather
 * than supplied.
 *
 * `total_price` casts through {@see Precision::CAST_MONEY} rather than a
 * literal `decimal:6`. `Precision` exists because the column scale and the cast
 * scale have to agree, and as two numbers in two files they drift — the symptom
 * being a price stored exactly and read back rounded, which looks like a
 * calculation bug and is not.
 *
 * `offer_date` and `valid_until` are deliberately **not** cast. A PostgreSQL
 * `date` arrives as the `YYYY-MM-DD` string §7.2 asks for, which is what
 * `SupplierQuotationSummary` carries; a `date` cast would turn it into a Carbon
 * carrying a midnight, and `DB-08` — store UTC, convert for display — is a rule
 * about instants, not about the day a supplier priced an offer.
 *
 * @property string $id
 * @property string $code
 * @property string $supplier_id
 * @property string|null $deal_id
 * @property string|null $total_price
 * @property string|null $currency_id
 * @property string|null $offer_date
 * @property string|null $valid_until
 * @property string|null $notes
 * @property bool $prices_include_tax
 * @property string|null $included_tax_percent
 * @property string|null $entered_total_price
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
#[Fillable(['supplier_id', 'deal_id', 'total_price', 'currency_id', 'offer_date', 'valid_until', 'notes', 'prices_include_tax', 'included_tax_percent', 'entered_total_price'])]
class SupplierQuotation extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'supplier_quotations';

    /**
     * The column's default (`D-105`, off), mirrored: a saved model does not
     * read database defaults back, and a summary hydrated from it needs a bool.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['prices_include_tax' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_price' => Precision::CAST_MONEY,
            'prices_include_tax' => 'boolean',
            'included_tax_percent' => Precision::CAST_PERCENT,
            'entered_total_price' => Precision::CAST_MONEY,
        ];
    }
}
