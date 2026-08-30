<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The framework binding for the table Point 1.1 built.
 *
 * What it owes is a key type that matches the column, casts that stop a boolean
 * arriving as a string, and the soft delete `DB-01` requires. The module's
 * rules live in `app/Modules/Suppliers/Domain` — a model here carrying business
 * logic is the thing `CLAUDE.md` names first when it says modules are
 * directories with layers, not Eloquent models carrying rules.
 *
 * Fillable is declared even though Point 2.1 never writes: Point 2.2 does, and
 * an unfillable model at that point invites `forceFill`, which is how a guarded
 * column gets written by accident.
 *
 * @property string $id
 * @property string $name
 * @property string|null $type
 * @property string $color_rating
 * @property string|null $phone
 * @property string|null $contact_person
 * @property bool $has_open_account
 * @property bool $is_active
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
#[Fillable([
    'name', 'type', 'color_rating', 'phone', 'contact_person',
    'has_open_account', 'is_active',
])]
class Supplier extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'suppliers';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'has_open_account' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
