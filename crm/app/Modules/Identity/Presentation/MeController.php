<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Domain\Authentication\Profile;
use App\Modules\Identity\Domain\Contracts\ProfileReaderInterface;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/auth/me` — who the caller is, and what their role holds.
 *
 * The permission list is `SEC-07`'s matrix read from the database, and it is
 * what the SPA draws its menu from. It is **not** authorization: §3.12 rule 1
 * and `SEC-09` put every check at the API, so this array being wrong changes
 * which buttons appear and nothing else.
 */
final class MeController
{
    public function __invoke(Request $request, ProfileReaderInterface $profiles): JsonResponse
    {
        $user = $request->user();

        abort_if($user === null, 401);

        $accountId = $user->getAuthIdentifier();

        abort_if(! is_string($accountId) && ! is_int($accountId), 401);

        $profile = $profiles->for((string) $accountId);

        // The guard resolved a user this reader cannot describe — a row deleted
        // between the two queries. 401, not 500: the session no longer names
        // anybody.
        abort_if(! $profile instanceof Profile, 401);

        return ApiEnvelope::single($request, $profile->toArray());
    }
}
