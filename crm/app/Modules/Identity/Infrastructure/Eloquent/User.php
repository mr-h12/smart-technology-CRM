<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The authenticated person, on the table Point 1.2 built.
 *
 * This is the framework binding for a schema that already exists — the module's
 * own domain objects, policies and use cases are Module 1's application work
 * and live under `app/Modules/Identity`, not here. What this class owes is a
 * key type that matches the column, casts that stop a boolean arriving as a
 * string, and the soft delete `DB-01` requires.
 *
 * `remember_token` and `email_verified_at` are gone with the scaffold. Nothing
 * in the documentation asks for "remember me" — `D-29` expires a session after
 * eight hours idle and `SEC-05` lists devices — and `SEC-04`'s email
 * verification is a step in the password-change flow, not a column here.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role_id
 * @property bool $is_active
 * @property bool $is_hidden
 * @property int $failed_login_attempts
 * @property \Illuminate\Support\Carbon|null $locked_until
 */
#[Fillable(['name', 'email', 'password', 'role_id', 'is_active', 'is_hidden'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    /**
     * Named explicitly because Eloquent finds a factory by convention —
     * `Database\Factories\{Model}Factory` relative to `App\Models` — and this
     * model no longer lives there. AP-02 puts a module's persistence inside the
     * module, so the convention has to be replaced rather than followed.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * §3.1 gives every user exactly one role, and `users.role_id` is NOT NULL,
     * so this relation always resolves for a row that exists.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * The signed-in devices `SEC-05` lists and allows force-logout on. Revoked
     * sessions are soft-deleted, so the default relation returns the live ones
     * — which is exactly what the device list shows.
     *
     * @return HasMany<UserSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class, 'user_id');
    }

    /**
     * §3.12 rule 6 — "The Super Admin is hidden: never listed in any user list,
     * for any role."
     *
     * **Every** query that produces a list of people for a human to look at or
     * pick from goes through this: a user index, an owner dropdown, a
     * reassignment picker, an approver selector, a report's staff column. The
     * rule says *every* list and names no exception, so there is no parameter
     * here to turn it off.
     *
     * It is a scope rather than a global one on purpose. A global scope would
     * also hide the account from the things that must still see it — the login
     * lookup, `SEC-03`'s notification recipients, `SEC-10`'s Login As, the
     * audit trail — and each of those would then need `withoutGlobalScope()`,
     * which is a rule you can forget to apply inverted into one you can forget
     * to lift. Opting *in* to a listing is the safer direction: the failure
     * mode is a query that shows too little, not one that shows the developer.
     *
     * ⚠️ **A scope cannot enforce itself.** Nothing makes a future listing call
     * it. Until `GET /api/v1/users` exists — Module 1's user CRUD, a later
     * point — this rule is enforced by convention plus the test that pins it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeListable(Builder $query): void
    {
        $query->where('is_hidden', false);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_hidden' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
        ];
    }
}
