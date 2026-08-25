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
    /**
     * @param  string  $locale  the request's locale, as `SetLocaleFromRequest` resolved it
     * @return array<string, mixed>
     */
    public static function of(RoleView $role, string $locale): array
    {
        return [
            'id' => $role->id,
            'slug' => $role->slug,
            // The two stored labels, and the one to print.
            //
            // `label` is resolved by the **server** because the fallback is a
            // rule, not a formatting choice: §3.1's eight roles carry no Arabic
            // name — the master documentation does not contain one — so an
            // Arabic screen must fall back to English for them and must not for
            // a custom role that has one. A client computing that is a second
            // implementation of the rule, and the copy that is wrong is always
            // the one in the screen (the same argument `is_grantable` carries).
            'name' => $role->name,
            'name_ar' => $role->nameAr,
            'label' => $role->label($locale),
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
    public static function manyRoles(ReferencePage $page, string $locale): array
    {
        $items = [];

        foreach ($page->items as $role) {
            $items[] = self::of($role, $locale);
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
                // §3.12 rule 3, sent for the same reason (Point 5.3). The
                // matrix screen has to draw a locked checkbox rather than one
                // the API will refuse, and the only alternative was a second
                // copy of the forbidden list in TypeScript.
                //
                // ⚠️ Today this is `true` on all 143 rows: a forbidden key has
                // no `permissions` row at all, so the seeder never creates one
                // for it. The flag is what keeps that from being load-bearing —
                // a later module that adds `customer.delete.all` gets a locked
                // checkbox and a refused PATCH, instead of a checkbox that
                // works right up to the server.
                'is_grantable' => $permission->isGrantable(),
            ];
        }

        return $items;
    }
}
