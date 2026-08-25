<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The `roles` row, as persistence and nothing more.
 *
 * **Why `Infrastructure` and not `Domain`.** `deptrac.layers.yaml` gives Domain
 * an empty ruleset — it may depend on nothing, Illuminate included — because
 * Coding Standards §3.1 keeps the business rules portable for `ERP-01`. An
 * Eloquent model is a framework object by definition, so this is the only
 * layer it can legally occupy.
 *
 * **It carries no rules.** The decisions about roles already exist and are
 * framework-free: `Domain\Rbac\Role` is the eight `§3.1` roles with their
 * hidden and unconditional-access behaviour, and `PermissionMatrix` is
 * `§3.3`…`§3.12`. This class maps a table, exposes its relations, and stops —
 * which is what `CLAUDE.md` means by "not Eloquent models carrying business
 * rules". Anything that reads like a decision belongs one layer down.
 *
 * @property string $id
 * @property string $name
 * @property string|null $name_ar
 * @property string $slug
 * @property bool $is_system
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
final class Role extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'roles';

    /** @var list<string> */
    protected $fillable = ['name', 'name_ar', 'slug', 'is_system', 'description'];

    /**
     * The `resource.action.scope` triples this role holds (`SEC-07`).
     *
     * `withTimestamps()` so a grant records when it was made — `DB-02` puts
     * audit columns on the pivot too, and a permission change that leaves no
     * trace is the one nobody can explain later.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }

    /**
     * The typed `§3.1` role this row stands for, or null for one an
     * administrator added later.
     */
    public function name(): ?RoleName
    {
        return RoleName::tryFrom($this->slug);
    }

    /**
     * `§3.1`'s eight, which an administrator may not delete or rename.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSystem(Builder $query): void
    {
        $query->where('is_system', true);
    }

    /**
     * Everything an administrator added, which is the set that may be edited.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCustom(Builder $query): void
    {
        $query->where('is_system', false);
    }
}
