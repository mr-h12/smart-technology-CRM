# CRM System — Claude Code Execution Guide

## Purpose

Build the CRM MVP described by the project documentation. This is an internal, on-premise CRM for Sales and Procurement, with Arabic and English support from the first release, desktop web, and an online-only PWA for field staff.

## Current State

Update this section whenever it stops being true.

- No application code yet. Stack is **Laravel**, recorded as **D-57** in the decision log and in §14.2.
- **P-01 PASSED** — see `prototypes/p01-arabic-pdf/`. Arabic shaping verified; `R-02` retired. PDFs render through headless Chrome, the engine Laravel's Browsershot drives.
- **P-02 deferred, not cancelled (`D-66`)** — development runs on a production-matched Docker environment (Linux containers, §14.2 stack) until the on-premise server is available. `P-02` still runs before the pilot rollout, and the deployment-debt register in `CHECKLIST.md` carries everything it would have proven.
- **OD-01 is closed** (2026-08-12, reconfirmed 2026-08-19: additional items are not taxed). **OD-03 remains unresolved** and still blocks Module 0.
- Track progress in `CHECKLIST.md`; `README.md` orients new contributors. Next action: close OD-03 + P-02 (server administrator), and confirm with the accountant whether the PO's «إشعار خصم» line is a sale discount or a separate credit note.

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

`D-66` governs how these two gates apply while the server is unavailable:

1. **P-01 Arabic PDF:** a full Arabic paragraph, item table, and numbers render with correct Arabic shaping and embedded fonts.
2. **P-02 Real-server deployment:** a Hello page works on the actual on-premise server and is reachable from a phone through Cloudflare (D-59). **Deferred under `D-66`** — build locally on Linux containers matching §14.2, never on the host OS directly. Developing on macOS hides the Linux font gap `P-01` found, which is the exact defect `P-02` exists to catch.

Before code is written, explicitly track these blockers:

- **OD-03:** server specifications. No longer blocks starting development (`D-66`); still blocks the server, `P-02`, and every item on the deployment-debt register.

`OD-01` is closed: additional items are **not** taxable (`D-62`). No provisional assumption survives — code that taxes delivery or installation is a defect.

## Required Delivery Order

Implement modules strictly in this order unless an approved change says otherwise:

`0 Foundation → 1 Identity & Dynamic RBAC → 2 Settings/Currencies → 3 Customers → 4 Catalog/Suppliers → 5 Deals → 6 Supplier Quotations → 7 Customer Quotations → 8 Approvals → 9 PDF → 10 Customer Response & POs → 11 Procurement → 12 Outdoor Visits → 13 Reports → 14 Dashboard → 15 Meilisearch`.

Do not advance a module until its acceptance criteria, tests, permissions, audit records, RTL/LTR states, and reversible migration pass. While `P-02` is deferred, the deployment item means the production-matched local environment plus a recorded deployment debt (`D-66`).

## Non-Negotiable Architecture

- Use a modular monolith: `Presentation → Application → Domain → Infrastructure`.
- Modules communicate through interfaces and domain events, never direct cross-module database access.
- Use PostgreSQL, Redis, local storage behind an abstraction, REST `/api/v1`, WebSockets, SPA, and PWA.
- Keep configuration in the database: roles, permissions, settings, managed lists, currencies, rounding units, and limits are not code constants.
- Every critical operation is transactional and idempotent. Use optimistic locking for quotations; return `409 Conflict` for stale concurrent edits.
- Use `Decimal`/database decimal types for all money. Floating point is forbidden.
- Store timestamps in UTC and display them in the user's timezone.
- Build `SearchService` in Module 3 with a PostgreSQL/ILIKE driver. All search calls must use it so Meilisearch can replace the driver later; fall back to ILIKE if Meilisearch fails.

## Laravel Conventions (D-57)

Framework choices that satisfy a documented requirement. Where a Laravel default conflicts with the documentation, the documentation wins.

- **Modules are directories with their own layers**, not Eloquent models carrying business rules. A module owns its domain rules, use cases, persistence interface, and API surface; cross-module work goes through interfaces or events, never another module's models.
- **Money uses `decimal:` casts backed by NUMERIC columns**, with BCMath for arithmetic. PHP floats are forbidden anywhere near a price (`DB-07`). Never use `round()` on an intermediate value — only the final total, by the configured currency unit.
- **Migrations always implement `down()`.** `migrate:rollback` must actually work before a module is done (`DEV-03`). Never edit an applied migration; add a correcting one.
- **`SoftDeletes` on every business table** satisfies `DB-01`. No `forceDelete()` on business data, ever.
- **Queues use the four documented names** — `critical`, `pdf`, `reports`, `maintenance`. Horizon supplies Queue Monitor (`OBS-02`) and the Scheduler view (`OBS-03`); prefer it over hand-built admin screens.
- **Scheduled jobs J-01…J-14 live in the scheduler**, each idempotent, with a retry limit and the documented startup catch-up (`D-55`, `ST-05`).
- **Permissions come from the database, not from hard-coded `Gate::define` calls.** Dynamic RBAC is `resource.action.scope` (`SEC-07`); a policy may read the database but must never embed the matrix in code.
- **Validate at the boundary with Form Requests; serialize with API Resources.** Controllers stay thin: validate, invoke a use case, serialize.
- **Set the application timezone to UTC** and convert for display only (`DB-08`).
- **Optimistic locking is not built in.** Quotations need an explicit version column plus `If-Match`, returning `409` on a stale write (`DB-12`, `API-12`).
- **PDFs render through Browsershot** (headless Chrome), asynchronously on the `pdf` queue, with fonts embedded — the approach proven by P-01.
- **All user-facing text lives in lang files.** No string literals in Blade, controllers, or components.
- **The frontend is a Vue 3 + TypeScript SPA on Vite consuming `/api/v1` (`D-67`).** **Never introduce Inertia or Livewire** — they bind the UI to controllers instead of the documented REST contract and break `AP-07`. The SPA displays backend results and may preview; it never owns a calculation, a permission decision, or a state transition.

