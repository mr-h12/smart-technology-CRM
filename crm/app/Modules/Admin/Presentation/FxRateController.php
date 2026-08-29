<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use App\Modules\Admin\Application\Money\RecordFxRate;
use App\Modules\Admin\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\Admin\Domain\Listing\ListingQuery;
use App\Modules\Admin\Domain\Money\RecordedRate;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §13 screen 5's *manual rate per currency* and *rate history*, behind
 * §3.11's **FX rates** row — the one the Manager holds too.
 *
 * Two verbs and no third. `AP-06` makes a rate append-only, so there is no
 * `PATCH` and no `DELETE`: recording a new price is `POST`, and the previous
 * price stays in the history the `GET` returns.
 *
 * Thin by rule: parse, invoke a use case, serialise.
 */
final class FxRateController
{
    public function index(Request $request, FxRateRepositoryInterface $rates): JsonResponse
    {
        // `OpenAPI §6` is parsed in Domain rather than by a Form Request: a
        // failed Form Request is a 422 and §6.1 asks for a 400.
        $page = $rates->history(ListingQuery::fromQueryString($request->query()));

        return ApiEnvelope::collection(
            $request,
            array_map(self::payload(...), $page->items),
            $page->meta(),
        );
    }

    public function store(RecordFxRateRequest $request, RecordFxRate $record): JsonResponse
    {
        $recorded = $record->handle(
            $request->currency('from_currency'),
            $request->currency('to_currency'),
            $request->rate(),
            $request->effectiveFrom(),
        );

        return ApiEnvelope::single($request, ['fx_rate' => self::payload($recorded)], 201);
    }

    /** @return array<string, mixed> */
    private static function payload(RecordedRate $rate): array
    {
        return [
            'id' => $rate->id,
            'from_currency' => $rate->rate->from()->value,
            'to_currency' => $rate->rate->to()->value,

            // A string, never a float: `DB-07`, on the multiplier that reaches
            // every converted line of every quotation.
            'rate' => $rate->rate->rate(),

            // `DB-08`: UTC on the wire, converted for display only.
            'effective_from' => $rate->effectiveFrom->format(DATE_ATOM),
            'created_at' => $rate->recordedAt->format(DATE_ATOM),
        ];
    }
}
