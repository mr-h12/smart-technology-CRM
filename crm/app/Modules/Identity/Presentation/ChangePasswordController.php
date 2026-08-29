<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Authentication\ChangePassword;
use App\Support\Http\ApiEnvelope;
use DateTimeImmutable;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/auth/change-password`.
 *
 * Behind `auth`, because §9 Flow 0's change is something a signed-in person
 * does to their own account. There is no `user_id` parameter and there will not
 * be one: changing somebody *else's* password is an administrative action under
 * §3.11, and giving this endpoint a target would make it that action without
 * the permission check that action needs.
 *
 * **`SEC-04`'s emailed code is required here** as of Point 3.3: the caller
 * presents `verification_code` alongside the current and new passwords, and
 * {@see PasswordChallengeController} is
 * where they obtain one. §9 Flow 0 now reads end to end.
 */
final class ChangePasswordController
{
    public function __invoke(
        ChangePasswordRequest $request,
        ChangePassword $change,
        ConfigRepository $config,
    ): JsonResponse {
        $user = $request->user();

        abort_if($user === null, 401);

        $accountId = $user->getAuthIdentifier();

        abort_if(! is_string($accountId) && ! is_int($accountId), 401);

        $revoked = $change->handle(
            (string) $accountId,
            $request->string('current_password')->toString(),
            $request->string('new_password')->toString(),
            $request->string('verification_code')->toString(),
            new DateTimeImmutable,
            $config->integer('identity.password_challenge.max_verification_attempts'),
        );

        return ApiEnvelope::single($request, [
            'password_changed' => true,
            // §9 Flow 0 ends with "log in again", and this is the machine-
            // readable form of that: the token used to make this call is one of
            // the sessions counted here, and it is already dead.
            'sessions_revoked' => $revoked,
            'reauthentication_required' => true,
        ]);
    }
}
