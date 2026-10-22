<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Presentation;

use App\Modules\Notifications\Application\MyAlerts;
use App\Modules\Notifications\Domain\UnknownAlert;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Module 16, Point 1 (`D-153`) — the bell's four calls. `auth` only, like
 * `GET /badges`: every signed-in person reads the alerts addressed to them.
 */
final class AlertController
{
    public function index(Request $request, MyAlerts $alerts): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $found = $alerts->page(self::actorId($request), $page);
        $pages = max(1, (int) ceil($found['total'] / MyAlerts::PER_PAGE));

        return ApiEnvelope::collection($request, $found['items'], [
            'page' => $page,
            'per_page' => MyAlerts::PER_PAGE,
            'total' => $found['total'],
            'total_pages' => $pages,
            'has_next_page' => $page < $pages,
            'has_previous_page' => $page > 1,
        ]);
    }

    public function unreadCount(Request $request, MyAlerts $alerts): JsonResponse
    {
        return ApiEnvelope::single($request, ['unread' => $alerts->unread(self::actorId($request))]);
    }

    public function read(Request $request, MyAlerts $alerts, string $alert): JsonResponse
    {
        try {
            $unread = $alerts->markRead(self::actorId($request), $alert);
        } catch (UnknownAlert) {
            throw new NotFoundHttpException;
        }

        return ApiEnvelope::single($request, ['unread' => $unread]);
    }

    public function readAll(Request $request, MyAlerts $alerts): JsonResponse
    {
        return ApiEnvelope::single($request, ['unread' => $alerts->markAllRead(self::actorId($request))]);
    }

    private static function actorId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        if (! is_string($id) && ! is_int($id)) {
            throw new RuntimeException('The alert routes require authentication.');
        }

        return (string) $id;
    }
}
