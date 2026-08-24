<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Contracts\PermissionRepositoryInterface;
use App\Modules\Identity\Domain\Rbac\Actor;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see PermissionRepositoryInterface} over `roles`, `permissions` and
 * `role_permissions`.
 *
 * ── Why a join and not the Eloquent relation ───────────────────────────────
 *
 * `Role::permissions()` loads every triple the role holds — 29 rows for Indoor
 * Sales, 55 for Manager — to answer a question about one `resource.action`.
 * On an authorisation path that runs on every request, `PRF-01`'s 500 ms budget
 * is not somewhere to spend a full matrix load. The join returns the scopes and
 * nothing else.
 *
 * ── Why the memo is per-request and nothing longer ─────────────────────────
 *
 * §3.12 rule 5 makes a matrix change a configuration change. A cache that
 * outlives a request would make it a wait, and one that outlives a deploy would
 * make it a deploy. Within a single request the matrix cannot change, so
 * memoising there is free and correct — and one endpoint checking the same
 * permission for a list of rows is the normal shape.
 */
final class EloquentPermissionRepository implements PermissionRepositoryInterface
{
    /** @var array<string, Actor|null> */
    private array $actors = [];

    /** @var array<string, list<Scope>> */
    private array $scopes = [];

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function actorFor(string $userId): ?Actor
    {
        if (array_key_exists($userId, $this->actors)) {
            return $this->actors[$userId];
        }

        $user = User::query()->whereKey($userId)->first();

        if (! $user instanceof User) {
            return $this->actors[$userId] = null;
        }

        $role = $user->role()->first();

        if (! $role instanceof Role) {
            // users.role_id is NOT NULL, so this means the role was archived
            // under a live user. Denying is the only safe reading: DB-01 says
            // archived, and an archived role grants nothing.
            return $this->actors[$userId] = null;
        }

        return $this->actors[$userId] = new Actor(
            userId: (string) $user->id,
            roleId: (string) $role->id,
            roleSlug: (string) $role->slug,
        );
    }

    /** @return list<Scope> */
    public function scopesFor(string $roleId, string $resource, string $action): array
    {
        $key = $roleId.'|'.$resource.'|'.$action;

        if (array_key_exists($key, $this->scopes)) {
            return $this->scopes[$key];
        }

        $rows = $this->connection->table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->where('permissions.resource', $resource)
            ->where('permissions.action', $action)
            // DB-01: a revoked grant and a retired permission are both soft
            // deletes, and neither may still authorise anything. Written out
            // here because a raw join carries no model scope to do it.
            ->whereNull('role_permissions.deleted_at')
            ->whereNull('permissions.deleted_at')
            ->pluck('permissions.scope');

        $scopes = [];

        foreach ($rows as $row) {
            // tryFrom, not from: the column has a CHECK constraint listing the
            // five, but an authorisation path must not become a 500 because a
            // row got past it. An unrecognised scope grants nothing.
            $scope = is_string($row) ? Scope::tryFrom($row) : null;

            if ($scope instanceof Scope) {
                $scopes[] = $scope;
            }
        }

        return $this->scopes[$key] = array_values(array_unique($scopes, SORT_REGULAR));
    }
}
