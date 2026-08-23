<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
 */
#[Fillable(['name', 'email', 'password', 'role_id', 'is_active', 'is_hidden'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

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
