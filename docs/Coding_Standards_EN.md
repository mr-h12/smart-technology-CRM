# CRM System — Coding Standards

> **Status:** Mandatory engineering standard before implementation.  
> **Scope:** Framework-neutral; applies to all backend, frontend, database, worker, and infrastructure code.  
> **Authority:** `CRM_Documentation_EN.md` remains the product source of truth. When this file conflicts with it, the CRM documentation wins.

## 1. Purpose and Rule Precedence

These standards make implementation safe, reviewable, testable, and consistent for a system that handles money, permissions, immutable history, and operational data.

Use this precedence order:

1. Approved CRM decision log and master requirements.
2. MVP build plan and its module acceptance criteria.
3. This coding standard.
4. OpenAPI contract, design system, personas, and module-specific specifications.
5. Tool, plugin, framework, or individual preference.

If a requirement is ambiguous, incomplete, or contradictory, stop and request a documented decision. Do not resolve it in code silently.

## 2. Working Discipline

- Restate each task as observable success criteria before changing code.
- For any change that spans more than one file, present `step → verification` and obtain approval before editing.
- Make the smallest coherent change. Do not mix unrelated refactoring, formatting, dependency upgrades, or dead-code cleanup into feature work.
- Read only the source sections directly relevant to the task, but re-read the relevant sections before changing money, permissions, workflow states, quotations, reports, or files.
- State assumptions explicitly. An unapproved assumption must be visible in the task notes and must not become a hidden business rule.
- Do not mark work complete until the applicable checks have run and their actual results are reported.

## 3. Architecture and Module Boundaries

### 3.1 Layering

Preserve the required direction of dependencies:

```text
Presentation → Application → Domain → Infrastructure
```

| Layer | Responsibilities | Must not contain |
|---|---|---|
| Presentation | HTTP handlers, request parsing, response serialization, UI components, user interaction. | Business calculations, permission decisions, direct database queries. |
| Application | Use cases, transaction coordination, authorization invocation, orchestration of interfaces/events. | Framework-specific UI concerns, raw SQL scattered through use cases. |
| Domain | Business entities, value objects, policies, state transitions, calculation rules, domain events. | HTTP, UI, framework, storage, queue, or vendor SDK calls. |
| Infrastructure | Database repositories, file storage adapter, queue adapter, PDF adapter, external-service adapters. | Unbounded business policy or permission logic. |

### 3.2 Modules

Keep code within the documented modules: Identity, Customers, Deals, Quotations, Suppliers, Catalog, Procurement, Outdoor, Reports, Notifications, Audit, and Admin.

- A module owns its domain rules, application use cases, persistence interface, and API surface.
- A module must not import another module’s private tables/repositories to change its data.
- Cross-module work uses declared interfaces or domain events and has an explicit owner for the resulting transaction/audit behavior.
- Do not create a shared “utils” area for business logic. Place a rule in its owning module or a clearly named shared technical library.

## 4. Naming and Code Clarity

- Use names that describe the business concept: `quotation`, `supplier_quotation`, `deal`, and `customer`; do not introduce alternate terms such as `order` for a CRM deal.
- Use the documented status values and document codes exactly. Do not create synonyms or framework enums that drift from managed database lists.
- Name boolean fields as a question or state: `is_archived`, `is_active`, `is_self_approved`, `has_open_account`.
- Name time fields precisely: `created_at`, `updated_at`, `valid_until`, `last_activity_at`; store timestamps in UTC.
- Name money fields by meaning and unit: `unit_cost`, `unit_cost_base`, `unit_price`, `line_total`, `gross_profit`, `final_profit`.
- Keep functions focused on one use case or policy. Prefer explicit names over comments explaining vague names.
- Comments explain non-obvious constraints, decisions, security boundaries, or references to `D-xx`, `DB-xx`, `J-xx`, and acceptance criteria; they do not narrate obvious syntax.

## 5. Types, Validation, and Data Integrity

- Use strict typing. Do not introduce untyped escape hatches, silent coercion, or implicit nullable values.
- Validate every untrusted value at the backend boundary: request body, query, header, path parameter, file metadata, queue payload, environment configuration, and external-adapter response.
- Validate format and business rules separately when useful: format validation produces field errors; domain validation produces a stable business-rule error code.
- Enforce invariants in the database as well as in application code: foreign keys, check constraints, unique constraints, required fields, and indexes.
- Treat database constraints as the final authority under concurrent requests. Convert known constraint failures to safe contract errors; never expose raw database messages.
- Use enum/reference tables for extendable values. Never hard-code sectors, units, service types, delivery terms, currencies, or permissions in UI or domain conditionals.

