<?php

declare(strict_types=1);

use App\Http\Middleware\ForgetResolvedGuards;
use App\Http\Middleware\SetLocaleFromRequest;
use App\Modules\Admin\Domain\Listing\InvalidListingQuery;
use App\Modules\Audit\Presentation\EnsureAuditPartitionsCommand;
use App\Modules\Catalog\Domain\Listing\CatalogItemNotFound;
use App\Modules\Catalog\Domain\Listing\InvalidCatalogItemListQuery;
use App\Modules\Customers\Domain\Listing\CustomerNotFound;
use App\Modules\Customers\Domain\Listing\InvalidCustomerListQuery;
use App\Modules\Customers\Presentation\ClearIncompleteCustomersCommand;
use App\Modules\Deals\Domain\Approval\DealApprovalRefused;
use App\Modules\Deals\Domain\Approval\DealStatusTransitionRefused;
use App\Modules\Deals\Domain\Contracts\DealNotReadyToSend;
use App\Modules\Deals\Domain\Listing\DealNotFound;
use App\Modules\Deals\Domain\Listing\InvalidDealListQuery;
use App\Modules\Idempotency\Domain\IdempotencyRefused;
use App\Modules\Idempotency\Presentation\RequireIdempotencyKey;
use App\Modules\Identity\Domain\Administration\InvalidListQuery;
use App\Modules\Identity\Domain\Administration\UserAdministrationRefused;
use App\Modules\Identity\Domain\Authentication\AuthenticationRefused;
use App\Modules\Identity\Domain\Authentication\PasswordChangeRefused;
use App\Modules\Identity\Domain\Authentication\SessionRevocationRefused;
use App\Modules\Identity\Domain\Impersonation\ImpersonationRefused;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefused;
use App\Modules\Identity\Presentation\AuthorizePermission;
use App\Modules\Identity\Presentation\VerifyPermissionMatrixCommand;
use App\Modules\Quotations\Domain\Listing\InvalidQuotationListQuery;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderNotFound;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Pricing\QuotationNotPriceable;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use App\Modules\Quotations\Presentation\ExpireQuotationsCommand;
use App\Modules\Storage\Domain\Exceptions\UploadRejected;
use App\Modules\SupplierQuotations\Domain\Listing\InvalidSupplierQuotationListQuery;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationNotFound;
use App\Modules\Suppliers\Domain\Listing\InvalidSupplierListQuery;
use App\Modules\Suppliers\Domain\Listing\SupplierNotFound;
use App\Modules\Suppliers\Presentation\ClearIncompleteSuppliersCommand;
use App\Support\Http\ApiExceptionRenderer;
use App\Support\Performance\MeasureApiLatencyCommand;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // API-02: versioned from day one. Set here rather than per route, so
        // nothing can be published unversioned by omission.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Laravel discovers commands in app/Console/Commands and nowhere else, and
    // this project puts a module's entry points inside the module (AP-02). So
    // every module command is named here; without this the class exists, the
    // scheduler resolves its signature, and `artisan` still answers "The
    // command ... does not exist".
    ->withCommands([
        EnsureAuditPartitionsCommand::class,
        // PRF-01's measurement tool. Not a module command — it belongs to no
        // business domain and sits in app/Support, which Laravel discovers just
        // as little as it discovers app/Modules.
        MeasureApiLatencyCommand::class,
        // SEC-07's live matrix, checked against §3. A module command, so it
        // has to be named here for the same reason the audit one does.
        VerifyPermissionMatrixCommand::class,
        // `D-87`'s one-off correction, one per module (F-11 · 1.4).
        ClearIncompleteSuppliersCommand::class,
        ClearIncompleteCustomersCommand::class,
        // `J-01`'s startup catch-up (`D-55`), run by the `scheduler` service.
        ExpireQuotationsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs on every request, web and API alike: §14.2 requires Arabic and
        // English from the first release, and a locale resolved per request is
        // what makes RTL/LTR a function of the request rather than of duplicated
        // screens (Coding Standards §11).
        $middleware->append(SetLocaleFromRequest::class);

        // Before anything can ask who the caller is. A RequestGuard memoises
        // the user it resolved, and AuthManager memoises the guard, so without
        // this a long-lived worker answers the second request as the first
        // request's user — measured on logout, which returned 200 and then let
        // the revoked token straight back in.
        $middleware->append(ForgetResolvedGuards::class);

        // OpenAPI §3.3 — every response carries a server-generated request id.
        $middleware->append(App\Http\Middleware\AddRequestId::class);

        // An unauthenticated API call must be answered, not redirected.
        // Laravel's default sends a guest to route('login'); this application
        // has no such route (D-67 puts login in the SPA), so the redirect threw
        // RouteNotFoundException and the caller received **500 instead of 401**
        // — measured in Point 5.4, on the first request to a route behind
        // `auth`. Returning null keeps the AuthenticationException unresolved
        // into a redirect, which the handler then renders as the 401 JSON
        // OpenAPI §4 requires.
        // SEC-09 · §3.12 rule 1 — the API-level permission check, as a route
        // alias so a protected route reads `permission:customer.view` and the
        // ability it needs is visible in routes/api.php rather than buried in a
        // controller.
        // OpenAPI §9.1 — `Idempotency-Key` on a critical create, as a route
        // alias for the same reason: the route says `idempotency` and the
        // requirement is visible beside the permission it follows.
        $middleware->alias([
            AuthorizePermission::ALIAS => AuthorizePermission::class,
            RequireIdempotencyKey::ALIAS => RequireIdempotencyKey::class,
        ]);

        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : '/',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ApiExceptionRenderer::applies($request),
        );

        // OpenAPI §5: every non-2xx answer uses the unified envelope. Laravel's
        // defaults do not — a validation failure is `{"message":…,"errors":…}`
        // and a 401 is `{"message":"Unauthenticated."}`, neither of which has a
        // `meta.request_id` a support report can quote. These four are the
        // shapes Module 1 can produce; §5.1's other nine arrive with the
        // modules that can raise them.
        $exceptions->render(
            fn (AuthenticationRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::refusal($e, $request)
                : null,
        );

        $exceptions->render(
            fn (AuthorizationRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::authorization($e, $request)
                : null,
        );

        $exceptions->render(
            fn (PasswordChangeRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::passwordChange($e, $request)
                : null,
        );

        $exceptions->render(
            fn (SessionRevocationRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::sessionRevocation($e, $request)
                : null,
        );

        $exceptions->render(
            fn (ImpersonationRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::impersonation($e, $request)
                : null,
        );

        $exceptions->render(
            fn (UserAdministrationRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::administration($e, $request)
                : null,
        );

        $exceptions->render(
            fn (RoleAdministrationRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::roleAdministration($e, $request)
                : null,
        );

        $exceptions->render(
            fn (InvalidListQuery $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::invalidListQuery($e, $request)
                : null,
        );

        $exceptions->render(
            fn (InvalidListingQuery $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::invalidListingQuery($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (InvalidCustomerListQuery $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::invalidCustomerListQuery($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (CustomerNotFound $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::customerNotFound($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (InvalidSupplierListQuery $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::invalidSupplierListQuery($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (SupplierNotFound $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::supplierNotFound($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (InvalidSupplierQuotationListQuery $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::invalidSupplierQuotationListQuery($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (SupplierQuotationNotFound $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::supplierQuotationNotFound($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (InvalidCatalogItemListQuery $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::invalidCatalogItemListQuery($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (CatalogItemNotFound $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::catalogItemNotFound($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (InvalidDealListQuery $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::invalidDealListQuery($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (DealNotFound $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::dealNotFound($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (DealApprovalRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::dealApprovalRefused($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (DealStatusTransitionRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::dealStatusTransitionRefused($e, $request)
                : null,
        );

        $exceptions->renderable(
            fn (DealNotReadyToSend $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::dealNotReadyToSend($request)
                : null,
        );

        $exceptions->renderable(
            fn (UploadRejected $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::uploadRejected($e, $request)
                : null,
        );

        $exceptions->render(
            fn (QuotationNotPriceable $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::quotationNotPriceable($e, $request)
                : null,
        );
        $exceptions->render(
            fn (InvalidQuotationListQuery $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::invalidQuotationListQuery($e, $request)
                : null,
        );
        $exceptions->render(
            fn (QuotationNotFound $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::quotationNotFound($e, $request)
                : null,
        );
        $exceptions->render(
            fn (PurchaseOrderNotFound $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::purchaseOrderNotFound($e, $request)
                : null,
        );
        $exceptions->render(
            fn (QuotationWriteRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::quotationWriteRefused($e, $request)
                : null,
        );
        $exceptions->render(
            fn (IdempotencyRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::idempotencyRefused($e, $request)
                : null,
        );

        $exceptions->render(
            fn (ValidationException $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::validation($e, $request)
                : null,
        );

        $exceptions->render(
            fn (AuthenticationException $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::unauthenticated($e, $request)
                : null,
        );

        // Registered before ValidationException would ever see it: this is a
        // subclass of HttpException, not of ValidationException, but the order
        // of `render` callbacks is the order they are tried, and the throttle
        // response carries a `Retry-After` §5.1 makes mandatory.
        $exceptions->render(
            fn (ThrottleRequestsException $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::throttled($e, $request)
                : null,
        );

        // F-17 · 1.1 — every other `HttpException`. Last, because the throttle
        // exception above is one too and must keep its own handler.
        $exceptions->render(
            fn (HttpExceptionInterface $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::httpException($e, $request)
                : null,
        );
    })->create();
