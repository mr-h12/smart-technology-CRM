<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\RoleAdministration;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Contracts\RoleDirectoryInterface;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefusal;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefused;
use App\Modules\Identity\Domain\RoleAdministration\RoleView;
use Illuminate\Database\ConnectionInterface;

/**
 * `PATCH /api/v1/roles/{id}` — the "edit role" half of §3.11's row, for the
 * role's *identity* rather than its grants.
 *
 * ── The slug is not editable, and that is a rule rather than an omission ───
 *
 * `Domain\Rbac\Role` matches a database row to §3.1 **by slug**, and
 * `RoleAssignmentPolicy::permitsSlug()` decides rule 7 from it. Renaming a slug
 * would therefore silently re-answer an authorisation question: change
 * `procurement` to `buying` and `Role::tryFrom()` starts returning null, so
 * `permitsSlug()` falls through to its Super-Admin-only branch and a Manager
 * who could assign that role yesterday is refused today, with nothing in the
 * audit log about permissions to explain it. The slug is the machine key the
 * original migration called "the machine key … never translated"; this endpoint
 * takes the two labels and the description and nothing else.
 *
 * ── System roles are refused ───────────────────────────────────────────────
 *
 * All eight, not just the Super Admin. `RolePermissionSeeder` rewrites `name`
 * from `Role::label()` on every run, so a rename here survives until the next
 * deployment and then reverts with no record of why. See
 * {@see RoleAdministrationRefusal::SystemRoleCannotBeEdited} for why that
 * refusal is a separate code from `role_is_immutable`.
 */
final readonly class UpdateRole
{
    public function __construct(
        private ConnectionInterface $connection,
        private RoleDirectoryInterface $roles,
        private AuditRecorderInterface $audit,
    ) {}

    /**
     * @param  array{name?: string, name_ar?: string|null, description?: string|null}  $attributes
     *
     * @throws RoleAdministrationRefused
     */
    public function handle(string $roleId, array $attributes): RoleView
    {
        $role = $this->roles->findRole($roleId);

        if (! $role instanceof RoleView) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::RoleNotFound);
        }

        // Ordered before the empty-body check on purpose: a caller poking at a
        // system role with an empty body should hear that the role is fixed,
        // not that they forgot a field.
        if ($role->isSystem) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::SystemRoleCannotBeEdited);
        }

        if ($attributes === []) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::NoFieldsSubmitted);
        }

        $name = $attributes['name'] ?? null;

        if ($name !== null && $this->roles->nameTaken($name, $role->id)) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::NameAlreadyTaken);
        }

        // `array_key_exists`, not `?? null`: `name_ar => null` clears the
        // column and must not be mistaken for "not submitted".
        $nameAr = $attributes['name_ar'] ?? null;

        if ($nameAr !== null && $this->roles->arabicNameTaken($nameAr, $role->id)) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::ArabicNameAlreadyTaken);
        }

        $before = self::snapshot($role);

        return $this->connection->transaction(function () use ($role, $attributes, $before): RoleView {
            $updated = $this->roles->updateRole($role->id, $attributes);

            $after = self::snapshot($updated);

            // An update that changed nothing writes no row, for the reason
            // `SyncRolePermissions` gives: `AUD-03` makes rows permanent, and a
            // screen that re-submits its form fills the log with entries that
            // record a click.
            if ($after !== $before) {
                $this->audit->record(
                    AuditEvent::of(IdentityAuditEvents::ROLE_UPDATED),
                    'role',
                    $role->id,
                    $before,
                    $after,
                );
            }

            return $updated;
        });
    }

    /**
     * The fields this endpoint can move, as `AUD-02`'s old and new values.
     *
     * The slug is included even though it can never change: an auditor reading
     * the row a year later needs to know *which* role it is, and the labels are
     * exactly the thing that just moved.
     *
     * @return array<string, string|null>
     */
    private static function snapshot(RoleView $role): array
    {
        return [
            'slug' => $role->slug,
            'name' => $role->name,
            'name_ar' => $role->nameAr,
            'description' => $role->description,
        ];
    }
}
