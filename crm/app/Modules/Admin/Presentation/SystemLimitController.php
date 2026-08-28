<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use App\Modules\Admin\Application\Settings\UpdateSystemLimits;
use App\Modules\Admin\Domain\Contracts\SystemLimitRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §13 screen 6 — "Limits & SLAs", read and written.
 *
 * The reading returns **every** declared limit, valued or null, because §13
 * draws a form: a field the response omits is a field the screen cannot render,
 * and five of the six have no documented value yet.
 *
 * Not paginated, and it is not an oversight: `OpenAPI §4.2` governs list
 * endpoints, and this is a form keyed by name — the same shape `GET /settings`
 * returns, bounded by an enum rather than by a page size.
 */
final class SystemLimitController
{
    public function index(Request $request, SystemLimitRepositoryInterface $limits): JsonResponse
    {
        return ApiEnvelope::single($request, ['limits' => $limits->all()]);
    }

    public function update(UpdateSystemLimitsRequest $request, UpdateSystemLimits $update): JsonResponse
    {
        /** @var array<string, string> $values */
        $values = $request->validated()['limits'];

        return ApiEnvelope::single($request, ['limits' => $update->handle($values)]);
    }
}
