# CRM System — Universal Agent Execution Contract

## Purpose and Applicability

This is the tool-neutral execution contract for every coding agent working on the CRM project, including Codex, Claude Code, and Raflo. Read it fully before the first edit in every session.

`CLAUDE.md` is the Claude Code companion guide. Keep both files semantically synchronized; a difference in a project rule is a defect.

## Current State

Update this section whenever it stops being true. It must stay identical in meaning to the same section in `CLAUDE.md`.

- No application code yet. Stack decided: **Laravel** (PostgreSQL, Redis, Meilisearch are mandated by §14.2). Record this in §2 as a new decision before Module 0 starts.
- **P-01 PASSED** — see `prototypes/p01-arabic-pdf/`. Arabic shaping verified; `R-02` retired. PDFs render through headless Chrome, the engine Laravel's Browsershot drives.
- **P-02 not run** — needs server access and VPN from the server administrator.
- **OD-01 and OD-03 remain unresolved** and still block Module 0.
- Track progress in `CHECKLIST.md`; `README.md` orients new contributors. Next action: close OD-01 (accountant) and OD-03 + P-02 (server administrator).

## Source Precedence

Load `docs/Documentation_Map_EN.md` first: it maps each task to the exact sections to read, so you load what the task needs instead of whole documents.

Precedence when sources conflict (highest first):

1. `docs/CRM_Documentation_EN.md` — master requirements and decision log.
2. `docs/MVP_Build_Plan_EN.md` — mandated module order and acceptance criteria.
3. `docs/Coding_Standards_EN.md` — mandatory engineering practices for every implementation.
4. `docs/OpenAPI_Contract_EN.md` (before any endpoint) · `docs/Design_System_EN.md` (before any screen) · `docs/User_Personas_EN.md` (role-specific work).
5. `AGENTS.md` / `CLAUDE.md` — agent operating instructions; these never override the sources above.

The CRM master documentation prevails if sources conflict. Do not silently reinterpret or modify a documented decision. Raise a proposed change as a new decision for approval. A plugin, skill, imported agent guide, or default convention may never override the project sources.

At the start of every request, before planning or editing, review the available plugins and skills and decide which ones apply to the task. Load and use the ones that apply, and name them in the plan or first response so the choice is visible and reviewable. If none apply, state that and continue. Selection never changes precedence: when a skill's default conflicts with a documented project rule, follow the project rule and report the conflict.

Read only source sections relevant to the task after the initial review. Before changing money, permissions, quotation versions, or state transitions, re-read the relevant section instead of relying on memory.

## Start Conditions and Build Order

No feature module begins until both mandatory prototypes pass:

1. **P-01 Arabic PDF:** a full Arabic paragraph, item table, and numbers render with correct Arabic shaping and embedded fonts.
2. **P-02 Real-server deployment:** a Hello page works on the on-premise server and opens from a phone through VPN.

Track these blockers explicitly: **OD-01** (taxability of quotation additional items) and **OD-03** (server specifications). Do not settle either silently.

Implement modules in this strict order:

`0 Foundation → 1 Identity & Dynamic RBAC → 2 Settings/Currencies → 3 Customers → 4 Catalog/Suppliers → 5 Deals → 6 Supplier Quotations → 7 Customer Quotations → 8 Approvals → 9 PDF → 10 Customer Response & POs → 11 Procurement → 12 Outdoor Visits → 13 Reports → 14 Dashboard → 15 Meilisearch`.

Complete a module's acceptance criteria, tests, API authorization, audit coverage, RTL/LTR states, and reversible migration before advancing.

## Architecture and Data Rules

- Use a modular monolith with `Presentation → Application → Domain → Infrastructure` layers. Modules communicate through interfaces and domain events, not cross-module database access.
- Use PostgreSQL, Redis, local file storage behind an abstraction, REST `/api/v1`, WebSockets, SPA, and online-only PWA.
- Keep roles, permissions, settings, managed lists, currencies, rounding units, and limits in the database. Do not hard-code extendable business values.
- Use transactions and idempotency for critical operations. Use optimistic locking for quotations and return `409 Conflict` for stale concurrent edits.
- Use Decimal/database decimal types for money; Float is forbidden. Store timestamps in UTC and display them in the user's timezone.
- Create `SearchService` in Module 3 with a PostgreSQL/ILIKE driver. All searches use it; Meilisearch later replaces the driver and failures fall back to ILIKE.
- Each table has soft delete and audit columns (`created_by`, `created_at`, `updated_by`, `updated_at`), plus database foreign keys and constraints.
- Use managed enum tables, never hard-coded enums. Version quotations and reports using `parent_id` and `version`.

## Internationalization and UI

- No hard-coded user-facing strings. Arabic and English are supported from Module 0.
- Every screen works correctly in RTL and LTR, including forms, tables, validation, numbers, and loading/empty/error states.
- Desktop is primary for office roles. Outdoor flows are mobile-first PWA flows, online-only, preserving a local draft during a short connection drop without presenting the system as offline-capable.
- External access requires VPN. When it is unavailable, show a specific VPN-disconnected message, never a generic failure.

