# CRM System — Universal Agent Execution Contract

## Purpose and Applicability

This is the tool-neutral execution contract for every coding agent working on the CRM project, including Codex, Claude Code, and Raflo. Read it fully before the first edit in every session.

`CLAUDE.md` is the Claude Code companion guide. Keep both files semantically synchronized; a difference in a project rule is a defect.

## Current State

Update this section whenever it stops being true. It must stay identical in meaning to the same section in `CLAUDE.md`.

- **The application exists.** 14 modules under `crm/app/Modules/`, 30 migrations, and a Vue 3 + TypeScript SPA on `/api/v1` (`D-67`). Measured on `main` at `16b40c2`, 2026-09-12: **2453 backend tests (13981 assertions)** and **750 frontend tests (44 files)**. Stack is **Laravel**, recorded as **D-57** in the decision log and in §14.2.
- **Module progress**, counted from `CHECKLIST.md`'s and `checklist/`'s own boxes on that commit: 0 Foundation **56 of 56** · 1 Identity & Dynamic RBAC 38 of 40 · 2 Settings, Managed Lists & Currencies 16 of 21 · 3 Customers 22 of 28 · 4 Catalog & Suppliers 28 of 30 · 5 Requests/Deals **32 of 33, finished 2026-09-09** · 6 Supplier Quotations **31 of 32, complete** (the open box is the unapproved `2.2b Idempotency-Key`) · **7 Customer Quotations in progress** — Step 1 (schema and domain, seven points) and Step 2 (pricing engine) closed; Step 3 (the write path) closed 2026-09-12 — 3.3 (#96), 3.4 (#97), 3.5 (#100), 3.6 (#101) and 3.7 (#102, `Idempotency-Key`) merged; Step 4 (actions on one quotation, five points) approved 2026-09-12 with defaults Q1–Q6 (#103), 4.1 `QuotationStatusTransition` (#104), 4.2 `submit-for-approval` (#105), 4.3 `new-version` (#106) and 4.4 `DELETE` (#107) merged, 4.5 price-drift `meta.warnings` (#108) merged — **Step 4 closed 2026-09-12**; Step 5 (list, five points) published on #110, unapproved. Modules 8–15 are untouched.
- **P-01 PASSED** — see `prototypes/p01-arabic-pdf/`. Arabic shaping verified; `R-02` retired. PDFs render through headless Chrome, the engine Laravel's Browsershot drives.
- **P-02 deferred, not cancelled (`D-66`)** — development runs on a production-matched Docker environment (Linux containers, §14.2 stack) until the on-premise server is available. `P-02` still runs before the pilot rollout, and the deployment-debt register in `CHECKLIST.md` carries everything it would have proven.
- **OD-01 is closed** (2026-08-12, reconfirmed 2026-08-19: additional items are not taxed). **OD-03 remains unresolved.** It no longer blocks a module — Module 0 closed under `D-66` — and still blocks the server, `P-02`, and every row of the deployment-debt register.
- Track progress in `CHECKLIST.md` — it holds only the live boxes, the debt registers, and the modules still open; a closed module's full point history is frozen verbatim in `checklist/module-0N.md` (nothing is ticked there). `README.md` orients new contributors. Next action: **Module 7 Step 5's point list is on #110 awaiting approval (Q1–Q7); Point 5.1 `dealIdsOwnedBy()` / `ownersOf()` starts once it is merged**; open owner questions: the `Idempotency-Key` retention period `OpenAPI §9.1` calls "defined" and nothing defines; the non-code items remain open — close OD-03 + P-02 (server administrator), and confirm with the accountant whether the PO's «إشعار خصم» line is a sale discount or a separate credit note.

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
2. **P-02 Real-server deployment:** a Hello page works on the on-premise server and opens from a phone through Cloudflare (D-59). **Deferred under `D-66`** — build locally on Linux containers matching §14.2, never on the host OS directly, because developing on the host hides the Linux font gap `P-01` found.

Track this blocker explicitly: **OD-03** (server specifications). It no longer blocks starting development (`D-66`) but still blocks the server, `P-02`, and the deployment-debt register in `CHECKLIST.md`. Do not settle it silently. **OD-01** is closed — additional items are **not** taxable (`D-62`), and no provisional assumption survives.

Implement modules in this strict order:

`0 Foundation → 1 Identity & Dynamic RBAC → 2 Settings/Currencies → 3 Customers → 4 Catalog/Suppliers → 5 Deals → 6 Supplier Quotations → 7 Customer Quotations → 8 Approvals → 9 PDF → 10 Customer Response & POs → 11 Procurement → 12 Outdoor Visits → 13 Reports → 14 Dashboard → 15 Meilisearch`.

Complete a module's acceptance criteria, tests, API authorization, audit coverage, RTL/LTR states, and reversible migration before advancing. While `P-02` is deferred, the deployment item means the production-matched local environment plus a recorded deployment debt (`D-66`).

## Architecture and Data Rules

- Use a modular monolith with `Presentation → Application → Domain → Infrastructure` layers. Modules communicate through interfaces and domain events, not cross-module database access.
- Use PostgreSQL, Redis, local file storage behind an abstraction, REST `/api/v1`, WebSockets, SPA, and online-only PWA.
- Keep roles, permissions, settings, managed lists, currencies, rounding units, and limits in the database. Do not hard-code extendable business values.
- Use transactions and idempotency for critical operations. Use optimistic locking for quotations and return `409 Conflict` for stale concurrent edits.
- Use Decimal/database decimal types for money; Float is forbidden. Store timestamps in UTC and display them in the user's timezone.
- Create `SearchService` in Module 3 with a PostgreSQL/ILIKE driver. All searches use it; Meilisearch later replaces the driver and failures fall back to ILIKE.
- Each table has soft delete and audit columns (`created_by`, `created_at`, `updated_by`, `updated_at`), plus database foreign keys and constraints.
- Use managed enum tables, never hard-coded enums. Version quotations and reports using `parent_id` and `version`.

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

- No hard-coded user-facing strings. Arabic and English are supported from Module 0.
- Every screen works correctly in RTL and LTR, including forms, tables, validation, numbers, and loading/empty/error states.
- Desktop is primary for office roles. Outdoor flows are mobile-first PWA flows, online-only, preserving a local draft during a short connection drop without presenting the system as offline-capable.
- External access uses Cloudflare Tunnel + Access for five named users (D-59); the LAN is primary for everyone else. Access gates identity but never replaces system authentication or the permission matrix. When external access is unavailable, show a specific message, never a generic failure.

## UI Verification

Any change to a screen, component, or stylesheet must be verified in a real browser (via the available browser automation, e.g. Claude Browser MCP) at both desktop and mobile widths, in Arabic (RTL, default locale) and English. jsdom tests alone are not sufficient evidence. Include the list of manual UI steps you performed in the change report.

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
- Discount applies to subtotal only and is subtracted **before** tax, reducing the tax base (`D-64`). Additional items are never taxed (`D-62`).
- Tax is optional and its percentage is per quotation, defaulting from the customer (`D-63`). An exempt quotation renders no tax line at all, not a zero line.
- Rounding is optional per currency (`D-65`). When enabled, round the final total only by that currency's configured unit; store `rounding_diff`, which is `0` when rounding is off.
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

## Terminology

- **Module N** is one of the numbered modules in `CHECKLIST.md` and the Required Delivery
  Order (0–15).
- **Step** and **Point** are a module's sub-units: every module is broken into steps, every step
  into points (see Working Rhythm). "Step 2" is always a step *within* a module, never a roadmap
  position — the documentation defines no separate "release step".
- A **module** is the whole of its steps and points. When asked to "plan Module N", plan the
  entire module — its full step-and-point breakdown — not just its first point.
- When a reference is ambiguous (which module a step belongs to, or module-vs-point scope), ask
  before planning rather than guessing silently.

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
4. **After every point, report as a checklist** with all seven parts below. All seven, every
   time, even when the point was trivial.

### The report after every point

| Part | What it must contain |
|---|---|
| **What was done** | One or two lines. The change, not the intention. |
| **Why it is correct** | Cited to `D-xx`, `§x`, `DB-xx`, `SEC-xx`, `ST-xx`, or an acceptance criterion. A claim of correctness with no citation is an opinion, and this project does not run on opinions. |
| **Checks run** | The actual command and its actual output. Pass or fail. Never "should work" — run it. |
| **Problems found** | Everything that went wrong, including what was hit and fixed mid-point. A point that reports no problems must say so explicitly rather than omitting the row. |
| **Waste audit** | The four questions below, each answered with evidence. "Nothing found" is an answer only after the four were actually asked. |
| **What this does NOT cover** | The honest gap. What a reader might wrongly assume is now handled. |
| **Next point** | Named, then stop. |

**Never report a point as complete on the strength of reasoning.** Run the check and paste the
output. If a check cannot be run, say that plainly instead of substituting confidence for evidence.

### The waste audit — every point, no exceptions

Code that works is not the same as code worth keeping. Every point audits **what that point
touched** for the four kinds of waste below. This is not a whole-repo sweep and not a refactor:
it is four questions asked against `git diff main...HEAD`, answered with evidence.

| What | How it is actually checked — not by eye |
|---|---|
| **Dead code** | Every symbol the point added is reached by something. `grep -rn "<name>"` over `app/` and `resources/js/`; one hit is the definition, so one hit means dead. PHPStan already reports an unused `private`; it says nothing about an unused public class, a lang key nobody reads, or a CSS class nobody applies. |
| **Duplicate logic** | Before writing a helper, search for it — the same thing under another name a few files over is the most common form of this. After writing it, search again for its distinctive line. A second implementation of something the project already has is a defect even when both are correct. |
| **Unused components** | A `.vue` file nobody imports, a route nobody links, a `data-testid` nothing queries, a translation key nothing renders, an exported function with no caller. |
| **Unnecessary complexity** | An interface with one implementation, a parameter every caller passes the same value for, a config for a value that never changes, an abstraction added for a second case nobody has asked for. The smallest thing that satisfies the citation is the right size. |

**Found waste is reported, not silently swept.** The rules above still hold: one point per turn,
and the approved point list is the scope.

- Waste **this point created** is removed inside this point. Leaving it for later is how it stays.
- Waste **this point merely revealed** is written into the debt register in `CHECKLIST.md` and
  named in the report. It is not fixed here — widening a point past its approved list is not the
  agent's call, and a large opportunistic cleanup buried in an unrelated point is unreviewable.
- A deliberate simplification with a known ceiling is not waste. Say what the ceiling is.

### What a point writes into `CHECKLIST.md`

Tick the box and append **one line**: date, PR number, and the one fact a reader needs
(`*(2026-09-12, #97 — 409 on stale If-Match)*`). The seven-part report lives in the PR
description, not here. Debt-register entries are the exception and keep their full reasoning.
When a module's last point closes, its section moves verbatim to `checklist/module-NN.md` and
the stub form in `CHECKLIST.md` (counts, link, open boxes) replaces it — that is part of the
manual-test-list handover, not a separate task.

**"Nothing found" is a legitimate result and often the true one** for a small point. It is only
legitimate after the four questions were asked, and the report says how they were asked.

### A point is not done until its checks have been read

Running a check is not the same as reading its result. Pushing is not the same
as passing.

- If the change triggers CI, **wait for the run and read its conclusion** before
  reporting the point. A commit that is pushed but unverified is a point in
  progress, not a point finished.
- If a check runs somewhere you cannot see from here, say so in the report
  rather than implying it passed.
- Report the actual conclusion, not the intent. "CI: success" is a fact; "CI
  should pass" is the same unverified claim in a different costume.

This is written down because it was broken: point 0.6 was reported complete with
"all checks passed" while its CI run had already failed. The failure was real —
`USER www-data` left Chromium unable to create its user-data directory, which
silently broke the PDF capability proved one point earlier. The verifier caught
it correctly on the first try. Nobody read it.

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

### At the end of every module — the manual test list

A module is not handed over until the owner has been given a list of things to
click. The gates prove the code compiles, type-checks and passes its tests;
only a person at the screen proves the module does what the owner wanted.

When the last point of a module is done, and **before the first point of the
next one**, publish a **manual front-end test list**:

- **In Arabic**, like every other explanation to the owner.
- **One line per check, written as an action and the result it must produce** —
  "افتح … ثم اضغط … ⇒ يجب أن ترى …". "Test the catalog screen" is not a check.
- **Grouped by screen, in the order a person would actually walk them**, so the
  list can be run top to bottom without jumping between roles and pages.
- **Name the role each check needs.** A check only a Manager can run is useless
  to somebody signed in as the CEO, and the role *is* half of what is being
  tested (`SEC-07`).
- **Every acceptance criterion of the module appears at least once, named**, so
  the list and `CHECKLIST.md` can be read against each other.
- **Both languages and both directions**, and the states a happy path hides:
  empty, loading, error, and refused-by-permission.
- **Say plainly what cannot be tested yet and why.** A criterion whose screen
  belongs to a later module is a gap the owner should see, not one the list
  should quietly skip.

The list goes in the message, not only in a file, and it closes a module the
way a seven-part report closes a point.

## Engineering Behavior

- Translate each request into observable success criteria before coding. For multi-file work, show `step → verification` and wait for approval before editing.
- State assumptions. When a requirement is ambiguous, underspecified, or conflicts with an authoritative source, stop and ask instead of guessing.
- Make the smallest coherent change. Avoid speculative abstraction, configuration, integration, and unrelated refactoring.
- Modify only required files, preserve the existing style, and mention unrelated defects instead of editing them.
- For a defect, write a failing regression test first whenever practical. Tests are mandatory for money, authorization, concurrency, workflows, versioning, and multi-table writes.
- Make schema changes in a new managed migration with tested up/down paths. Never manually alter production schema or an already-applied migration. Regenerate database types after schema changes.
- Use strict typing and backend boundary validation. Do not add untyped escape hatches or client-owned business logic.
- Two failed attempts at the same fix end the attempt. Report what was tried, what was observed, and the current hypothesis; a third variation waits for the owner.
- Read narrowly: when the location is known, read those lines rather than the whole file, and refer back to a file already read in this session instead of reading it again. This governs how much is read, never whether it is read.
- Trim tool output: pipe verbose commands through `head`/`tail` and quote the failing lines. §"A point is not done until its checks have been read" still requires the real output; it does not require the noise around it.
- Dispatch fan-out searches — sweeps over many files or directories where only the conclusion matters — to a subagent, keeping file contents out of the main context.

## Team Collaboration and Module Ownership

- **Module isolation.** Work inside one module (`app/Modules/Identity/` vs `app/Modules/Admin/`) touches no file outside it, except shared core components — `app/Support/`, configuration, or migrations that module owns. Cross-module needs go through an interface or a domain event, not an edit in the neighbouring module.
- **Feature branching.** Each task or point is developed on an independent branch named `feature/<module-name>-<short-description>`.
- **Quality gates before any merge.** All of these run locally, and their output is read, before a branch merges:
  - `php artisan test`
  - `npm run test:unit`
  - `./vendor/bin/pint --test`
  - `./vendor/bin/phpstan analyse --memory-limit=1G`
  - `./vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`
  - `./vendor/bin/deptrac analyse --config-file=deptrac.modules.yaml`
- **No direct push to `main`.** Every merge requires a review confirming that the deptrac boundaries and strict typing remain intact.

## Delivery Protocol (per checklist point)

1. Write failing tests first (TDD) before implementation.
2. Run the full gate suite locally: lint, static analysis (PHPStan), unit + feature tests.
3. Open a PR and WAIT for CI to go green on the *new* commit SHA (filter CI status by SHA, never trust the latest row).
4. Report the actual command output as evidence — never claim 'tests pass' without pasting the result.
5. Ask the user to merge (Claude cannot run `gh pr merge`).

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
