<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\Rbac\Actor;
use App\Modules\Identity\Domain\Rbac\Scope;

/**
 * `SEC-07` — the matrix, read from the database.
 *
 * Every method here answers from `roles`, `permissions` and `role_permissions`.
 * **No implementation may consult `PermissionMatrix`**: that class is the
 * canonical *source* the seeder loads once, and §3.12 rule 5 makes the live
 * matrix a configuration change. Configuration that a class re-derives from
 * code is configuration that does not work — removing a grant would have to be
 * a deployment, which is the exact thing rule 5 forbids.
 */
interface PermissionRepositoryInterface
{
    /** The caller and their role, or null if the user no longer exists. */
    public function actorFor(string $userId): ?Actor;

    /**
     * Every scope this role holds on `resource.action`, from the grant rows.
     *
     * A list rather than one value because `Scope::includes()` is a partial
     * order — see {@see \App\Modules\Identity\Domain\Rbac\PermissionDecision}.
     * An empty list means the role holds nothing here, which §3's `—` and `❌`
     * both mean.
     *
     * @return list<Scope>
     */
    public function scopesFor(string $roleId, string $resource, string $action): array;
}
