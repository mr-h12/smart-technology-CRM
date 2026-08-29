<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\RoleAdministration\ArchiveRole;
use App\Modules\Identity\Application\RoleAdministration\CreateRole;
use App\Modules\Identity\Application\RoleAdministration\ListRoles;
use App\Modules\Identity\Application\RoleAdministration\SyncRolePermissions;
use App\Modules\Identity\Application\RoleAdministration\UpdateRole;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

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
        $page = $roles->handle(
            self::actorId($request),
            // Parsed in Domain rather than by a Form Request: OpenAPI §6.1 and
            // §6.2 require `400 invalid_request` for a bad page size or an
            // unknown filter, and a Form Request failure is a 422.
            ReferenceListCriteria::forRoles($request->query()),
        );

        return ApiEnvelope::collection(
            $request,
            RolePayload::manyRoles($page, self::locale()),
            RolePayload::pagination($page),
        );
    }

    public function show(Request $request, string $role, ListRoles $roles): JsonResponse
    {
        return ApiEnvelope::single($request, RolePayload::of($roles->one($role), self::locale()));
    }

    public function store(CreateRoleRequest $request, CreateRole $create): JsonResponse
    {
        $created = $create->handle(
            $request->slug(),
            $request->name(),
            $request->nameAr(),
            $request->description(),
            $request->permissionIds(),
        );

        // 201: OpenAPI §4.1 covers POST create with the single-resource
        // envelope, and §7.1 makes this a conventional resource creation.
        return ApiEnvelope::single($request, RolePayload::of($created, self::locale()), 201);
    }

    public function update(UpdateRoleRequest $request, string $role, UpdateRole $update): JsonResponse
    {
        $updated = $update->handle($role, $request->submitted());

        return ApiEnvelope::single($request, RolePayload::of($updated, self::locale()));
    }

    public function destroy(Request $request, string $role, ArchiveRole $archive): JsonResponse
    {
        $archive->handle($role);

        // 200 with a body rather than 204, for the reason `SessionController`
        // gives: `OpenAPI §3.3` puts a request id on every response and §4.1
        // puts it in `meta`, and a 204 has no body to carry one.
        //
        // `archived`, not `deleted`: `DB-01` soft-deletes and the row is still
        // there. A field that said otherwise would be the API telling the SPA
        // something untrue about storage.
        return ApiEnvelope::single($request, ['archived' => true, 'role_id' => $role]);
    }

    public function updatePermissions(
        SyncRolePermissionsRequest $request,
        string $role,
        SyncRolePermissions $sync,
    ): JsonResponse {
        $result = $sync->handle($role, $request->permissionIds());

        return ApiEnvelope::single($request, RolePayload::of($result['role'], self::locale()) + [
            // What actually moved, so the screen can say "3 granted, 1 revoked"
            // instead of "saved". It is the same diff the audit row keeps.
            'diff' => RolePayload::diff($result['diff']),
        ]);
    }

    /**
     * The locale `SetLocaleFromRequest` negotiated for this request.
     *
     * Read from the application, which is where that middleware puts it
     * (`app()->setLocale($locale)`) — **not** from `$request->getLocale()`,
     * which is Symfony's own field and is never written by it. Asking the
     * `Accept-Language` header again here would be a second implementation of
     * §14.2's negotiation, and the two would disagree the first time the header
     * carried a weighted list.
     */
    private static function locale(): string
    {
        return App::getLocale();
    }

    private static function actorId(Request $request): string
    {
        $actor = $request->user();

        abort_if($actor === null, 401);

        $id = $actor->getAuthIdentifier();

        abort_if(! is_string($id) && ! is_int($id), 401);

        return (string) $id;
    }
}
