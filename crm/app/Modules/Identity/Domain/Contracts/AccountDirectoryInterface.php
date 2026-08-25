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
     * The account behind an id, locked for update.
     *
     * Separate from {@see findByEmailForUpdate()} because the caller already
     * holds an authenticated identity and must not have to round-trip through
     * an address to use it — an address is a mutable field and an id is not.
     */
    public function findByIdForUpdate(string $accountId): ?Account;

    /**
     * The account behind an id, **without** a row lock.
     *
     * Separate from {@see self::findByIdForUpdate()} because `SELECT … FOR
     * UPDATE` outside a transaction takes a lock and drops it again in the same
     * breath — harmless, and a lie about what the caller needs. `SEC-04`'s
     * challenge issue reads a name and an address to put in a mail and writes
     * nothing to `users`; the honest signature says so.
     */
    public function findById(string $accountId): ?Account;

    /**
     * Replaces the stored hash (`SEC-02`).
     *
     * Takes a hash, never a plaintext password: hashing is a framework concern
     * and this interface lives in Domain, which `D-77` keeps framework-free.
     * The caller has already hashed, which also means no implementation of this
     * interface can accidentally store a password in the clear.
     */
    public function updatePassword(string $accountId, string $passwordHash): void;

    /**
     * The active Super Admins `SEC-03` must notify.
     *
     * @return list<string> account ids
     */
    public function superAdminIds(): array;
}
