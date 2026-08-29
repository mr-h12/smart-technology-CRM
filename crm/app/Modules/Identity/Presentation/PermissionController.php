<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\RoleAdministration\ListPermissions;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/permissions` — the assignable half of §3.11's role · permissions
 * row.
 *
 * A controller of its own rather than a method on {@see RoleController},
 * because `permissions` is a resource in `OpenAPI §7.1`'s sense and not a
 * sub-collection of a role: the same 143 rows are the input to every role's
 * grid, and hanging them off `/roles` would imply otherwise.
 */
final class PermissionController
{
    public function index(Request $request, ListPermissions $permissions): JsonResponse
    {
        $page = $permissions->handle(ReferenceListCriteria::forPermissions($request->query()));

        return ApiEnvelope::collection(
            $request,
            RolePayload::manyPermissions($page),
            RolePayload::pagination($page),
        );
    }
}
