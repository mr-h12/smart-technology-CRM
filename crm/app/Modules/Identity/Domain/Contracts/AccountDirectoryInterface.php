<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\Authentication\Account;
use DateTimeImmutable;

/**
 * Reads and updates the `users` row that authentication cares about.
 *
 * Soft-deleted rows are invisible here (`DB-01`): an archived account is not
 * one that signs in, and making that visible to the caller would only invite
 * somebody to decide otherwise.
 */
interface AccountDirectoryInterface
{
    /**
     * The account for an address, locked for update.
     *
     * The row lock is not optional. Two wrong guesses arriving together would
     * otherwise both read `failed_login_attempts = 3` and both write `4`, and
     * `SEC-03`'s fifth failure would never arrive.
     */
    public function findByEmailForUpdate(string $email): ?Account;

    /** `SEC-03` — the new count, and the lock if this failure caused one. */
    public function recordFailure(string $accountId, int $attempts, ?DateTimeImmutable $lockedUntil): void;

    /** A successful sign-in clears both counters. */
    public function clearFailures(string $accountId): void;

    /**
     * The active Super Admins `SEC-03` must notify.
     *
     * @return list<string> account ids
     */
    public function superAdminIds(): array;
}
