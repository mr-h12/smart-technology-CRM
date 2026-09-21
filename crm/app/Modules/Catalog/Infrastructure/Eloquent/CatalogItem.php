<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The framework binding for the table Point 1.2 built.
 *
 * What it owes is a key type that matches the column, a cast that stops a
 * boolean arriving as a string, and the soft delete `DB-01` requires. The
 * module's rules live in `app/Modules/Catalog/Domain` — a model here carrying
 * business logic is the thing `CLAUDE.md` names first when it says modules are
 * directories with layers, not Eloquent models carrying rules.
 *
 * Fillable is declared even though Point 3.1 never writes: Point 3.2 does, and
 * an unfillable model at that point invites `forceFill`, which is how a guarded
 * column gets written by accident.
 *
 * @property string $id
 * @property string $kind
 * @property string|null $name
 * @property string|null $product_code
 * @property string|null $category
 * @property string|null $unit
 * @property string|null $service_type
 * @property string|null $company
 * @property string|null $description
 * @property string|null $notes
 * @property bool $is_active
 * @property bool $is_incomplete
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
#[Fillable([
    'kind', 'name', 'product_code', 'category', 'unit', 'service_type',
    'company', 'description', 'notes', 'is_active', 'is_incomplete',
])]
class CatalogItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'catalog_items';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_incomplete' => 'boolean',
        ];
    }
}
