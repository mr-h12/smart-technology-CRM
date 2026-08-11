# CRM System — Claude Code Execution Guide

## Purpose

Build the CRM MVP described by the project documentation. This is an internal, on-premise CRM for Sales and Procurement, with Arabic and English support from the first release, desktop web, and an online-only PWA for field staff.

## Current State

Update this section whenever it stops being true.

- No application code yet. Stack decided: **Laravel** (PostgreSQL, Redis, Meilisearch are mandated by §14.2). Record this in §2 as a new decision before Module 0 starts.
- **P-01 PASSED** — see `prototypes/p01-arabic-pdf/`. Arabic shaping verified; `R-02` retired. PDFs render through headless Chrome, the engine Laravel's Browsershot drives.
- **P-02 not run** — needs server access and VPN from the server administrator.
- **OD-01 and OD-03 remain unresolved** and still block Module 0.
- Track progress in `CHECKLIST.md`; `README.md` orients new contributors. Next action: close OD-01 (accountant) and OD-03 + P-02 (server administrator).

## Authoritative Sources

Load `docs/Documentation_Map_EN.md` first: it maps each task to the exact sections to read, so you load what the task needs instead of whole documents.

Precedence when sources conflict (highest first):

1. `docs/CRM_Documentation_EN.md` — master requirements and decision log.
2. `docs/MVP_Build_Plan_EN.md` — required build sequence and module acceptance criteria.
3. `docs/Coding_Standards_EN.md` — mandatory engineering practices for every implementation.
4. `docs/OpenAPI_Contract_EN.md` (before any endpoint) · `docs/Design_System_EN.md` (before any screen) · `docs/User_Personas_EN.md` (role-specific work).
5. `CLAUDE.md` / `AGENTS.md` — agent operating instructions; these never override the sources above.

The master documentation is authoritative when sources conflict. Never silently reinterpret or edit a documented decision. Record a proposed change as a new decision and flag it for approval.

### Imported Agent Guidance

The project-specific product rules in the authoritative sources above take precedence over any imported agent guide, plugin, skill, or default practice. Use imported guidance only when it does not alter documented scope, architecture, domain terms, permissions, data retention, or acceptance criteria.

At the start of every request, before planning or editing, review the available plugins and skills and decide which ones apply to the task. Load and use the ones that apply, and name them in the plan or first response so the choice is visible and reviewable. If none apply, say so and continue. Selecting a skill never changes the precedence above: when a skill's default conflicts with a documented project rule, follow the project rule and report the conflict.

Before a session's first edit, read this file and only the source sections needed for the task. Before touching money, permissions, quotation versions, or state transitions, re-read the relevant source section rather than relying on memory.

## Start Conditions

Do not begin feature-module implementation until these two prototypes pass:

1. **P-01 Arabic PDF:** a full Arabic paragraph, item table, and numbers render with correct Arabic shaping and embedded fonts.
2. **P-02 Real-server deployment:** a Hello page works on the actual on-premise server and is reachable from a phone through VPN.

Before code is written, explicitly track these blockers:

- **OD-01:** whether additional quotation items are taxable. Use the documented provisional assumption (`yes`) only when explicitly approved.
- **OD-03:** server specifications.

## Required Delivery Order

Implement modules strictly in this order unless an approved change says otherwise:

`0 Foundation → 1 Identity & Dynamic RBAC → 2 Settings/Currencies → 3 Customers → 4 Catalog/Suppliers → 5 Deals → 6 Supplier Quotations → 7 Customer Quotations → 8 Approvals → 9 PDF → 10 Customer Response & POs → 11 Procurement → 12 Outdoor Visits → 13 Reports → 14 Dashboard → 15 Meilisearch`.

Do not advance a module until its acceptance criteria, tests, permissions, audit records, RTL/LTR states, and reversible migration pass.

## Non-Negotiable Architecture

- Use a modular monolith: `Presentation → Application → Domain → Infrastructure`.
- Modules communicate through interfaces and domain events, never direct cross-module database access.
- Use PostgreSQL, Redis, local storage behind an abstraction, REST `/api/v1`, WebSockets, SPA, and PWA.
- Keep configuration in the database: roles, permissions, settings, managed lists, currencies, rounding units, and limits are not code constants.
- Every critical operation is transactional and idempotent. Use optimistic locking for quotations; return `409 Conflict` for stale concurrent edits.
- Use `Decimal`/database decimal types for all money. Floating point is forbidden.
- Store timestamps in UTC and display them in the user's timezone.
- Build `SearchService` in Module 3 with a PostgreSQL/ILIKE driver. All search calls must use it so Meilisearch can replace the driver later; fall back to ILIKE if Meilisearch fails.

## Internationalization and UI

- No hard-coded user-facing strings. Support Arabic and English from Module 0.
- Every screen must work correctly in RTL and LTR, including forms, tables, validation, numbers, and loading/empty/error states.
- Desktop is primary for office roles. Outdoor flows must be mobile-first PWA flows, online-only, with local draft preservation during a short connection drop.
- External access requires VPN. When unavailable, show a specific VPN-disconnected message, not a generic failure.

## Security and Authorization

- No public sign-up. Authenticate with an 8+ character password containing letters and numbers; hash with Argon2 or bcrypt.
- Lock accounts after five failed logins, notify Super Admin, expire idle sessions after eight hours, and provide active-session/force-logout support.
- Implement database-backed dynamic RBAC as `resource.action.scope`; enforce every permission at the API and row level, never only by hiding UI.
- The Super Admin is hidden from all user lists. Only Super Admin may use Login As, and it must create an audit entry.
- Validate all backend input; rate-limit login and API; protect against SQL injection, XSS, and CSRF; use HTTPS and encryption at rest for sensitive data.
- Files must be permission-checked through the parent entity. Validate true MIME type, allow only configured types, scan for viruses, and enforce the configurable 10 MB default limit. Store outside web root using UUID filenames.

