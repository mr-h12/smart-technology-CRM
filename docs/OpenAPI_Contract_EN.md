# CRM System — OpenAPI Contract

> **Status:** Required baseline before the first endpoint.  
> **Version:** `v1`  
> **Arabic:** reading-only translation in `arabic/`; not a maintained companion (D-58).

## 1. Purpose and Authority

This contract defines the API-wide conventions required by `API-01` through `API-12`: REST resource naming, `/api/v1/` versioning, unified success/error envelopes, pagination, filtering, grouping, rate limiting, API logging, OpenAPI documentation, and optimistic concurrency.

It applies to the internal web SPA and PWA. It does not relax the CRM documentation's permission matrix, business rules, audit requirements, data model, or scope. Where a module specification needs a rule that this document does not define, the CRM master documentation prevails.

## 2. Base Conventions

| Item | Contract |
|---|---|
| Base path | `/api/v1` |
| Style | RESTful JSON over HTTPS. Use plural, kebab-case resource names: `/supplier-quotations`, not verbs in paths. |
| Character encoding | UTF-8 JSON. |
| Field naming | `snake_case` in request and response JSON. |
| Resource IDs | Opaque UUID identifiers. Human-readable business codes (`DL-…`, `QT-…`, `SQ-…`, `PO-…`, `RPT-…`) remain separate fields. |
| Dates/times | ISO 8601 UTC timestamps (`2026-08-11T13:45:30Z`). The client displays them in the user’s timezone. |
| Dates only | ISO 8601 date (`YYYY-MM-DD`). |
| Money | Decimal values are JSON strings, never binary floating-point numbers. Every money structure carries amount, currency, captured FX rate, and base amount where applicable. |
| Content negotiation | Requests use `Content-Type: application/json` unless uploading files. Responses use `application/json` unless streaming a permitted download. |
| Language | The client sends `Accept-Language: ar` or `en`. Stable machine codes remain English; user-facing messages are localized. |

### 2.1 API versioning

- All endpoints start at `/api/v1` from day one.
- A breaking public contract change requires a new version (`/api/v2`); do not silently repurpose a field or status code.
- Additive optional fields are allowed in `v1` when documented in OpenAPI.
- Every deployed version publishes an OpenAPI document and versioned changelog.

## 3. Authentication, Authorization, and Traceability

### 3.1 Authentication

- User endpoints require an authenticated, active user session or an equivalent server-issued bearer credential.
- The browser must never receive privileged database/service credentials.
- API keys and webhooks are reserved for the documented API integration capability and must be individually scoped, rate-limited, auditable, revocable, and never used as a substitute for an employee session.
- Deactivated, locked, expired, or force-logged-out accounts cannot call protected endpoints.

### 3.2 Authorization

- Every endpoint enforces dynamic database-backed `resource.action.scope` permissions at the API and row level.
- The server determines all scopes: `Own`, `Team`, `All`, `Out`, and `Asgn`. Query parameters can narrow a permitted result; they can never broaden it.
- UI visibility is not authorization. A direct API request without permission must fail.
- Export/download permission must never exceed view permission.
- Customer-facing PDF endpoints always exclude supplier names, supplier prices, costs, and margins regardless of caller role.

### 3.3 Request and audit identifiers

| Header | Direction | Rule |
|---|---|---|
| `X-Request-Id` | Response | Server-generated unique ID returned on every response. Include it in logs, errors, audit correlation, and support reports. |
| `X-Correlation-Id` | Request/response | Optional caller-supplied trace ID. Accepted **only** when it matches `^[A-Za-z0-9._-]{1,128}$` — letters, digits, dot, underscore and hyphen, maximum **128** characters (`D-69`). Propagate an accepted value byte for byte, including the single character `0`. Never trust it as an authorization input. |
| `Idempotency-Key` | Request | Required for defined critical create/action endpoints; a UUID or similarly high-entropy client-generated key. |
| `If-Match` | Request | Required for quotation mutations subject to optimistic locking; contains the latest quotation version token. |