## 6. Money, Currency, and Calculations

- **Float is forbidden for money.** Use Decimal/NUMERIC in storage and exact decimal types in application code.
- Serialize money as decimal strings at the API boundary, as defined by `OpenAPI_Contract_EN.md`.
- Perform all calculations in the backend/domain layer. The frontend may preview a server-confirmed result but never becomes the source of truth.
- Apply documented calculation order exactly (`CRM_Documentation_EN.md` §5.2). Do not round intermediate values. Round the final quotation total only, by the configured unit for its currency, and only when rounding is enabled for that currency — rounding is optional (`D-65`).
- The discount reduces the tax base: it is subtracted from the subtotal before tax is computed (`D-64`). Additional items are never part of the tax base (`OD-01`, `D-62`). A quotation with no tax renders no tax line rather than a zero one (`D-63`).
- Store amount, currency, `fx_rate_at_time`, and base amount where required. Never recompute a historical quotation using a newer FX rate or catalog/supplier price.
- Keep supplier cost, margin, saving, profit, and customer selling price semantically separate in code, schema, responses, and UI models.
- Create focused unit tests for every pricing formula, rounding boundary, currency conversion, discount, tax, additional item, and procurement saving rule before changing calculation code.

## 7. Database and Migration Standards

- All schema changes use a new, versioned, managed migration with tested `up` and `down` paths.
- Never hand-edit the production schema. Never alter a migration that has already been applied anywhere shared; add a correcting migration.
- Every business table has a primary key, foreign keys, audit columns, and soft-delete behavior as required by `DB-01` and `DB-02`.
- Add indexes for each new join, permission scope, filter, sort, and common lookup. Verify them with realistic query plans for high-volume paths.
- Use transactions for every operation touching more than one table, including record mutation plus audit entry, version creation, status transition, reassignment, and file metadata attachment.
- Do not use physical `DELETE` for CRM business entities, reports, audit records, or files. Use documented archive/deactivation/soft-delete behavior.
- Use optimistic locking for quotations. A stale write returns `409 concurrency_conflict`; it never silently overwrites current data.
- Seed data is versioned and repeatable: roles, permissions, managed lists, currencies, and test users must be reproducible in non-production environments.

## 8. API Standards

- Follow `OpenAPI_Contract_EN.md` for `/api/v1`, JSON envelopes, errors, pagination, filter/search/sort/group parameters, idempotency, and response metadata.
- Keep route handlers/controllers thin: parse and validate, invoke an application use case, then serialize the approved result.
- Paginate every collection. Never implement a “return all” API endpoint for business data.
- Apply authorization before data retrieval or mutation; query filters may narrow scope but never enlarge it.
- Use stable machine-readable error codes. Localize user-facing messages based on the request language; do not make clients parse error text.
- Require idempotency keys for defined critical creates/actions and preserve the original response for valid replays.
- Keep OpenAPI schemas, request validators, response serializers, and tests aligned. A route is incomplete if it is undocumented.

## 9. Authorization, Security, and Privacy

- Enforce dynamic RBAC at API and row level. Hiding a button is never access control.
- Authorize each action on the target record and current state, including nested resources, downloads, bulk actions, exports, and async job requests.
- Test a negative case for every permission-sensitive operation: a caller who lacks access must be unable to read, infer, mutate, export, or download the data.
- Keep secrets outside source code and logs. Never expose service credentials, session tokens, reset codes, stack traces, SQL, or internal paths in browser responses.
- Apply rate limiting to login and API endpoints; validate input; protect against injection, XSS, and CSRF; use HTTPS even on internal networks.
- For files, validate true MIME type, configured size, parent-entity permission, and virus-scan result before making a file available. Store outside web root and use UUID storage names.
- Customer-facing PDF rendering must consume a safe customer-view data model that cannot contain supplier name, supplier price, cost, margin, saving, or profit fields.

## 10. Audit, Logging, and Events

- Audit logs are immutable and retained permanently. Clients never write or alter them directly.
- Every mutation records the actor, event, entity, old value, new value, timestamp, IP, device, request ID, and correlation ID where available.
- Always audit the mandatory critical events: tax/margin edits, customer reassignment, role change, Login As, archive restore, FX-rate change, account deactivation, and self-approval.
- Emit domain events after a successful transaction for cross-module effects, such as customer-status recalculation, dashboard aggregation, or search indexing.
- Events and jobs must be idempotent; consumers must tolerate delivery retries without duplicate data, notifications, or audit entries.
- Use structured logs with correlation IDs. Log failure context safely; never log secrets or excessive personal/sensitive content.

