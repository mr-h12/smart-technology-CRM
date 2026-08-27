<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The `currencies` row, as persistence and nothing more.
 *
 * **Why `Infrastructure`.** `deptrac.layers.yaml` gives Domain an empty ruleset,
 * so an Eloquent model — a framework object by definition — cannot live there.
 * The decisions about currencies are already framework-free in
 * `Domain\Money\Currency` and `RoundingRule`; this maps a table and stops.
 *
 * **`rounding_unit` carries no cast, deliberately.** PostgreSQL hands NUMERIC
 * back as a string and `RoundingRule` does BCMath over that string, so a cast
 * would add a conversion where `DB-07` forbids one and buy nothing. It would
 * also make this the only class in any module importing `App\Support`, which
 * both deptrac configs report as an uncovered dependency — the boundary
 * noticing before a reviewer did.
 *
 * @property string $id
 * @property string $code
 * @property string $rounding_unit
 * @property bool $rounding_enabled
 * @property bool $is_base
 */
final class Currency extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'currencies';

    /** @var list<string> */
    protected $fillable = ['code', 'rounding_unit', 'rounding_enabled', 'is_base'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rounding_enabled' => 'boolean',
            'is_base' => 'boolean',
        ];
    }
}