`X-Correlation-Id` is validated but never rejected. A missing, empty, malformed, or overlong
value is **ignored and replaced by a server-generated ID**, and the request proceeds to its
normal status — never `400`. The header is optional, so an unusable value costs the caller its
trace, not its request (`D-69`). The accepted character set is exactly what is safe to write
verbatim into a structured log line (§10, `AUD-05`) or an audit row: no whitespace, no `CR`/`LF`,
no quoting, markup, or field-separator characters, so no caller can forge a permanent record.

All authenticated mutations must create their required audit entry server-side. Clients cannot author audit rows directly.

## 4. Unified Response Envelope

### 4.1 Single-resource success

Use for `GET` detail, `POST` create, and successful `PATCH`/action responses.

```json
{
  "data": {
    "id": "018f6a2c-2e7e-7d9a-a5e8-3b329495aa10",
    "code": "QT-2026-0001"
  },
  "meta": {
    "request_id": "req_01J5Y8P7TJ1J8DNM7ED6K2D1XQ"
  }
}
```

### 4.2 Collection success

Every list endpoint is paginated; an endpoint must never return an unbounded collection.

```json
{
  "data": [],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 25,
      "total": 142,
      "total_pages": 6,
      "has_next_page": true,
      "has_previous_page": false
    },
    "request_id": "req_01J5Y8P7TJ1J8DNM7ED6K2D1XQ"
  }
}
```

### 4.3 Accepted asynchronous work

Use `202 Accepted` for work that is queued, including PDF generation and applicable report generation.

```json
{
  "data": {
    "job_id": "018f6a34-1ef9-7bda-bfe8-56e4e152cc44",
    "status": "queued"
  },
  "meta": {
    "request_id": "req_01J5Y8P7TJ1J8DNM7ED6K2D1XQ"
  }
}
```

The module contract defines who may view job status and what completed output is available. A `202` response is not evidence that the resulting PDF or report is ready.

## 5. Unified Error Envelope

All non-2xx responses use this shape. Never return framework-default HTML or unstructured error objects.

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Please correct the highlighted fields.",
    "details": [
      {
        "field": "valid_until",
        "code": "required",
        "message": "A validity date is required."
      }
    ]
  },
  "meta": {
    "request_id": "req_01J5Y8P7TJ1J8DNM7ED6K2D1XQ"
  }
}
```

### 5.1 Error codes and HTTP status

| HTTP | `error.code` | Use |
|---:|---|---|
| 400 | `invalid_request` | Malformed JSON, invalid query syntax, or invalid header. |
| 401 | `authentication_required` / `session_invalid` | Missing, expired, locked, or invalid authentication. |
| 403 | `permission_denied` | Authenticated caller cannot perform the requested action. |
| 404 | `resource_not_found` | Resource does not exist or is not visible to the caller. Do not reveal which case applies. |
| 409 | `concurrency_conflict` | Quotation version is stale. Return current version metadata needed to refresh, never overwrite silently. |
| 409 | `idempotency_conflict` | Same idempotency key reused with a different request payload or operation. |
| 409 | `state_transition_invalid` | Requested state change violates the documented workflow. |
| 422 | `validation_failed` | Well-formed request fails business or field validation. |
| 422 | `business_rule_blocked` | A documented rule blocks the action, such as missing supplier price. |
| 423 | `account_locked` | Account locked after failed logins. |
| 429 | `rate_limit_exceeded` | Limit exceeded. Return `Retry-After`. |
| 500 | `internal_error` | Unexpected failure. Log internally; never expose stack trace, SQL, secrets, or sensitive data. |
| 503 | `service_unavailable` | Temporary dependency or maintenance failure. Return a safe retry message. |

Use specific stable codes inside `details` for expected rules, for example `supplier_price_missing`, `rejection_reason_required`, `quantity_exceeds_recorded`, and `file_mime_invalid`.

## 6. Query Contract for Lists

### 6.1 Pagination

```text
GET /api/v1/customers?page=1&per_page=25
```

- Default `per_page`: `25`; maximum `100`. Invalid or excessive values return `400 invalid_request`.
- `total` is required when the underlying query can calculate it efficiently. If a specialised endpoint cannot calculate it, the module OpenAPI spec must state the alternative before implementation.
- Pagination always happens after authorization scoping and before response serialization.

### 6.2 Filtering, search, sorting, and grouping

```text
GET /api/v1/quotations?filter[status]=pending&filter[currency]=EGP
GET /api/v1/customers?q=ahmed&sort=-created_at,name
GET /api/v1/quotations?group_by=<documented-group>
```

| Parameter | Rule |
|---|---|
| `filter[field]` | Supports only fields explicitly declared for that resource. Repeat the parameter for an allowed multi-value filter. |
| `q` | Permission-scoped search query. It always passes through `SearchService`; do not expose a database-specific search syntax. |
| `sort` | Comma-separated allowed fields. Prefix `-` means descending; default order is resource-specific and documented. |
| `group_by` | Server-side grouping only. Each resource declares its allowed groups; clients do not request arbitrary database grouping. |
| `include` | Optional related-data expansion, limited to explicitly documented, permission-safe relations. Never use it to bypass a detail endpoint or return unrestricted collections. |

Reject unknown filter, sort, group, or include values with `400 invalid_request`; never ignore them silently.

## 7. Resource and Action Conventions

### 7.1 Resource routes

Use conventional routes:

```text
GET    /api/v1/customers
POST   /api/v1/customers
GET    /api/v1/customers/{customer_id}
PATCH  /api/v1/customers/{customer_id}

