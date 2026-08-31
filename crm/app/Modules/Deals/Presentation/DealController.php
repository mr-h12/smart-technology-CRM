<?php

declare(strict_types=1);

namespace App\Modules\Deals\Presentation;

use App\Modules\Deals\Application\Approval\ChangeDealStatus;
use App\Modules\Deals\Application\Approval\ReviewDealApproval;
use App\Modules\Deals\Application\Assignment\AssignDeal;
use App\Modules\Deals\Application\Listing\ListDeals;
use App\Modules\Deals\Application\Writing\SaveDeal;
use App\Modules\Deals\Domain\Listing\DealListCriteria;
use App\Modules\Identity\Domain\Rbac\AuthorizationAttribute;
use App\Modules\Identity\Domain\Rbac\PermissionDecision;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * §10's deal list and detail, as `OpenAPI §7.1`'s conventional routes —
 * `CustomerController`'s shape (Module 3 Point 3.2), on the same reasoning.
 *
 * The methods do what `CLAUDE.md` allows a controller to do and nothing else:
 * validate, invoke a use case, serialise. The reach comes off the request
 * because `AuthorizePermission` has already resolved it.
 */
final class DealController
{
    public function index(Request $request, ListDeals $deals): JsonResponse
    {
        // Parsed in Domain rather than by a Form Request: OpenAPI §6.1 and §6.2
        // require `400 invalid_request` for a bad page size or an unknown
        // filter, and a Form Request failure is a 422.
        $page = $deals->handle(
            DealListCriteria::fromQuery($request->query()),
            self::heldScopes($request),
            self::actorId($request),
        );

        return ApiEnvelope::collection($request, DealPayload::many($page), DealPayload::pagination($page));
    }

    public function show(Request $request, string $deal, ListDeals $deals): JsonResponse
    {
        return ApiEnvelope::single($request, DealPayload::of(
            $deals->one($deal, self::heldScopes($request), self::actorId($request)),
        ));
    }

    public function store(SaveDealRequest $request, SaveDeal $deals): JsonResponse
    {
        return ApiEnvelope::single($request, DealPayload::of($deals->create(
            $request->validated(),
            self::heldScopes($request),
            self::actorId($request),
        )), 201);
    }

    public function update(SaveDealRequest $request, string $deal, SaveDeal $deals): JsonResponse
    {
        return ApiEnvelope::single($request, DealPayload::of($deals->update(
            $deal,
            $request->validated(),
            self::heldScopes($request),
            self::actorId($request),
        )));
    }

    public function assign(AssignDealRequest $request, string $deal, AssignDeal $deals): JsonResponse
    {
        return ApiEnvelope::single($request, DealPayload::of(
            $deals->handle($deal, $request->ownerId(), self::heldScopes($request), self::actorId($request)),
        ));
    }

    public function approve(Request $request, string $deal, ReviewDealApproval $deals): JsonResponse
    {
        return ApiEnvelope::single($request, DealPayload::of(
            $deals->approve($deal, self::heldScopes($request), self::actorId($request)),
        ));
    }

    public function reject(RejectDealRequest $request, string $deal, ReviewDealApproval $deals): JsonResponse
    {
        return ApiEnvelope::single($request, DealPayload::of(
            $deals->reject($deal, $request->reason(), self::heldScopes($request), self::actorId($request)),
        ));
    }

    public function changeStatus(ChangeDealStatusRequest $request, string $deal, ChangeDealStatus $deals): JsonResponse
    {
        return ApiEnvelope::single($request, DealPayload::of($deals->handle(
            $deal,
            $request->status(),
            $request->reason(),
            self::heldScopes($request),
            self::actorId($request),
        )));
    }

    /**
     * §3.2's scope codes for this caller, as the middleware left them.
     *
     * @return list<string>
     */
    private static function heldScopes(Request $request): array
    {
        $decision = $request->attributes->get(AuthorizationAttribute::NAME);

        if (! $decision instanceof PermissionDecision) {
            // The route carries `permission:deal.view`, so the attribute is
            // always there. Reaching here means the route lost its middleware,
            // and answering with an empty scope would turn that into a quiet
            // empty list instead of the configuration error it is.
            throw new RuntimeException('The deal routes require the permission middleware.');
        }

        return $decision->scopeValues();
    }

    private static function actorId(Request $request): string
    {
        $user = $request->user();

        if ($user === null) {
            throw new RuntimeException('The deal routes require authentication.');
        }

        $id = $user->getAuthIdentifier();

        if (! is_string($id) && ! is_int($id)) {
            throw new RuntimeException('The authenticated user has no usable identifier.');
        }

        return (string) $id;
    }
}
