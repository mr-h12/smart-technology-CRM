<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation;

use App\Modules\Customers\Application\Archiving\ArchiveCustomer;
use App\Modules\Customers\Application\Listing\ListCustomers;
use App\Modules\Customers\Application\Writing\SaveCustomer;
use App\Modules\Customers\Domain\Listing\CustomerListCriteria;
use App\Modules\Customers\Domain\Writing\CustomerWriteResult;
use App\Modules\Identity\Domain\Rbac\AuthorizationAttribute;
use App\Modules\Identity\Domain\Rbac\PermissionDecision;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * §10's customer list and detail, as `OpenAPI §7.1`'s conventional routes.
 *
 * The methods do what `CLAUDE.md` allows a controller to do and nothing else:
 * validate, invoke a use case, serialise. The reach comes off the request
 * because `AuthorizePermission` has already resolved it — making the controller
 * resolve it again would be two answers to one question, and `SEC-08` is not a
 * question worth answering twice.
 */
final class CustomerController
{
    public function index(Request $request, ListCustomers $customers): JsonResponse
    {
        // Parsed in Domain rather than by a Form Request: OpenAPI §6.1 and §6.2
        // require `400 invalid_request` for a bad page size or an unknown
        // filter, and a Form Request failure is a 422.
        $page = $customers->handle(
            CustomerListCriteria::fromQuery($request->query()),
            self::heldScopes($request),
            self::actorId($request),
        );

        return ApiEnvelope::collection($request, CustomerPayload::many($page), CustomerPayload::pagination($page));
    }

    public function show(Request $request, string $customer, ListCustomers $customers): JsonResponse
    {
        return ApiEnvelope::single($request, CustomerPayload::of(
            $customers->one($customer, self::heldScopes($request), self::actorId($request)),
        ));
    }

    public function store(SaveCustomerRequest $request, SaveCustomer $customers): JsonResponse
    {
        return self::saved($request, $customers->create(
            $request->validated(),
            self::heldScopes($request),
            self::actorId($request),
        ), 201);
    }

    public function update(SaveCustomerRequest $request, string $customer, SaveCustomer $customers): JsonResponse
    {
        return self::saved($request, $customers->update(
            $customer,
            $request->validated(),
            self::heldScopes($request),
            self::actorId($request),
        ));
    }

    public function archive(Request $request, string $customer, ArchiveCustomer $customers): JsonResponse
    {
        return ApiEnvelope::single($request, CustomerPayload::of(
            $customers->archive($customer, self::heldScopes($request), self::actorId($request)),
        ));
    }

    public function restore(Request $request, string $customer, ArchiveCustomer $customers): JsonResponse
    {
        return ApiEnvelope::single($request, CustomerPayload::of(
            $customers->restore($customer, self::heldScopes($request), self::actorId($request)),
        ));
    }

    /**
     * The record, plus `D-35`'s warning when there is one.
     *
     * The key is absent rather than an empty array when nothing is similar: a
     * client checking `meta.similar_customers` for truthiness and one checking
     * for the key both get the same answer, and the common response does not
     * carry a field describing something that did not happen.
     */
    private static function saved(Request $request, CustomerWriteResult $result, int $status = 200): JsonResponse
    {
        $similar = CustomerPayload::similar($result->similar);

        return ApiEnvelope::single(
            $request,
            CustomerPayload::of($result->customer),
            $status,
            $similar === [] ? [] : ['similar_customers' => $similar],
        );
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
            // The route carries `permission:customer.view`, so the attribute is
            // always there. Reaching here means the route lost its middleware,
            // and answering with an empty scope would turn that into a quiet
            // empty list instead of the configuration error it is.
            throw new RuntimeException('The customer routes require the permission middleware.');
        }

        return $decision->scopeValues();
    }

    private static function actorId(Request $request): string
    {
        $user = $request->user();

        if ($user === null) {
            throw new RuntimeException('The customer routes require authentication.');
        }

        $id = $user->getAuthIdentifier();

        // Narrowed rather than cast. `Coding Standards` forbids the untyped
        // escape hatch, and `AuthorizePermission` already refuses the same two
        // shapes at the boundary — this is that check, not a second opinion.
        if (! is_string($id) && ! is_int($id)) {
            throw new RuntimeException('The authenticated user has no usable identifier.');
        }

        return (string) $id;
    }
}