## 11. Frontend, i18n, and Design-System Standards

- Use the design tokens and component rules in `Design_System_EN.md`. Do not hard-code colors, spacing, radii, shadows, direction, or user-facing strings in feature code.
- All visible text is localized from day one. Arabic and English must have equivalent meaning; RTL/LTR is controlled by locale, not by duplicated screens.
- Use logical layout properties (start/end) instead of left/right assumptions. Preserve numeric, date, currency, and table reading order in RTL.
- Build loading, empty, validation, error, disabled, permission-denied, and connection-unavailable states for every screen where relevant.
- Do not compute money, permission, status-transition, or audit decisions in the client. Display backend results and use client state only for safe presentation/interactions.
- Maintain accessible keyboard behavior, visible focus, semantic labels, error associations, and theme contrast across all three themes.
- For Outdoor Sales, prioritize touch-friendly, minimal-typing flows and preserve a draft across a brief connectivity loss without presenting the system as offline-capable.

## 12. Queues, Jobs, PDF, and External Adapters

- Keep queues separated by documented priority: `critical`, `pdf`, `reports`, and `maintenance`.
- Every job declares its input schema, idempotency key/strategy, retry limit, failure handling, audit/log behavior, and observable status.
- Implement the scheduled jobs J-01 through J-14 exactly as documented, including the stated startup catch-up behavior.
- Generate PDFs asynchronously, use embedded Arabic-capable fonts, store immutable snapshots as required, retry failures, and expose readiness through the API contract.
- Place SMTP, storage, search, PDF, and every deferred integration behind an interface/adapter. An external outage must fail safely without making the whole CRM unavailable.
- Do not add deferred integrations (WhatsApp, Outlook, AI, Mapbox, push) or post-MVP functionality unless a new approved decision expands scope.

## 13. Testing and Verification

### 13.1 Required test levels

| Level | Required for |
|---|---|
| Unit | Pricing/calculation rules, state policies, validation, permission policies, mappers, and value objects. |
| Integration | Database constraints, migrations, repositories, API authorization, transactions, audit writes, queues, and module workflows. |
| End-to-end | Login, role boundaries, customer/deal/quotation lifecycle, approval, PDF, purchase order, procurement, delivery, mobile visit flow, and restart recovery. |
| Security/negative | Every permission boundary, validation failure, file rejection, rate limit, and stale concurrent quotation update. |

### 13.2 Test rules

- A money, permission, state-transition, versioning, or multi-table change has no exception from testing.
- For bug fixes, add a regression test that fails before the fix whenever practical.
- Tests use deterministic dates, Decimal values, test identities, and controlled FX rates. They must not depend on current time, external services, production data, or test execution order.
- Test both allowed and denied role paths. A passing happy path does not prove authorization.
- Run relevant checks continuously while working; before handoff, run the full applicable type check, lint, test, build, and smoke test suite.

## 14. Code Review and Definition of Done

Every change is ready for review only when it answers:

1. Which documented requirement, decision, database rule, job, or acceptance criterion does this implement?
2. Which roles can read and act on the changed resource, and how is that enforced at API and row level?
3. Which tables, migrations, constraints, indexes, transactions, audit entries, and events are changed?
4. Which test proves the happy path, negative permission path, validation/error path, and concurrency/version path where applicable?
5. Which user-facing states exist in Arabic and English, RTL and LTR, and across all themes where applicable?

Do not hand off work until:

- The diff is scoped to the task and contains no unrelated noise.
- Migrations run and reverse cleanly.
- Types, lint, relevant tests, build, and smoke checks pass.
- API documentation and any affected source documentation are updated.
- Audit entries, permissions, pagination, validation, i18n, accessibility, and performance implications have been checked.
- The handoff reports actual commands/results and a concise manual test checklist.

## 15. Prohibited Patterns

- Floating-point money or client-owned financial calculations.
- Hard-coded permissions, business enums, user-facing strings, theme values, or regional assumptions.
- UI-only authorization, missing negative authorization tests, or client-accessible service credentials.
- Physical deletion, mutation of immutable audit history, or in-place mutation of quotation/report version history.
- Unbounded list queries, client-side filtering used as security, or unpaginated exports that bypass permissions.
- Direct cross-module table writes, generic business logic hidden in helpers, or integrations called directly from domain code.
- Silent conflict resolution, silent loss of user input, or automatically overwriting a stale quotation.
- Deferred features, external integrations, or framework changes introduced without an approved decision.
