<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\PasswordPolicy;
use App\Modules\Identity\Domain\Personas\TestPersonas;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Seeding\GuardedSeeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The eight test personas (`DEV-08`), one per `§3.1` role.
 *
 * **This is test data, and it says so.** `seedsTestData()` returns true, so
 * `GuardedSeeder::run()` throws `ProductionSeedRefused` before a single row is
 * written if the environment is production — whether it was reached through
 * `DatabaseSeeder` or directly through `db:seed --class=`.
 *
 * **The password is not in this file, and there is no default.**
 * `SEED_TEST_USER_PASSWORD` is declared empty in `.env.example`, the same rule
 * `DB_PASSWORD` and `REDIS_PASSWORD` already follow, and this seeder refuses to
 * run without it rather than inventing one. A committed literal is a credential
 * that ships, and a default is a literal with better manners.
 *
 * The value is checked against `PasswordPolicy` (`D-28`) before use: seeding
 * eight accounts with a password the system would reject at the login form
 * produces test users nobody can change the password of.
 *
 * Addresses are on RFC 6761's `.test`, which never resolves — chosen in Point
 * 7.5 so that a mistyped environment cannot send mail to a real inbox.
 */
final class UserSeeder extends GuardedSeeder
{
    /** The environment key, named for the error messages that mention it. */
    public const PASSWORD_KEY = 'SEED_TEST_USER_PASSWORD';

    /** Where that key is read from — see config/seeding.php for why. */
    public const PASSWORD_CONFIG = 'seeding.test_user_password';

    public function seedsTestData(): bool
    {
        return true;
    }

    protected function seed(): void
    {
        $password = $this->password();

        foreach (TestPersonas::all() as $persona) {
            $role = Role::where('slug', $persona->role()->value)->first();

            if ($role === null) {
                // Ordering, stated as an error rather than left to produce a
                // foreign-key violation three frames deeper.
                throw new RuntimeException(
                    "Role '{$persona->role()->value}' does not exist. "
                    .'RolePermissionSeeder must run before UserSeeder.'
                );
            }

            $user = User::withTrashed()->updateOrCreate(
                ['email' => $persona->email()],
                [
                    'name' => $persona->name(),
                    'password' => $password,
                    'role_id' => $role->id,
                    'is_active' => true,
                    // §3.12 rule 6 — the Super Admin is hidden from every user
                    // list. The flag comes from the role rather than from a
                    // hard-coded email, so it stays true if the address changes.
                    'is_hidden' => $persona->role()->isHidden(),
                ],
            );

            // deleted_at is not fillable, so it cannot be restored through the
            // payload above — Eloquent would drop it without a word.
            if ($user->trashed()) {
                $user->restore();
            }
        }
    }

    /**
     * @throws RuntimeException when the key is absent, or holds a password the
     *                          system itself would reject
     */
    private function password(): string
    {
        // config(), never env(). With config:cache applied — which production
        // runs — env() returns null even for a key that is set: measured, with
        // the value in .env, before and after caching. A seeder that refuses a
        // correctly configured password only in production is worse than one
        // that never worked.
        $value = config(self::PASSWORD_CONFIG);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException(
                self::PASSWORD_KEY.' is not set. This seeder has no default on purpose: '
                .'a committed password is a credential that ships. Set it in the environment.'
            );
        }

        if (! PasswordPolicy::isSatisfiedBy($value)) {
            throw new RuntimeException(
                self::PASSWORD_KEY.' does not satisfy D-28 (at least '
                .PasswordPolicy::MINIMUM_LENGTH.' characters, letters and numbers). '
                .'Seeding accounts the login form would reject helps nobody.'
            );
        }

        // Hashed here rather than relying on the model's `hashed` cast, so this
        // is explicit at the point SEC-02 is satisfied. The cast leaves an
        // already-hashed value alone, so the two do not fight.
        return Hash::make($value);
    }
}
