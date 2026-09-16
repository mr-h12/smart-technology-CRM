<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use App\Modules\Admin\Application\Money\CurrencyNotOffered;
use App\Modules\Admin\Application\Money\UpdateCurrencyRounding;
use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Money\Currency;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * §13 screen 5's editable half — "rounding unit per currency", and `D-65`'s
 * switch. The base currency and the rate history are the rest of that screen:
 * the rates are Point 3.3, and changing which currency is the base is not an
 * edit this endpoint offers (see `CHECKLIST.md`).
 *
 * Thin by rule: validate, invoke a use case, serialise.
 */
final class CurrencyController
{
    public function index(Request $request, CurrencyRepositoryInterface $currencies): JsonResponse
    {
        return ApiEnvelope::single($request, [
            'currencies' => array_map(self::payload(...), $currencies->all()),
        ]);
    }

    public function update(
        UpdateCurrencyRoundingRequest $request,
        string $code,
        UpdateCurrencyRounding $update,
    ): JsonResponse {
        $currency = CurrencyCode::tryFrom(strtoupper($code));

        if (! $currency instanceof CurrencyCode) {
            // Not a code this system knows at all. 404 for the same reason an
            // archived one is: the path names a resource that is not there.
            throw new NotFoundHttpException;
        }

        $unit = $request->validated('rounding_unit');
        $enabled = $request->validated('rounding_enabled');

        try {
            $updated = $update->handle(
                $currency,
                is_string($unit) ? $unit : null,
                is_bool($enabled) ? $enabled : null,
            );
        } catch (CurrencyNotOffered $refused) {
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }

        return ApiEnvelope::single($request, ['currency' => self::payload($updated)]);
    }

    /** @return array<string, mixed> */
    private static function payload(Currency $currency): array
    {
        return [
            // D-80: the uuid a supplier offer's `currency_id` has to name.
            'id' => $currency->id(),
            'code' => $currency->code()->value,

            // A string, never a float: DB-07, and the reason §5.3's 0.01 has to
            // survive a round trip through JSON intact.
            'rounding_unit' => $currency->rounding()->unit(),
            'rounding_enabled' => $currency->rounding()->isEnabled(),
            'is_base' => $currency->isBase(),
        ];
    }
}
