<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Administration;

use App\Modules\Identity\Domain\Administration\AdministeredUser;
use App\Modules\Identity\Domain\Administration\AdministrationRefusal;
use App\Modules\Identity\Domain\Administration\UserAdministrationRefused;
use App\Modules\Identity\Domain\Authentication\DeviceSession;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Modules\Identity\Domain\RoleAdministration\ReferencePage;

/**
 * §13 screen 2's "devices · IP · browser" — `GET /users/{id}/sessions`.
 *
 * ── The target is resolved through the directory, not the session store ────
 *
 * {@see UserDirectoryInterface::find()} applies `User::scopeListable()`, so
 * §3.12 rule 6's hidden Super Admin answers `404 user_not_found` here exactly
 * as it does on `GET /users/{id}`. Reading the sessions straight out of the
 * store would have skipped that: the store is keyed on `user_id` and knows
 * nothing about who may be listed, so an administrator who guessed the hidden
 * id would have been handed the one account §3.12 rule 6 exists to conceal —
 * along with its addresses and its browsers.
 *
 * ── An impersonation row is still not a device ─────────────────────────────
 *
 * {@see SessionStoreInterface::devicesFor()} filters `impersonator_id IS NULL`
 * and this use case does not undo it. §3.1 hides the Super Admin from *all*
 * users, and a Manager reading this screen is one of them; a Login As session
 * surfacing here would name the administrator running it to the only other
 * role that can open the screen.
 *
 * ── Reading is not audited ─────────────────────────────────────────────────
 *
 * `AUD-01` audits changes. A permanent `AUD-03` row every time a drawer opened
 * would bury the terminations this feature exists to make findable.
 */
final readonly class ListUserSessions
{
    public function __construct(
        private UserDirectoryInterface $users,
        private SessionStoreInterface $sessions,
    ) {}

    /**
     * @param  string|null  $callerSessionId  the administrator's own session, so
     *                                        inspecting yourself marks the right row
     * @return ReferencePage<DeviceSession>
     *
     * @throws UserAdministrationRefused when the id is unknown, archived, or hidden
     */
    public function handle(
        string $userId,
        ?string $callerSessionId,
        ReferenceListCriteria $criteria,
    ): ReferencePage {
        if (! $this->users->find($userId) instanceof AdministeredUser) {
            throw UserAdministrationRefused::because(AdministrationRefusal::UserNotFound);
        }

        return $this->sessions->devicesFor($userId, $callerSessionId, $criteria);
    }
}