## Internationalization and UI

- No hard-coded user-facing strings. Support Arabic and English from Module 0.
- Every screen must work correctly in RTL and LTR, including forms, tables, validation, numbers, and loading/empty/error states.
- Desktop is primary for office roles. Outdoor flows must be mobile-first PWA flows, online-only, with local draft preservation during a short connection drop.
- External access uses Cloudflare Tunnel + Access for five named users (D-59); the LAN is primary for everyone else. Access gates identity but never replaces system authentication or the permission matrix. When external access is unavailable, show a specific message, not a generic failure.

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
- Discount is a percentage of the subtotal only, and it is subtracted **before** tax so it reduces the tax base (`D-64`). Additional items are never taxed (`D-62`).
- Tax is optional and its percentage is per quotation, defaulting from the customer (`D-63`). An exempt quotation renders **no tax line at all**, not a zero line.
- Rounding is **optional per currency** (`D-65`). When it is on, apply it to the final total only, using that currency's configured unit. Store `rounding_diff` — it is `0` when rounding is off.
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

## Working Rhythm — One Point at a Time

This governs every module and overrides any default urge to batch work.

**Module → Step → Point.** Every module is broken into steps, and every step into points.
A point is the smallest unit that can be verified on its own.

1. **Do exactly one point per turn. Never two.** Finishing a point early is not a reason to
   start the next one.
2. **Stop and wait for review after every point.** The owner reviews everything. Do not
   proceed on assumed approval, and do not treat silence as approval.
3. **Before starting a step, publish its point list** and get it approved. The decomposition
   itself is reviewable — an unapproved point list is an unapproved plan.
4. **After every point, report as a checklist** with all six parts below. All six, every time,
   even when the point was trivial.

### The report after every point

| Part | What it must contain |
|---|---|
| **What was done** | One or two lines. The change, not the intention. |
| **Why it is correct** | Cited to `D-xx`, `§x`, `DB-xx`, `SEC-xx`, `ST-xx`, or an acceptance criterion. A claim of correctness with no citation is an opinion, and this project does not run on opinions. |
| **Checks run** | The actual command and its actual output. Pass or fail. Never "should work" — run it. |
| **Problems found** | Everything that went wrong, including what was hit and fixed mid-point. A point that reports no problems must say so explicitly rather than omitting the row. |
| **What this does NOT cover** | The honest gap. What a reader might wrongly assume is now handled. |
| **Next point** | Named, then stop. |

**Never report a point as complete on the strength of reasoning.** Run the check and paste the
output. If a check cannot be run, say that plainly instead of substituting confidence for evidence.

### Never assume — open it and look

Do not state, rely on, or build against what a file, image, package, or system
contains until you have checked it **in this session**. Reading is cheap; the
failures this prevents are not.

This rule exists because it was broken three times in one session, each time the
same way — assuming a binary was present in a container image:

| Assumed | Reality | How it surfaced |
|---|---|---|
| the probe image had `curl` | it ships `psql`, not `curl` | the CI probe failed mid-run |
| `nginx:alpine` had `openssl` | it has neither | the container restart-looped on exit 127 |
| a grep for `opcache` would match | it is listed as `Zend OPcache` | a false "MISSING" in a report |

None of these were knowledge gaps. Each was a check that took one command and
was skipped.

**Check before you depend on it:**

- A tool inside an image → `command -v`, in that image, before writing the line
  that calls it.
- A package's contents → query it (`dpkg -c`, `apt-cache show`), do not infer
  from its name.
- A configuration default → print it (`php -i`, `show <setting>`, `locale`), do
  not recall it.
- A documented rule → open the section and read it. Cite the line, not the
  memory of it.
- An external fact — a version, a requirement, an API — fetch the current
  source. Training data goes stale, and this project already outran it once.
- A file you are about to edit → read the exact text first; anchor edits on
  strings you have seen, and verify the anchor matches once and only once.

**The tell:** if you are about to write "should", "presumably", "it must have",
or "typically", stop. That sentence is a check you have not run. Either run it,
or say plainly that it is unverified and mark it as such.

**A verifier is not verified until it has failed.** A check that has only ever
passed proves nothing about whether it can detect the defect. Break the thing on
purpose, watch the check fail, then fix it back.

### End every message with what to search

Close **every** message — not only point reports — with a short "What to search"
section: the exact terms the owner can look up to understand the topic on their own,
without this conversation.

- Give searchable terms, not a summary. `docker compose depends_on condition
  service_healthy` is useful; "Docker startup stuff" is not.
- Name the specific technology, flag, or concept actually used, so the results match
  what was built rather than the general subject area.
- Three to six entries. Order them by what matters most for judging the work.
- Include the term for anything asserted as a constraint, so the claim can be checked
  independently rather than taken on trust.

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
