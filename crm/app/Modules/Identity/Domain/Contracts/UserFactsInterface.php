<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

/**
 * What another module may read about a user without an admin's grant: the
 * name behind an id (Module 7 Step 6 Q2 — the employee group's label on
 * `GET /quotations?group_by=employee`, which a Team Leader sees and
 * `GET /users` would refuse them).
 *
 * The facts seam, on {@see \App\Modules\Deals\Domain\Contracts\DealFactsInterface}'s
 * shape; {@see UserDirectoryInterface} is the administrator's surface and
 * stays behind `admin.create_user`.
 */
interface UserFactsInterface
{
    /**
     * User id => display name for one page's owners. The hidden Super Admin
     * (§3.1 "hidden from all user lists") and a `DB-01` soft-deleted account
     * have no entry, so the caller falls back to whatever it labelled before.
     * The empty list answers `[]` without a query.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    public function namesOf(array $userIds): array;
}
