<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Identity\Domain\Rbac\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The `permissions` row — one `resource.action.scope` triple.
 *
 * In `Infrastructure` for the same reason as {@see Role}: Domain may not touch
 * Illuminate. The triple's meaning lives in `Domain\Rbac`, which is where the
 * matrix and the scope comparison already are.
 *
 * @property string $id
 * @property string $resource
 * @property string $action
 * @property string $scope
 * @property string|null $description
 */
final class Permission extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'permissions';

    /** @var list<string> */
    protected $fillable = ['resource', 'action', 'scope', 'description'];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }

    /**
     * `§3.2`'s own notation: "Permission = Resource + Action + Scope", written
     * the way that section writes it — `customer.view.own`.
     */
    public function triple(): string
    {
        return $this->resource.'.'.$this->action.'.'.$this->scope;
    }

    /**
     * The typed scope, so a caller comparing reach uses `Scope::includes()`
     * rather than comparing two strings and inventing the hierarchy again.
     */
    public function scopeValue(): Scope
    {
        return Scope::from($this->scope);
    }

    /** @param  Builder<self>  $query */
    public function scopeForResource(Builder $query, string $resource): void
    {
        $query->where('resource', $resource);
    }
}
