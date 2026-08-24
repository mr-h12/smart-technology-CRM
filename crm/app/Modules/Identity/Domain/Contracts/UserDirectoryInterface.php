<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\Administration\AdministeredUser;
use App\Modules\Identity\Domain\Administration\UserListCriteria;
use App\Modules\Identity\Domain\Administration\UserPage;

/**
 * The `users` table as §3.11's administration screens need it.
 *
 * Separate from {@see AccountDirectoryInterface}, which serves authentication
 * and hands back a password hash. Nothing here ever returns one.
 *
 * ── §3.12 rule 6 is this interface's responsibility, not its callers' ──────
 *
 * "The Super Admin is hidden — never listed in any user list, for any role."
 * Every method below is specified to apply `User::scopeListable()`, so a hidden
 * account is absent from {@see self::list()} **and** unreachable through
 * {@see self::find()} by anyone who guesses its id. The rule as written covers
 * only listing; extending it to lookup is deliberate, because an endpoint that
 * refuses to list an account and then edits it on request has not hidden
 * anything.
 *
 * The cost, stated rather than hidden: a Super Admin cannot administer another
 * Super Admin through this API. §3.12 rule 6 names no exception, so neither
 * does this.
 */
interface UserDirectoryInterface
{
    public function list(UserListCriteria $criteria): UserPage;

    /** Listable users only — a hidden or archived row answers null. */
    public function find(string $userId): ?AdministeredUser;

    /** Takes a hash (`SEC-02`); Domain never sees a plaintext password (`D-77`). */
    public function create(string $name, string $email, string $passwordHash, string $roleId, bool $isHidden): AdministeredUser;

    /**
     * Applies the fields that were submitted, and only those.
     *
     * @param  array{name?: string, email?: string, role_id?: string, is_hidden?: bool}  $changes
     */
    public function update(string $userId, array $changes): AdministeredUser;

    /** `D-34` — the switch, never a delete. */
    public function setActive(string $userId, bool $isActive): void;

    /** The live role behind an id, as `[id, slug, name]`, or null. */
    public function findRole(string $roleId): ?RoleSummary;

    /** True when a live account already holds this address (the partial unique index). */
    public function emailIsTaken(string $email, ?string $exceptUserId = null): bool;
}