GET    /api/v1/deals
POST   /api/v1/deals
GET    /api/v1/deals/{deal_id}
PATCH  /api/v1/deals/{deal_id}
GET    /api/v1/deals/{deal_id}/timeline
POST   /api/v1/deals/{deal_id}/documents

GET    /api/v1/quotations
POST   /api/v1/quotations
GET    /api/v1/quotations/{quotation_id}
PATCH  /api/v1/quotations/{quotation_id}

GET    /api/v1/purchase-orders
GET    /api/v1/purchase-orders/{purchase_order_id}
POST   /api/v1/purchase-orders/{purchase_order_id}/documents
```

Use matching resource structures for suppliers, catalog items, supplier quotations, purchase orders, negotiations, visits, reports, users, roles, permissions, currencies, FX rates, settings, and permitted administration resources.

A purchase order has no `POST /purchase-orders`: it is written by `respond` with `accepted` (§7.2; `D-12`, `D-53`). It carries no permission of its own — it is read by whoever may view its quotation, scoped through the deal, and its documents are attached under `quotation.record_customer_response` (`D-38`). `q` searches both `po_number` and `customer_po_reference` (Module 10).

### 7.2 Domain actions

Use a clear action suffix only when an action is not a normal resource update and it represents a documented domain command.

```text
PATCH /api/v1/customers/{customer_id}/assign
PATCH /api/v1/customers/{customer_id}/archive
PATCH /api/v1/customers/{customer_id}/restore

PATCH /api/v1/deals/{deal_id}/assign
PATCH /api/v1/deals/{deal_id}/approve
PATCH /api/v1/deals/{deal_id}/reject
PATCH /api/v1/deals/{deal_id}/status