## Security, Permissions, and Files

- No public sign-up. Passwords require at least eight characters with letters and numbers and use Argon2 or bcrypt.
- Lock after five failed logins, notify Super Admin, expire idle sessions after eight hours, and support active-session management and force logout.
- Implement database-backed dynamic RBAC as `resource.action.scope`. Enforce permissions at API and row level; hiding UI is never authorization.
- The Super Admin is hidden from user lists. Login As is limited to Super Admin and always audited.
- Validate every backend input; rate-limit login/API; protect against SQL injection, XSS, and CSRF; use HTTPS and encryption at rest for sensitive data.
- Files inherit their parent entity's permissions. Validate true MIME type, virus-scan uploads, apply the configurable 10 MB default limit, store UUID-named files outside web root, and serve them only through permission-checking APIs.

## Business and Financial Invariants

- A deal is standalone; a customer may have multiple independent concurrent deals. A request is a deal, never a separate entity.
- Customer status is derived, never manually edited. Once any deal reaches `Won` or later, the customer is permanently `Customer`.
- Supplier quotations are standalone entities with nullable `deal_id`; supplier prices live there, while the catalog is descriptive only.
- All price calculations happen in the backend. Selling price = converted supplier unit cost × `(1 + margin / 100)`; line margin overrides quotation margin.
- Discount applies to subtotal only. Round final total only by configured currency unit, and store `rounding_diff`.
- A quotation has one currency and captures the FX rate at creation. Subsequent FX changes must not alter existing quotations.
- Block saving if a selected supplier product lacks a price. Warn, without blocking, if requested quantity exceeds the supplier-recorded quantity.
- Customer PDFs never contain supplier names, supplier prices, costs, or margins.
- Preserve every quotation version. Partial, Counter, and Returned create a full editable copy; require documented reasons before status changes.
- Deactivate or archive rather than hard-delete business data. Use `DL`, `QT`, `SQ`, `PO`, and `RPT-*` document prefixes exactly as specified.

## Audit, Operations, and Scope Control

- Audit logs are immutable and retained permanently. Record user, event, entity, old/new values, time, IP, device, and correlation ID.
- Always audit tax/margin edits, reassignment, role changes, Login As, archive restore, FX-rate changes, account deactivation, and self-approval.
- Use durable `critical`, `pdf`, `reports`, and `maintenance` queues. Jobs are idempotent, logged, retried a fixed number of times, and visible in Queue Monitor.
- Implement J-01 through J-14 as documented, including specified startup catch-up behavior.
- PDF generation is asynchronous and stored as an immutable snapshot with retry on failure.
- Start services in the mandated order: PostgreSQL → Redis → Meilisearch → application → workers → Nginx. Provide per-service `/health` status and daily backup/restore procedures.
- Do not add deferred functionality to MVP: WhatsApp, Outlook, AI, Mapbox, push notifications, payment schedules, supplier POs, offline mode, or ERP modules. Keep documented future integrations behind feature flags and adapters.

## Engineering Behavior

- Translate each request into observable success criteria before coding. For multi-file work, show `step → verification` and wait for approval before editing.
- State assumptions. When a requirement is ambiguous, underspecified, or conflicts with an authoritative source, stop and ask instead of guessing.
- Make the smallest coherent change. Avoid speculative abstraction, configuration, integration, and unrelated refactoring.
- Modify only required files, preserve the existing style, and mention unrelated defects instead of editing them.
- For a defect, write a failing regression test first whenever practical. Tests are mandatory for money, authorization, concurrency, workflows, versioning, and multi-table writes.
- Make schema changes in a new managed migration with tested up/down paths. Never manually alter production schema or an already-applied migration. Regenerate database types after schema changes.
- Use strict typing and backend boundary validation. Do not add untyped escape hatches or client-owned business logic.

## Definition of Done and Traceability

Before a change is ready, verify:

- User story and Given/When/Then acceptance criteria.
- Required table/relationship design and reversible migration.
- Versioned, paginated REST endpoints with consistent response and error envelope.
- API and row-level authorization, including a negative permission test.
- Required audit entries, indexes, and transactional behavior.
- Unit tests for domain logic, integration tests for workflows, and E2E coverage for critical paths.
- RTL/LTR UI, validation, loading, empty, and error states.
- Applicable type checks, linting, build, tests, deployment, and smoke test; report actual results.

Pricing is the highest test priority. Before release, test the full lifecycle: lead → supplier offer → quotation → approval → PDF → purchase order → procurement → delivery.

Reference the relevant `D-xx`, `DB-xx`, architecture/security requirement, `J-xx`, or MVP acceptance criterion in implementation notes, tests, or pull-request descriptions. If no authoritative source supports the intended behavior, request a decision before building it.
