<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Infrastructure\Eloquent;

use App\Support\Database\Precision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The framework binding for the table Point 1.1 built — `SupplierQuotation`'s
 * shape (Module 6 Point 1.3), on the same reasoning. The module's rules live in
 * `app/Modules/Quotations/Domain`; a model here carrying business logic is what
 * `CLAUDE.md` names first.
 *
 * `code` is fillable-by-omission on purpose: `EloquentQuotationDirectory` sets
 * it directly from `document_sequences`, because it is allocated rather than
 * supplied.
 *
 * ── Every decimal casts through `Precision`, and none through a literal ────
 *
 * `D-68` fixes the scales — money at six, percent at three — and `Precision`
 * exists because a column's scale and its cast's scale have to agree. As two
 * numbers in two files they drift, and the symptom is a total stored exactly
 * and read back rounded, which looks like a calculation bug and is not.
 * `DB-07` is the reason none of them is a float.
 *
 * `tax_percent` and `tax_amount` are nullable and stay nullable through the
 * cast: `D-63`'s exempt quotation renders **no tax line at all**, and `null` is
 * how "no tax" is told apart from "zero tax".
 *
 * ── What is deliberately not cast ─────────────────────────────────────────
 *
 * `quotation_date` and `valid_until` are PostgreSQL `date`s and arrive as the
 * `YYYY-MM-DD` strings §6 asks for. A `date` cast would turn each into a Carbon
 * carrying a midnight, and `DB-08` — store UTC, convert for display — is a rule
 * about instants, not about the day a quotation was priced. `SupplierQuotation`
 * left `offer_date` and `valid_until` uncast for the same reason.
 *
 * `sent_at` **is** an instant, and it is uncast here because nothing writes or
 * reads it until Step 4's transitions. The point that gives it a writer gives
 * it its `datetime` cast.
 *
 * The booleans and the two counters are uncast because the pgsql driver already
 * returns them as `bool` and `int` — asserted in `EloquentQuotationDirectoryTest`
 * rather than assumed, so a cast added here would be a second opinion about a
 * value that is already right.
 *
 * @property string $id
 * @property string $code
 * @property string $deal_id
 * @property string $customer_id
 * @property string|null $quotation_date
 * @property string|null $valid_until
 * @property string $status
 * @property string $currency_id
 * @property string $default_margin
 * @property string $discount_percent
 * @property string|null $tax_percent
 * @property string $rounding_unit
 * @property bool $rounding_enabled
 * @property string $subtotal
 * @property string $additional_total
 * @property string $discount_amount
 * @property string $tax_base
 * @property string|null $tax_amount
 * @property string $net_amount
 * @property string $total_before_round
 * @property string $final_total
 * @property string $rounding_diff
 * @property string|null $payment_terms
 * @property string|null $warranty
 * @property string|null $delivery_terms
 * @property bool $show_delivery_terms
 * @property int $version
 * @property string|null $parent_id
 * @property string|null $rejection_reason
 * @property string|null $sent_at
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property bool $is_self_approved
 * @property int $version_token
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
#[Fillable([
    'deal_id',
    'customer_id',
    'quotation_date',
    'valid_until',
    'currency_id',
    'default_margin',
    'discount_percent',
    'tax_percent',
    'rounding_unit',
    'rounding_enabled',
    'subtotal',
    'additional_total',
    'discount_amount',
    'tax_base',
    'tax_amount',
    'net_amount',
    'total_before_round',
    'final_total',
    'rounding_diff',
    'payment_terms',
    'warranty',
    'delivery_terms',
    'show_delivery_terms',
])]
class Quotation extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'quotations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'default_margin' => Precision::CAST_PERCENT,
            'discount_percent' => Precision::CAST_PERCENT,
            'tax_percent' => Precision::CAST_PERCENT,
            'rounding_unit' => Precision::CAST_MONEY,
            'subtotal' => Precision::CAST_MONEY,
            'additional_total' => Precision::CAST_MONEY,
            'discount_amount' => Precision::CAST_MONEY,
            'tax_base' => Precision::CAST_MONEY,
            'tax_amount' => Precision::CAST_MONEY,
            'net_amount' => Precision::CAST_MONEY,
            'total_before_round' => Precision::CAST_MONEY,
            'final_total' => Precision::CAST_MONEY,
            'rounding_diff' => Precision::CAST_MONEY,
            // Point 4.2 gives it a writer, so it gets its cast (see `sent_at` above).
            'submitted_at' => 'datetime',
        ];
    }
}
