<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The framework binding for `customer_notes` (`D-122`). No cast on
 * `note_date`: PostgreSQL hands back `Y-m-d`, which is the API's shape, and a
 * `Carbon` would place a calendar date at UTC midnight.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $body
 * @property string|null $note_date
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
#[Fillable(['customer_id', 'body', 'note_date'])]
class CustomerNote extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'customer_notes';
}
