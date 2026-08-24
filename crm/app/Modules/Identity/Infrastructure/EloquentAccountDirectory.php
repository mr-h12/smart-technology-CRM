<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Authentication\Account;
use App\Modules\Identity\Domain\Contracts\AccountDirectoryInterface;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * {@see AccountDirectoryInterface} over the `users` table.
 *
 * The mapping is the point: everything above this line reasons about an
 * {@see Account}, so the sign-in rules never touch Eloquent and `deptrac` can
 * keep Application off Infrastructure.
 */
final class EloquentAccountDirectory implements AccountDirectoryInterface
{
    public function findByEmailForUpdate(string $email): ?Account
    {
        // No withTrashed(): DB-01 archives rather than deletes, and an archived
        // account is not one that signs in.
        $user = User::query()->where('email', $email)->lockForUpdate()->first();

        return $user instanceof User ? self::toAccount($user) : null;
    }

    public function findByIdForUpdate(string $accountId): ?Account
    {
        $user = User::query()->whereKey($accountId)->lockForUpdate()->first();

        return $user instanceof User ? self::toAccount($user) : null;
    }

    /**
     * `SEC-02` — the hash arrives already made; this only stores it.
     *
     * `forceFill` past the `hashed` cast is deliberately **not** used: the
     * attribute is assigned normally, and Laravel's `hashed` cast is a no-op on
     * a value that is already a hash, so one path stores passwords and there is
     * no second one that could store a plaintext.
     */
    public function updatePassword(string $accountId, string $passwordHash): void
    {
        $user = User::query()->whereKey($accountId)->first();

        if (! $user instanceof User) {
            return;
        }

        $user->password = $passwordHash;
        $user->save();
    }

    public function recordFailure(string $accountId, int $attempts, ?DateTimeImmutable $lockedUntil): void
    {
        $user = User::query()->whereKey($accountId)->first();

        if (! $user instanceof User) {
            return;
        }

        $user->failed_login_attempts = $attempts;

        // Only ever set, never cleared here: clearing is `clearFailures()`, and
        // a method that could do both would eventually unlock an account by
        // being called with null from the failure path.
        if ($lockedUntil !== null) {
            // Carbon, because that is what the cast produces on the way back
            // out; handing the property a bare DateTimeImmutable would make the
            // round trip asymmetric.
            $user->locked_until = Carbon::instance($lockedUntil);
        }

        $user->save();
    }

    public function clearFailures(string $accountId): void
    {
        $user = User::query()->whereKey($accountId)->first();

        if (! $user instanceof User) {
            return;
        }

        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->save();
    }

    /**
     * `SEC-03`'s recipients, by role slug from the database.
     *
     * Not from a configured address list: §3.1 says who Super Admin is and
     * §3.12 rule 5 keeps that in the table, so an address in a `.env` file
     * would be a second answer to a question the database already answers.
     *
     * §3.12 rule 6 hides this account from every *list*. Hidden is not absent —
     * it still receives what it is owed.
     *
     * @return list<string>
     */
    public function superAdminIds(): array
    {
        $ids = User::query()
            ->where('is_active', true)
            ->whereHas(
                'role',
                /** @param  Builder<Role>  $query */
                static fn (Builder $query): Builder => $query->where('slug', RoleName::SuperAdmin->value),
            )
            ->pluck('id')
            ->map(static fn (mixed $id): string => is_string($id) || is_int($id) ? (string) $id : '')
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();

        return array_values($ids);
    }

    private static function toAccount(User $user): Account
    {
        $lockedUntil = $user->locked_until;

        return new Account(
            id: (string) $user->id,
            name: (string) $user->name,
            email: (string) $user->email,
            passwordHash: (string) $user->password,
            isActive: $user->is_active === true,
            failedLoginAttempts: (int) $user->failed_login_attempts,
            // DB-08: the column is `timestamptz` and the application timezone
            // is UTC, but the conversion is explicit rather than assumed —
            // a comparison against a UTC "now" is only sound if both sides are.
            lockedUntil: $lockedUntil instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($lockedUntil)->setTimezone(new DateTimeZone('UTC'))
                : null,
        );
    }
}