PATCH /api/v1/quotations/{quotation_id}/submit-for-approval
PATCH /api/v1/quotations/{quotation_id}/approve
PATCH /api/v1/quotations/{quotation_id}/return
PATCH /api/v1/quotations/{quotation_id}/edit-and-approve
POST  /api/v1/quotations/{quotation_id}/new-version
PATCH /api/v1/quotations/{quotation_id}/send
PATCH /api/v1/quotations/{quotation_id}/respond
```

- Every action has an explicit request schema, required permission, audit event, accepted current state, resulting state, and idempotency requirement in the generated OpenAPI document.
- Never use generic endpoints such as `/update-status`, `/action`, or `/bulk` without a resource and documented domain meaning.
- Use `POST` to create a new version or a non-idempotent subresource; use `PATCH` for an authorized state/action mutation of an existing resource.

**Quotation approval actions** (Module 8; `MVP_Build_Plan` §Module 8 names the three routes — the `edit-and-approve` line above is the contract catching up with the plan, recorded 2026-09-15):

| Action | Request body | Permission (§3.5) | Audit event | Accepted state → result |
|---|---|---|---|---|
| `approve` | none | `quotation.approve` | `QUOTATION_APPROVED`, or `SELF_APPROVAL` **instead** when the approver is the quotation's `created_by` (§6.5, `D-50`; the row is flagged `is_self_approved`) | `pending` → `approved` |
| `return` | `{ "note": string }` — required, non-blank (`422 validation_failed` on `note`) | `quotation.return_with_note` | `QUOTATION_RETURNED`, carrying the note | `pending` → `draft` on the **same row**: `returned_at` and `return_note` set, `submitted_at` cleared |
| `edit-and-approve` | the full editable body of `PATCH /quotations/{quotation_id}` | `quotation.approve`; `quotation.edit_margin` / `quotation.edit_tax` when the body moves the margin or the tax | `QUOTATION_UPDATED` (old → new), then `QUOTATION_APPROVED` or `SELF_APPROVAL`, in one transaction | `pending` → `approved` (re-priced) |

All three carry `If-Match` (§9.2) and answer `409 concurrency_conflict` on a stale token and `409 state_transition_invalid` from any other state. None takes an `Idempotency-Key`: they are `PATCH` mutations whose replay is already refused by the token, the reading §9.1's "actions that change irreversible-equivalent business state" was given for `submit-for-approval` (Module 7, Point 4.2) and is kept here for consistency.

**Quotation send and customer-response actions** (Module 10; `D-90`, recorded 2026-09-23):

| Action | Request body | Permission (§3.5) | Audit event | Accepted state → result |
|---|---|---|---|---|
| `send` | none | `quotation.send_to_customer` | `QUOTATION_SENT` | `approved` → `sent`, `sent_at` set; no PDF is required (`D-90`). The deal moves `supplier_quotation` → `quotation_sent`, stays where it is from `quotation_sent` on, and a deal before `supplier_quotation` refuses the send with `422 deal_not_ready_to_send` |
| `respond` · accepted | `{ "response": "accepted", "customer_po_reference": string, "po_date": date }` — both required | `quotation.record_customer_response` | `QUOTATION_ACCEPTED` (old → new `consumed_quantity` per line, `D-81`), `PURCHASE_ORDER_CREATED` | `sent` → `accepted`; a purchase order `PO-YYYY-NNNN` is written (§4.6); the deal does not move |
| `respond` · partial | `{ "response": "partial" }` | `quotation.record_customer_response` | `QUOTATION_PARTIAL`, `QUOTATION_VERSION_CREATED` | `sent` → `partial`; a new version (`draft`, `parent_id`, `version + 1`) is written in the same transaction and named in the response as `new_version: {id, code, version}` beside the answered quotation (§6.3, `D-08`); the deal does not move |
| `respond` · counter | `{ "response": "counter", "reason": string }` — reason required, non-blank (`422 rejection_reason_required`) | `quotation.record_customer_response` | `QUOTATION_COUNTERED`, `QUOTATION_VERSION_CREATED` | `sent` → `counter`; a new version as for partial; the deal does not move |
| `respond` · rejected | `{ "response": "rejected", "reason": string }` — reason required, non-blank (`422 rejection_reason_required`) | `quotation.record_customer_response` | `QUOTATION_REJECTED` | `sent` or `expired` → `rejected`; the deal goes `lost` with the reason only when no other quotation of that deal is live, and is left untouched when it has no `lost` edge (`D-90`); the answer carries `deal_lost: true` when the deal became `lost`, `false` when it was left where it was |

Both carry `If-Match` (§9.2) and answer `409 concurrency_conflict` on a stale token and `409 state_transition_invalid` from any other state. Neither takes an `Idempotency-Key`, on the approval actions' reading above: a replay is refused by the token. `accepted`'s per-line consumption stays idempotent on its own key (`D-81`). `expired` is written only by `J-01`, never by a request.

### 7.3 Bulk operations

Bulk archive/restore is permitted only where the CRM documentation permits it. Use a resource-specific action and a bounded identifier list:

```json
{
  "ids": ["uuid-1", "uuid-2"]
}
```

Return per-record result data. Authorize and audit each affected record; do not allow a bulk request to bypass row scope.

## 8. Data Representation Rules

### 8.1 Money

```json
{
  "amount": "1234.67",
  "currency": "EGP",
  "fx_rate_at_time": "1.000000",
  "base_amount": "1234.67"
}
```

- The backend calculates, validates, rounds, and persists all financial values.
- Amount strings use `.` as decimal separator and no thousands separator.
- A request may submit permitted input amounts; response totals are authoritative backend results.
- Preserve monetary snapshots in quotation/report versions. Editing current currency or FX configuration never mutates a historical response.

### 8.2 Relationships and audit fields

- Represent direct relationships with explicit ID fields such as `customer_id`, `deal_id`, and `owner_id`.
- Include lightweight display objects only when the resource's documented `include` contract authorizes them.
- Read responses include applicable audit metadata (`created_by`, `created_at`, `updated_by`, `updated_at`) subject to permission. The immutable full audit log is a separate resource.
- Soft-deleted/archived/inactive status must be explicit when a caller is permitted to view it; never disguise an archive as a physical delete.

### 8.3 Files and downloads

- Multipart upload endpoints must declare allowed MIME types, configurable maximum size, virus-scan state, and parent entity.
- A stored file is never publicly addressable. Download uses a permission-checking endpoint and re-checks access at request time.
- PDF generation is asynchronous. A customer quotation PDF endpoint follows the role's generate/download rule and never exposes internal supplier or financial fields.

## 9. Idempotency and Concurrency

### 9.1 Idempotency

Require `Idempotency-Key` for critical POST commands, including creation of deals, quotations, supplier quotations, purchase orders, reports, versions, and actions that change irreversible-equivalent business state.

- Persist the actor, route, key, request hash, final status, and response for the defined retention period.
- Repeating the same actor + route + key + payload returns the original response without repeating the side effect.
- Reusing a key with a changed payload or different action returns `409 idempotency_conflict`.
- Idempotency does not substitute for authorization: every replay is checked against an active session and current permission.

### 9.2 Optimistic concurrency

- Quotation reads return a current version token, for example `"etag": "quotation:uuid:7"`.
- A quotation mutation sends that token in `If-Match`.
- If the stored version differs, return `409 concurrency_conflict` with a safe refresh reference. Do not merge or overwrite automatically.
- The same pattern may be adopted later for other high-contention resources only through a documented contract update.

## 10. Rate Limits, Logs, and Documentation

- Rate-limit login and API endpoints by user, credential, IP, and endpoint as appropriate. The concrete limits are configurable system settings, not client constants.
- Log API actor/credential, timestamp, request ID, correlation ID, endpoint, method, response status, IP, device/client metadata, duration, and safe error code. Never log passwords, tokens, secrets, or raw sensitive payloads unnecessarily.
- Publish generated OpenAPI 3.1 JSON and human-readable documentation for every version. The contract is part of CI: implementation routes, request schemas, response schemas, and documented operation IDs must stay synchronized.
- Test every protected operation through API-level negative authorization tests; UI tests alone are insufficient.

## 11. API Contract Acceptance Criteria

1. Given a successful detail or create request, when the server responds, then it uses the single-resource envelope and includes `meta.request_id`.
2. Given any list endpoint, when a caller omits pagination, then the server returns the bounded default page and pagination metadata.
3. Given an invalid field or business rule, when the server rejects it, then it returns a localized message, stable machine code, field details where relevant, and no internal stack trace.
4. Given a user without permission, when they call an endpoint directly, then the server denies access regardless of UI state and does not reveal inaccessible record existence.
5. Given a quotation updated by another user, when a stale `If-Match` token is submitted, then the server returns `409 concurrency_conflict` and preserves both the current quotation and audit history.
6. Given a repeated critical command with the same idempotency key and payload, when it is retried, then it returns the original result without a duplicate business record or audit event.
7. Given an API response with money, when it contains a monetary value, then that value is serialized as a decimal string with its required currency/FX context.
8. Given a customer-facing PDF request, when the caller is otherwise authorized, then its generated/downloaded content still excludes supplier names, prices, cost, and margin.
