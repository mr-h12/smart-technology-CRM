<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use App\Modules\Admin\Application\Settings\UpdateSettings;
use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §13 screen 4 — "System Settings", read and written.
 *
 * Thin by rule (`CLAUDE.md`, Laravel conventions): validate at the boundary,
 * invoke a use case, serialise. The permission lives on the route.
 *
 * The reading returns **every** known field, valued or null, because §13 draws
 * a form: a field the response omits is a field the screen cannot render.
 */
final class SettingsController
{
    public function index(Request $request, SettingsRepositoryInterface $settings): JsonResponse
    {
        return ApiEnvelope::single($request, ['settings' => $settings->all()]);
    }

    public function update(UpdateSettingsRequest $request, UpdateSettings $update): JsonResponse
    {
        /** @var array<string, string> $values */
        $values = $request->validated()['settings'];

        return ApiEnvelope::single($request, ['settings' => $update->handle($values)]);
    }
}
