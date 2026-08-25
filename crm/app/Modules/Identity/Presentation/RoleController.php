<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\RoleAdministration\ListRoles;
use App\Modules\Identity\Application\RoleAdministration\SyncRolePermissions;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §3.11's "create / edit role · permissions", as `OpenAPI §7.1`'s resource
 * routes.
 *
 * The methods do what `CLAUDE.md` allows a controller to do and nothing else:
 * validate, invoke a use case, serialise. Every rule — the Super Admin
 * exception, §3.12 rule 3, the audit row — lives behind the Application
 * boundary, where a second entry point would find it.
 */
final class RoleController
{
    public function index(Request $request, ListRoles $roles): JsonResponse
    {
        // Parsed in Domain rather than by a Form Request: OpenAPI §6.1 and §6.2
        // require `400 invalid_request` for a bad page size or an unknown
        // filter, and a Form Request failure is a 422.
        $page = $roles->handle(ReferenceListCriteria::forRoles($request->query()));

        return ApiEnvelope::collection(
            $request,
            RolePayload::manyRoles($page),
            RolePayload::pagination($page),
        );
    }

    public function show(Request $request, string $role, ListRoles $roles): JsonResponse
    {
        return ApiEnvelope::single($request, RolePayload::of($roles->one($role)));
    }

    public function updatePermissions(
        SyncRolePermissionsRequest $request,
        string $role,
        SyncRolePermissions $sync,
    ): JsonResponse {
        $result = $sync->handle($role, $request->permissionIds());

        return ApiEnvelope::single($request, RolePayload::of($result['role']) + [
            // What actually moved, so the screen can say "3 granted, 1 revoked"
            // instead of "saved". It is the same diff the audit row keeps.
            'diff' => RolePayload::diff($result['diff']),
        ]);
    }
}
