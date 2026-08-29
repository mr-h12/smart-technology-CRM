<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Administration\CreateUser;
use App\Modules\Identity\Application\Administration\ListUsers;
use App\Modules\Identity\Application\Administration\ListUserSessions;
use App\Modules\Identity\Application\Administration\SetUserActivation;
use App\Modules\Identity\Application\Administration\TerminateUserSession;
use App\Modules\Identity\Application\Administration\UpdateUser;
use App\Modules\Identity\Domain\Administration\UserListCriteria;
use App\Modules\Identity\Domain\Authentication\SessionAttribute;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * §3.11's user administration, as `OpenAPI §7.1`'s conventional resource routes
 * plus §7.2's two documented actions.
 *
 * ── Why one controller with six methods and not six invokables ─────────────
 *
 * The auth endpoints are single actions and are written as single-action
 * classes. This is a resource, and §7.1 names it as one — splitting a `GET`
 * index from a `GET` show buys nothing here and loses the one place a reader
 * can see the whole surface.
 *
 * The methods do what `CLAUDE.md` allows a controller to do and nothing else:
 * validate, invoke a use case, serialise. Every rule — rule 7, `D-28`, `D-34`,
 * the audit rows — lives behind the Application boundary, where a second entry
 * point would find it.
 */
final class UserController
{
    public function index(Request $request, ListUsers $users): JsonResponse
    {
        // Parsed in Domain rather than by a Form Request: OpenAPI §6.1 and §6.2
        // require `400 invalid_request` for a bad page size or an unknown
        // filter, and a Form Request failure is a 422.
        $page = $users->handle(UserListCriteria::fromQuery($request->query()));

        return ApiEnvelope::collection(
            $request,
            UserPayload::many($page, self::locale()),
            UserPayload::pagination($page),
        );
    }

    public function show(Request $request, string $user, ListUsers $users): JsonResponse
    {
        return ApiEnvelope::single($request, UserPayload::of($users->one($user), self::locale()));
    }

    public function store(CreateUserRequest $request, CreateUser $create): JsonResponse
    {
        $created = $create->handle(
            self::actorId($request),
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->string('role_id')->toString(),
        );

        // 201: OpenAPI §4.1 covers POST create with the single-resource
        // envelope, and §7.1 makes this a conventional resource creation.
        return ApiEnvelope::single($request, UserPayload::of($created, self::locale()), 201);
    }

    public function update(UpdateUserRequest $request, string $user, UpdateUser $update): JsonResponse
    {
        /** @var array{name?: string, email?: string, role_id?: string} $submitted */
        $submitted = $request->safe()->only(['name', 'email', 'role_id']);

        $updated = $update->handle(self::actorId($request), $user, $submitted);

        return ApiEnvelope::single($request, UserPayload::of($updated, self::locale()));
    }

    public function deactivate(Request $request, string $user, SetUserActivation $activation): JsonResponse
    {
        return self::activationResponse($request, $activation->handle($user, false));
    }

    public function reactivate(Request $request, string $user, SetUserActivation $activation): JsonResponse
    {
        return self::activationResponse($request, $activation->handle($user, true));
    }

    /**
     * §13 screen 2 — "devices · IP · browser" for one employee.
     *
     * The same payload `SEC-05`'s own screen renders, so a fingerprint cannot
     * leak here after being kept out there. The caller's session id is passed
     * through so an administrator inspecting their own account sees which row
     * is the one they are looking through.
     */
    public function sessions(Request $request, string $user, ListUserSessions $sessions): JsonResponse
    {
        $page = $sessions->handle(
            $user,
            self::callerSessionId($request),
            // Parsed in Domain rather than by a Form Request: OpenAPI §6.1 and
            // §6.2 require `400 invalid_request` for a bad page size or an
            // unknown filter, and a Form Request failure is a 422.
            ReferenceListCriteria::forSessions($request->query()),
        );

        return ApiEnvelope::collection(
            $request,
            SessionPayload::many($page),
            SessionPayload::pagination($page),
        );
    }

    /** §13 screen 2's force logout, one device at a time. */
    public function terminateSession(
        Request $request,
        string $user,
        string $session,
        TerminateUserSession $terminate,
    ): JsonResponse {
        $terminate->handle($user, $session, self::callerSessionId($request));

        // 200 with a body rather than 204, for the reason `SessionController`
        // gives: `OpenAPI §3.3` puts a request id on every response and §4.1
        // puts it in `meta`, and a 204 has no body to carry one.
        return ApiEnvelope::single($request, ['revoked' => true, 'session_id' => $session]);
    }

    /**
     * The locale `SetLocaleFromRequest` negotiated for this request.
     *
     * Read from the application, which is where that middleware puts it
     * (`app()->setLocale($locale)`) — not from `$request->getLocale()`, which
     * is Symfony's own field and is never written by it.
     */
    private static function locale(): string
    {
        return App::getLocale();
    }

    /** The device the administrator is holding, as the guard resolved it. */
    private static function callerSessionId(Request $request): ?string
    {
        $sessionId = $request->attributes->get(SessionAttribute::NAME);

        return is_string($sessionId) ? $sessionId : null;
    }

    /** @param array{user: \App\Modules\Identity\Domain\Administration\AdministeredUser, sessions_revoked: int, changed: bool} $result */
    private static function activationResponse(Request $request, array $result): JsonResponse
    {
        return ApiEnvelope::single($request, UserPayload::of($result['user'], self::locale()) + [
            // How many devices this took down (SEC-05). Zero on a reactivation
            // and on a call that changed nothing, which `changed` distinguishes
            // from a deactivation of somebody who was never signed in.
            'sessions_revoked' => $result['sessions_revoked'],
            'changed' => $result['changed'],
        ]);
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
