<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The `fx_rates` row, as persistence and nothing more — the reasoning in
 * {@see Currency} applies unchanged.
 *
 * **`rate` carries no cast, deliberately.** PostgreSQL hands `NUMERIC(18,8)`
 * back as a string and `ExchangeRate` does BCMath over that string; a cast
 * would add exactly the float conversion `DB-07` forbids, on the one value that
 * multiplies through every converted line of every quotation.
 *
 * @property string $id
 * @property string $from_currency_id
 * @property string $to_currency_id
 * @property string $rate
 * @property \Illuminate\Support\Carbon $effective_from
 * @property \Illuminate\Support\Carbon $created_at
 */
final class FxRate extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'fx_rates';

    /** @var list<string> */
    protected $fillable = ['from_currency_id', 'to_currency_id', 'rate', 'effective_from'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        // DB-08: stored UTC. The cast is what makes `effective_from` a date
        // object rather than the driver's string, and it is a date — not money.
        return ['effective_from' => 'immutable_datetime'];
    }
}
