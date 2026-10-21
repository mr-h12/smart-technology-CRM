<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Presentation;

use App\Modules\Dashboard\Application\DashboardMeta;
use App\Modules\Dashboard\Application\DashboardOverview;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Module 14 (`D-146`). Every route here carries `permission:dashboard.view`;
 * what a holder sees beyond that is `DashboardMeta`'s answer.
 */
final class DashboardController
{
    public function meta(Request $request, DashboardMeta $meta): JsonResponse
    {
        return ApiEnvelope::single($request, $meta->for(self::actorId($request)));
    }

    public function overview(DashboardPeriodRequest $request, DashboardOverview $overview): JsonResponse
    {
        return ApiEnvelope::single($request, $overview->for(self::actorId($request), $request->from(), $request->to()));
    }

    private static function actorId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        if (! is_string($id) && ! is_int($id)) {
            throw new RuntimeException('The dashboard routes require authentication.');
        }

        return (string) $id;
    }
}