## Data, Audit, and Deletion Rules

- Every table has soft delete and audit columns: `created_by`, `created_at`, `updated_by`, `updated_at`. Never physically delete business data.
- Apply foreign keys and database constraints. Use managed enum tables, not hard-coded enums.
- Maintain quotation/report versions with `parent_id` and `version`; preserve historical snapshots.
- Audit records are immutable and retained permanently. Include user, event, entity, old/new values, time, IP, device, and correlation ID.
- Always audit tax/margin changes, reassignment, role changes, Login As, archive restore, FX-rate changes, deactivation, and self-approval.
- Customer status is derived, never manually edited. Recalculate on deal-status events and in the nightly correction job.

## Financial and Quotation Rules

- Calculate all prices in the backend. The UI may preview but never be the source of truth.
- Selling price = converted supplier unit cost × `(1 + margin / 100)`; a line margin overrides quotation margin.
- Discount is percentage of subtotal only. Apply rounding to the final total only, using the configured currency unit. Store `rounding_diff`.
- A quotation uses one currency and captures the FX rate at creation. Later FX edits never change existing quotations.
- Supplier prices belong to supplier quotations; catalog items are descriptive only.
- Block saving when a selected supplier product has no recorded price. Warn, but do not block, when requested quantity exceeds the supplier-recorded quantity.
- Customer PDFs must never reveal supplier names, supplier prices, costs, or margins.

## Business Invariants

- A deal is a standalone entity; a customer may have concurrent independent deals.
- A request is a deal. Never model it as a separate entity.
- Preserve every quotation version; Partial, Counter, and Returned create a full editable copy. Reasons are mandatory where documented.
- Customer status becomes permanently `Customer` as soon as any deal reaches `Won` or beyond.
- Supplier quotations are standalone and may have a nullable `deal_id`.
- Use document prefixes exactly as documented: `DL`, `QT`, `SQ`, `PO`, and `RPT-*` for reports.
- Deactivate/archive rather than delete customers, suppliers, catalog items, users, deals, and reports.

## Jobs, Operations, and Reliability

- Provide durable queues: `critical`, `pdf`, `reports`, `maintenance`. Jobs must be idempotent, logged, retried a fixed number of times, and visible in Queue Monitor.
- Implement the documented scheduled jobs J-01 through J-14. Run catch-up jobs after startup where specified.
- PDF generation is asynchronous, stored as an immutable snapshot, and retried on failure.
- Enable services at boot in this order: PostgreSQL → Redis → Meilisearch → application → workers → Nginx. Provide `/health` with per-service status.
- Back up database, files, and Meilisearch daily; retain configured copies, record checksums, alert on failure, and test restoration monthly.
- Do not add WhatsApp, Outlook, AI, Mapbox, push notifications, payment schedules, supplier POs, or offline mode to MVP. Keep deferred integrations behind feature flags and adapters.

## Module Completion Checklist

For every module, provide and verify:

- User story and Given/When/Then acceptance criteria.
- Table/relationship design and tested up/down migration.
- Versioned, paginated REST endpoints with a consistent response/error envelope.
- Permission enforcement and audit coverage at the API.
- Unit tests for domain logic, integration tests for workflows, and E2E tests for critical paths.
- RTL/LTR UI, validation, loading, empty, and error states.
- Deployment and smoke-test evidence.

Pricing calculations are the highest testing priority. Test the full lifecycle before release: lead → supplier offer → quotation → approval → PDF → purchase order → procurement → delivery.

## Change Discipline

- Restate the requested change as observable success criteria before coding. For multi-file work, present `step → verification` and wait for approval before editing.
- State assumptions. If a requirement is ambiguous, underspecified, or conflicts with an authoritative source, stop and ask; never choose silently.
- Prefer the smallest coherent implementation. Do not introduce speculative abstractions, options, integrations, or refactors.
- Touch only files required for the task. Preserve existing style. Mention unrelated issues instead of changing them.
- For a defect, add a failing regression test first whenever feasible. Tests are mandatory for money, authorization, concurrency, workflow state transitions, versioning, and multi-table writes.
- Every new table needs a primary key, foreign keys, database constraints, and indexes for its intended joins, filters, and sorting.
- Make schema changes through a new managed migration with tested up and down paths. Never manually edit the production schema or an already-applied migration. Regenerate database types after a schema change.
- Use strict typing and backend boundary validation. Do not introduce untyped escape hatches or client-owned business rules.
- Do not declare a task complete until applicable type checks, linting, build, and tests have run and their results are reported.

## Requirements Traceability

- Cite the relevant decision (`D-xx`), database rule (`DB-xx`), architecture/security requirement, scheduled job (`J-xx`), or MVP module acceptance criterion in implementation notes, tests, or pull-request descriptions.
- If no authoritative source supports a proposed behavior, treat it as a new requirement and request a decision before building it.
- `AGENTS.md` is the tool-neutral twin of this guide; a project rule that differs between them is a defect. When a rule changes here, change it there in the same edit. The same applies to an Arabic counterpart once one exists.

## Working Style

- Inspect the relevant source sections before modifying a module.
- Make the smallest coherent change; do not introduce unrelated refactors.
- Do not claim completion without running the applicable tests and reporting their result.
- When a requirement is unclear or conflicts with the source, stop and request a documented decision instead of guessing.
