<?php

declare(strict_types=1);

namespace App\Modules\Deals\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The framework binding for the table Point 1.1 built — `Customer`'s shape
 * (Module 3 Point 1.1), on the same reasoning: a key type that matches the
 * column, casts that stop a boolean arriving as a string, and the soft delete
 * `DB-01` requires. The module's rules live in `app/Modules/Deals/Domain` — a
 * model here carrying business logic is what `CLAUDE.md` names first.
 *
 * `code`, `status`, `approval_status` and `rejection_reason` are
 * fillable-by-omission on purpose: `EloquentDealDirectory` sets each directly
 * (Point 2.3) rather than through mass assignment, because none of them is a
 * caller-supplied value — a code is allocated, a status transition is a
 * domain rule, and approval is derived from who is creating the deal.
 *
 * `customer_id` **is** fillable here, even though it is create-only: that
 * boundary is `DealDraft::forUpdate()`'s (Point 2.3), which never includes
 * the key in what it hands to `fill()` — the model has no way to tell create
 * from update, and guarding the same rule twice would be the second copy
 * that eventually disagrees with the first.
 *
 * @property string $id
 * @property string $code
 * @property string $customer_id
 * @property string|null $title
 * @property string|null $source
 * @property string|null $service_type
 * @property string $status
 * @property string|null $owner_id
 * @property string|null $approval_status
 * @property string|null $rejection_reason
 * @property string|null $lost_reason
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
#[Fillable(['customer_id', 'title', 'source', 'service_type', 'owner_id'])]
class Deal extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'deals';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
        ];
    }
}
