<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Domain\RoleAdministration\GrantDiff;
use App\Modules\Identity\Domain\RoleAdministration\PermissionView;
use App\Modules\Identity\Domain\RoleAdministration\ReferencePage;
use App\Modules\Identity\Domain\RoleAdministration\RoleView;

/**
 * §3.11's roles and permissions, serialised.
 *
 * ── Ids *and* triples, on purpose ──────────────────────────────────────────
 *
 * The SPA submits ids — `PATCH` takes `permission_ids` — and a human reads
 * triples. Sending only ids would make the matrix screen issue 143 lookups to
 * render one row; sending only triples would make the client parse §3.2's
 * notation to build a request. Both are here because they answer different
 * questions.
 *
 * No `created_by`/`updated_by` and no timestamps beyond what a screen shows:
 * who changed a grant is `audit_log`'s answer (`AUD-01`), not a column echoed
 * to every caller.
 */
final class RolePayload
{
    /** @return array<string, mixed> */
    public static function of(RoleView $role): array
    {
        return [
            'id' => $role->id,
            'slug' => $role->slug,
            'name' => $role->name,
            // `§3.1`'s eight. The screen uses it to explain why a role cannot
            // be renamed or retired; it does **not** decide whether the grants
            // may be edited — §3.12 rule 5 says they may, for every role except
            // the one §3.1 answers for unconditionally.
            'is_system' => $role->isSystem,
            'is_editable' => ! $role->hasUnconditionalAccess(),
            'description' => $role->description,
            'permissions' => self::permissions($role->permissions),
        ];
    }

    /**
     * @param  ReferencePage<RoleView>  $page
     * @return list<array<string, mixed>>
     */
    public static function manyRoles(ReferencePage $page): array
    {
        $items = [];

        foreach ($page->items as $role) {
            $items[] = self::of($role);
        }

        return $items;
    }

    /**
     * @param  ReferencePage<PermissionView>  $page
     * @return list<array<string, mixed>>
     */
    public static function manyPermissions(ReferencePage $page): array
    {
        return self::permissions($page->items);
    }

    /** @return array<string, mixed> */
    public static function diff(GrantDiff $diff): array
    {
        return [
            'granted' => $diff->granted,
            'revoked' => $diff->revoked,
            // The SPA shows "no changes" rather than a success toast that
            // implies something moved. The use case writes no audit row in this
            // case either, and the two must agree.
            'changed' => ! $diff->isEmpty(),
        ];
    }

    /**
     * `OpenAPI §4.2`'s six pagination keys, all of them written out.
     *
     * @param  ReferencePage<covariant object>  $page
     * @return array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}
     */
    public static function pagination(ReferencePage $page): array
    {
        return [
            'page' => $page->page,
            'per_page' => $page->perPage,
            'total' => $page->total,
            'total_pages' => $page->totalPages(),
            'has_next_page' => $page->hasNextPage(),
            'has_previous_page' => $page->hasPreviousPage(),
        ];
    }

    /**
     * @param  list<PermissionView>  $permissions
     * @return list<array<string, mixed>>
     */
    private static function permissions(array $permissions): array
    {
        $items = [];

        foreach ($permissions as $permission) {
            $items[] = [
                'id' => $permission->id,
                'resource' => $permission->resource,
                'action' => $permission->action,
                'scope' => $permission->scope,
                // §3.2's notation, sent rather than left to the client to
                // concatenate — a client that builds it itself is a second
                // implementation of the permission naming rule.
                'triple' => $permission->triple(),
            ];
        }

        return $items;
    }
}
