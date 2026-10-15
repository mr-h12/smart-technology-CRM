<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The framework binding for the table Point 1.1 built.
 *
 * What it owes is a key type that matches the column, casts that stop a boolean
 * arriving as a string, and the soft delete `DB-01` requires. The module's
 * rules live in `app/Modules/Customers/Domain` — a model here carrying business
 * logic is the thing `CLAUDE.md` names first when it says modules are
 * directories with layers, not Eloquent models carrying rules.
 *
 * `customer_status` and `status_reason` are fillable for `D-123`'s status set
 * by hand only: `CustomerDraft` decides which keys reach `fill()`, and only
 * {@see \App\Modules\Customers\Domain\Writing\CustomerDraft::forStatusByHand()} carries them.
 *
 * @property string $id
 * @property string $name
 * @property string $customer_status
 * @property string|null $status_reason
 * @property string|null $sector
 * @property string|null $region
 * @property string|null $contact_person
 * @property string|null $contact_title
 * @property string|null $phone
 * @property string|null $phone2
 * @property string|null $whatsapp
 * @property string|null $email
 * @property string|null $location_url
 * @property string|null $website_url
 * @property string|null $social_url_1
 * @property string|null $social_url_2
 * @property string|null $social_url_3
 * @property string|null $sales_owner_id
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property string|null $notes
 * @property bool $is_archived
 * @property bool $is_incomplete
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
#[Fillable([
    'name', 'sector', 'region', 'contact_person', 'contact_title', 'phone', 'phone2',
    'whatsapp', 'email', 'location_url', 'website_url', 'social_url_1', 'social_url_2', 'social_url_3',
    'sales_owner_id', 'start_date', 'customer_status', 'status_reason',
    'is_archived', 'is_incomplete',
])]
class Customer extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'customers';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'is_archived' => 'boolean',
            'is_incomplete' => 'boolean',
        ];
    }
}
