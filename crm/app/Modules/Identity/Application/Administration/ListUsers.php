<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Administration;

use App\Modules\Identity\Domain\Administration\AdministeredUser;
use App\Modules\Identity\Domain\Administration\AdministrationRefusal;
use App\Modules\Identity\Domain\Administration\UserAdministrationRefused;
use App\Modules\Identity\Domain\Administration\UserListCriteria;
use App\Modules\Identity\Domain\Administration\UserPage;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;

/**
 * §8's "Employees" screen, and the first listing in the system that
 * {@see \App\Modules\Identity\Infrastructure\Eloquent\User::scopeListable()}
 * actually guards.
 *
 * ── No scope filtering, and that is the documented answer ──────────────────
 *
 * `SEC-08` is row-level security, and §3.11 gives both roles that hold any
 * administration permission — Super Admin and Manager — scope **All** on every
 * row of that table. There is no Team column to honour: the "Others" column is
 * `—` throughout, so the Team Leader never reaches this use case at all.
 *
 * ⚠️ **`users` also has no team column** (Point 1.2's approved schema), so if a
 * later decision gives the Team Leader a scoped employee list, this needs a
 * migration before it needs a filter. Recorded so that gap is not mistaken for
 * an oversight here.
 */
final readonly class ListUsers
{
    public function __construct(private UserDirectoryInterface $users) {}

    public function handle(UserListCriteria $criteria): UserPage
    {
        return $this->users->list($criteria);
    }

    /** @throws UserAdministrationRefused when the id is unknown, archived, or hidden */
    public function one(string $userId): AdministeredUser
    {
        $user = $this->users->find($userId);

        if (! $user instanceof AdministeredUser) {
            // §5.1: 404 for "does not exist **or** is not visible to the
            // caller. Do not reveal which case applies." §3.12 rule 6's hidden
            // Super Admin lands here and is indistinguishable from a typo.
            throw UserAdministrationRefused::because(AdministrationRefusal::UserNotFound);
        }

        return $user;
    }
}
