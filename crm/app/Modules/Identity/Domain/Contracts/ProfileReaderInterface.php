<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\Authentication\Profile;

/**
 * Assembles `GET /auth/me`'s answer from the database.
 *
 * `SEC-07` and §3.12 rule 5 put the matrix in `role_permissions`, so an
 * implementation reads rows. It must never consult `PermissionMatrix`, which is
 * the canonical *source* the seeder loads from and not a runtime authority — a
 * permission an administrator adds is a configuration change, and configuration
 * that a class re-derives from code is configuration that does not work.
 */
interface ProfileReaderInterface
{
    public function for(string $accountId): ?Profile;
}
