<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * `D-28` and `SEC-02`: "8 characters minimum, letters and numbers".
 *
 * The rule, and only the rule. Hashing is `SEC-02`'s other half — Argon2 or
 * bcrypt — and belongs to Module 1's infrastructure; a Domain class that hashed
 * would be reaching for the framework, which `deptrac` forbids here and
 * `Coding Standards §3.1` forbids everywhere.
 *
 * It lives in one place because it has three callers coming: the login form,
 * change-password, and the seeder that creates `DEV-08`'s test users. Three
 * copies of "at least eight, with a digit" is how one of them ends up at seven.
 */
final class PasswordPolicy
{
    public const MINIMUM_LENGTH = 8;

    public static function isSatisfiedBy(string $password): bool
    {
        if (mb_strlen($password) < self::MINIMUM_LENGTH) {
            return false;
        }

        // "Letters and numbers" is read as *both present*, not as *only these*.
        // A passphrase with a hyphen is longer and stronger than one without,
        // and nothing in D-28 excludes it.
        return preg_match('/\p{L}/u', $password) === 1
            && preg_match('/\d/', $password) === 1;
    }
}
