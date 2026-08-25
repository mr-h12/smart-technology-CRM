<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Administration\AdministeredUser;
use App\Modules\Identity\Domain\Administration\UserListCriteria;
use App\Modules\Identity\Domain\Administration\UserPage;
use App\Modules\Identity\Domain\Contracts\RoleSummary;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * {@see UserDirectoryInterface} over `users`, joined to `roles`.
 *
 * ── `listable()` is applied in one place, by construction ──────────────────
 *
 * Every read below starts from {@see self::listable()}, which is the only
 * builder factory in this class. §3.12 rule 6 says "never listed in any user
 * list, **for any role**", and a scope that each method remembers to call is a
 * scope one method will forget. The writes take an id that can only have come
 * from a read, so the filter reaches them too.
 */
final class EloquentUserDirectory implements UserDirectoryInterface
{
    public function list(UserListCriteria $criteria): UserPage
    {
        $query = $this->listable();

        if ($criteria->isActive !== null) {
            $query->where('users.is_active', $criteria->isActive);
        }

        if ($criteria->roleSlug !== null) {
            $query->whereHas('role', static function (Builder $role) use ($criteria): void {
                $role->where('slug', $criteria->roleSlug);
            });
        }

        // OpenAPI §6.1: "Pagination always happens **after** authorization
        // scoping" — the scope is already on the builder, and the count below
        // is taken from the same one rather than from a fresh query.
        $total = $query->count();

        $rows = $query
            ->with('role')
            ->orderBy($criteria->sortField, $criteria->sortDescending ? 'desc' : 'asc')
            // A deterministic tiebreak. Two people named Ahmed would otherwise
            // page non-deterministically: PostgreSQL is free to return equal
            // sort keys in any order, so a row can appear on page 1 and page 2
            // of the same listing, or on neither.
            ->orderBy('users.id')
            ->offset($criteria->offset())
            ->limit($criteria->perPage)
            ->get();

        $items = [];

        foreach ($rows as $row) {
            $items[] = self::hydrate($row);
        }

        return new UserPage($items, $total, $criteria->page, $criteria->perPage);
    }

    public function find(string $userId): ?AdministeredUser
    {
        $row = $this->listable()->with('role')->whereKey($userId)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function create(string $name, string $email, string $passwordHash, string $roleId, bool $isHidden): AdministeredUser
    {
        $user = new User;
        $user->fill([
            'name' => $name,
            'email' => $email,
            // Already hashed by the use case. The model's `hashed` cast leaves
            // an existing hash alone, so the two do not fight — the same
            // arrangement UserSeeder uses.
            'password' => $passwordHash,
            'role_id' => $roleId,
            'is_active' => true,
            'is_hidden' => $isHidden,
        ]);
        $user->save();

        return self::hydrate($user->load('role'));
    }

    public function update(string $userId, array $changes): AdministeredUser
    {
        $user = $this->listable()->whereKey($userId)->first();

        if ($user === null) {
            // The use case located this row moments ago inside the same
            // transaction. Reaching here means the row moved underneath us,
            // which is a bug rather than a refusal to render.
            throw new RuntimeException('User '.$userId.' vanished between read and write.');
        }

        $user->fill($changes);
        $user->save();

        return self::hydrate($user->load('role'));
    }

    public function setActive(string $userId, bool $isActive): void
    {
        $user = $this->listable()->whereKey($userId)->first();

        if ($user === null) {
            throw new RuntimeException('User '.$userId.' vanished between read and write.');
        }

        $user->is_active = $isActive;
        $user->save();
    }

    public function findRole(string $roleId): ?RoleSummary
    {
        $role = Role::query()->whereKey($roleId)->first();

        return $role === null ? null : new RoleSummary($role->id, $role->slug, $role->name);
    }

    public function emailIsTaken(string $email, ?string $exceptUserId = null): bool
    {
        // Deliberately **not** through listable(): the partial unique index is
        // `WHERE deleted_at IS NULL` and knows nothing about `is_hidden`, so an
        // address held by the hidden Super Admin is taken even though no
        // listing shows it. Asking the filtered set would report it free and
        // then fail on the INSERT with a database error instead of a 422.
        $query = User::query()->where('email', $email);

        if ($exceptUserId !== null) {
            $query->whereKeyNot($exceptUserId);
        }

        return $query->exists();
    }

    /**
     * The one builder factory: live rows, §3.12 rule 6 already applied.
     *
     * @return Builder<User>
     */
    private function listable(): Builder
    {
        return User::query()->listable();
    }

    private static function hydrate(User $user): AdministeredUser
    {
        $role = $user->role;
        $createdAt = $user->created_at;
        $updatedAt = $user->updated_at;

        // `users.role_id` is NOT NULL with a RESTRICT foreign key and
        // `standardColumns()` writes both timestamps, so none of these three can
        // legitimately be null. Checked rather than silenced with a static
        // analysis suppression: if the invariant ever breaks, an explicit
        // message beats a "Call to a member function on null" three frames away.
        if ($role === null || $createdAt === null || $updatedAt === null) {
            throw new RuntimeException('User '.$user->id.' is missing its role or timestamps.');
        }

        return new AdministeredUser(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roleId: $user->role_id,
            roleSlug: $role->slug,
            roleName: $role->name,
            roleNameAr: $role->name_ar,
            isActive: $user->is_active,
            isHidden: $user->is_hidden,
            // DB-08: stored UTC, handed on as an immutable UTC instant. The
            // conversion to the reader's timezone is the SPA's, at display.
            createdAt: DateTimeImmutable::createFromInterface($createdAt),
            updatedAt: DateTimeImmutable::createFromInterface($updatedAt),
        );
    }
}
