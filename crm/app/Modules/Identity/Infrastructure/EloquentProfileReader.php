<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Authentication\Profile;
use App\Modules\Identity\Domain\Contracts\ProfileReaderInterface;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;

/**
 * {@see ProfileReaderInterface} — `SEC-07`'s matrix, read from the tables.
 */
final class EloquentProfileReader implements ProfileReaderInterface
{
    public function for(string $accountId): ?Profile
    {
        $user = User::query()->whereKey($accountId)->first();

        if (! $user instanceof User) {
            return null;
        }

        $role = $user->role()->first();
        $unconditional = $role instanceof Role && $role->name()?->hasUnconditionalAccess() === true;

        return new Profile(
            id: (string) $user->id,
            name: (string) $user->name,
            email: (string) $user->email,
            isActive: $user->is_active === true,
            role: $role instanceof Role
                ? ['id' => (string) $role->id, 'slug' => (string) $role->slug, 'name' => (string) $role->name]
                : null,
            permissions: $unconditional ? $this->everyTriple() : $this->triplesOf($role),
            unconditionalAccess: $unconditional,
        );
    }

    /** @return list<string> */
    private function triplesOf(?Role $role): array
    {
        if (! $role instanceof Role) {
            return [];
        }

        return self::sorted(
            $role->permissions()->get()->map(static fn (Permission $p): string => $p->triple())->all(),
        );
    }

    /**
     * Every triple the system knows, for the role §3.1 exempts from the matrix.
     *
     * Read from the table rather than from `PermissionMatrix`, so a permission
     * an administrator adds later (§3.12 rule 5) is included without anybody
     * remembering to come back here.
     *
     * @return list<string>
     */
    private function everyTriple(): array
    {
        return self::sorted(
            Permission::query()->get()->map(static fn (Permission $p): string => $p->triple())->all(),
        );
    }

    /**
     * @param  array<int, string>  $triples
     * @return list<string>
     */
    private static function sorted(array $triples): array
    {
        // sort() reindexes from zero, which is what makes the result a list.
        sort($triples);

        return $triples;
    }
}
