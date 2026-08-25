<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\SessionManagement;

use App\Modules\Identity\Domain\Authentication\DeviceSession;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Modules\Identity\Domain\RoleAdministration\ReferencePage;

/**
 * `GET /api/v1/auth/sessions` — `SEC-05`'s active device list.
 *
 * ── There is no permission on this ─────────────────────────────────────────
 *
 * §3.11 has no row for it, and inventing one would invent a permission the
 * seeded matrix does not contain. The right to see your own devices is having
 * a session, exactly as it is for `POST /auth/change-password`: the account is
 * read from the guard and there is no `user_id` parameter, so there is nothing
 * an authorisation check could narrow. Somebody *else's* devices are §13
 * screen 2's "devices · IP · browser", an administrative screen with its own
 * permission, and this is not it.
 *
 * ── Reading is not free of consequence, so it is not audited ───────────────
 *
 * `AUD-01` audits changes. A list endpoint that wrote a permanent row
 * (`AUD-03`) every time a screen mounted would bury the revocations this
 * module actually needs to be able to find.
 */
final readonly class ListDeviceSessions
{
    public function __construct(private SessionStoreInterface $sessions) {}

    /** @return ReferencePage<DeviceSession> */
    public function handle(
        string $accountId,
        ?string $currentSessionId,
        ReferenceListCriteria $criteria,
    ): ReferencePage {
        return $this->sessions->devicesFor($accountId, $currentSessionId, $criteria);
    }
}
