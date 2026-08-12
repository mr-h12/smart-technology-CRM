# CRM System — Documentation Map

> **Purpose:** Load only the authoritative context needed for the current task.  
> **Arabic:** reading-only translation in `arabic/`; not a maintained companion (D-58).

## 1. Why This Map Exists

The CRM documentation is intentionally detailed. Reading unrelated sections during every task increases the chance of missing the few constraints that actually govern the change.

This map tells a coding agent which files and sections to load before working. It does not replace any source document and does not create requirements. If a mapped source conflicts with the CRM master documentation, the CRM master documentation and its decision log win.

## 2. Source Precedence

1. `CRM_Documentation_EN.md` — master requirements and decision log.
2. `MVP_Build_Plan_EN.md` — mandatory implementation order and module acceptance criteria.
3. `Coding_Standards_EN.md` — required engineering practices.
4. `OpenAPI_Contract_EN.md`, `Design_System_EN.md`, and `User_Personas_EN.md` — implementation contracts.
5. `CLAUDE.md` and `AGENTS.md` — agent operating instructions; they never override the first four sources.

## 3. Session Start Protocol

### 3.1 Read on every implementation session

1. `CLAUDE.md` when using Claude Code; otherwise `AGENTS.md`.
2. `Coding_Standards_EN.md` sections 1–2 and the section relevant to the task.
3. This map: identify the task row before loading product requirements.

### 3.2 Read for every API/UI change

- `OpenAPI_Contract_EN.md` for backend/API work.
- `Design_System_EN.md` for frontend/UI work.
- `User_Personas_EN.md` for role-specific screen, navigation, dashboard, visit, or workflow work.

### 3.3 Do not load broadly by default

- Do **not** read the entire CRM master document for a narrowly scoped task.
- Do read the full relevant section and every decision/edge case listed in the selected map row.
- Read additional sections only when the task crosses a module boundary, changes a shared rule, or exposes a conflict.
- If the map is incomplete for a task, inspect the CRM table of contents and report the missing link before coding.

## 4. Module Context Matrix

| Module / task | Required CRM sections | Required build-plan module | Also load | Must verify before coding |
|---|---|---|---|---|
| **Module 0 — Foundation** | §1 Overview; §3.12 overrides; §4.8 database rules; §14 Architecture; §15 Jobs; §16 Operations; §17 Files; §18 Notifications | Module 0 | Coding Standards §§3, 5, 7, 9–12; Design System §§1, 3–5; OpenAPI §§1–3 | P-01 and P-02 are complete; i18n, audit, storage, queues, seed data, and module boundaries are present. |
| **Identity & RBAC** | §2 D-28–D-30, D-32, D-34, D-38, D-41–D-44; §3.1–3.12; §9 Flow 0 and Flow 9; §10.1; §13; §14.3; §14.7–14.8 | Module 1 | Coding Standards §§5, 8–10, 13; OpenAPI §§2–5, 10; Personas P-01, P-03 | API/row-level permission denial, session lifecycle, lockout, hidden Super Admin, and required audit events. |
| **Settings, managed lists & currencies** | §2 D-17, D-20, D-52; §4.8 DB-05–DB-06; §5.3; §13 screens 4–6; §15 J-03, J-12 | Module 2 | Coding Standards §§5–7, 10; OpenAPI §§6, 8 | Configuration is database-driven; FX history/audit exists; rounding affects only new quotations. |
| **Customers** | §2 D-01, D-02, D-16–D-18, D-31, D-34–D-35, D-49; §3.3; §4.1–4.2, §4.5; §8 role screens; §9 Flows 1, 7, 9, 10; §10.1–10.2; §14.7–14.8 | Module 3 | Coding Standards §§5, 7–11, 13; OpenAPI §§4–8; Design System §§5–7; Personas P-03, P-04, P-06, P-07 | Scope filtering, archive/restore, owner transfer audit, incomplete import behavior, duplicate warning—not block—and customer-status derivation. |
| **Catalog & suppliers** | §2 D-19–D-22, D-36–D-37, D-45; §3.6–3.7; §7.1, §7.3; §10.3–10.4; §17 | Modules 4 and relevant parts of 6 | Coding Standards §§5–7, 9, 11; OpenAPI §§6–8; Design System §§6–7 | Descriptive catalog only; deactivate rather than delete; supplier-color chip; open editing audit; deactivation and price-change warnings. |
| **Deals / requests** | §2 D-01–D-02, D-12, D-14, D-17, D-49, D-53; §3.4; §4.1, §4.3–4.7; §8; §9 Flows 1, 3, 10; §10.5; §15 J-02–J-03 | Module 5 | Coding Standards §§5, 7–10, 13; OpenAPI §§5–9; Design System §§5–7; Personas P-03–P-08 | One customer can have concurrent deals; valid status transition; timeline entry; customer-status event; request approval; delivery-complete attribution. |
| **Supplier quotations** | §2 D-21–D-22, D-36, D-45, D-51; §3.6–3.7; §4.1; §7.1–7.2; §10.3; §17 | Module 6 | Coding Standards §§5–9, 12–13; OpenAPI §§4–9; Design System §§6–7; Personas P-06–P-08 | Nullable `deal_id`; product auto-add; file validation/storage; original offer snapshot; shared screen permissions. |
| **Customer quotations / pricing** | §2 D-03–D-11, D-26, D-36–D-37, D-42, D-46, D-50, D-52; §3.5 and §3.12; §4.1, §4.7–4.8; **§5 in full**; §6.1–6.6; §10.3–10.5; §14.4, §14.7; §17 | Module 7 | Coding Standards §§5–10, 13–15; OpenAPI §§4–9; Design System §§5–8; Personas P-03, P-04, P-06, P-07, P-08 | Decimal calculations; final-only rounding; captured FX; cost/margin visibility; Draft-only edit/delete; versioning; save blocks/warnings; `409` optimistic conflict; no supplier data in customer PDF. |
| **Approvals** | §2 D-10–D-11, D-42, D-50; §3.5 and §3.12; §6.3–6.6; §8; §9 Flow 4; §12.1–12.3 | Module 8 | Coding Standards §§8–10, 13–15; OpenAPI §§5, 7, 9; Design System §§6–7; Personas P-03–P-04 | Manager/TL parity, required return note, tax/margin audit, self-approval flag/audit/badge, and no automatic escalation. |
| **PDF generation** | §2 D-23, D-38–D-40; §3.5, §3.12; §6.2; §14.6; §15 J-01 and queue rules; §17; §18.2; §19 R-02 | Module 9 | P-01 specification; Coding Standards §§9, 12–13; OpenAPI §§4, 8–10; Design System §7.2 | Arabic shaping prototype passed; async generation; immutable stored PDF; retry/failure behavior; permission checks; unconditional customer-data exclusion. |
| **Customer response & purchase orders** | §2 D-08, D-12–D-13, D-53; §3.4–3.5; §4.6–4.7; §6.1–6.3; §9 Flow 1 and Flow 7; §10.5; §15 J-01; §17 | Module 10 | Coding Standards §§5–10, 13; OpenAPI §§5–9; Design System §7.2 | Full-copy versioning for Partial/Counter/Returned; mandatory reasons; archive quotation only; both PO numbers searchable; expiry job. |
| **Procurement** | §2 D-13–D-14, D-23, D-41; §3.4, §3.9, §3.12; §5.4–5.5; §8; §9 Flow 5; §11.2; §12.2; §17 | Module 11 | Coding Standards §§5–10, 13; OpenAPI §§5–9; Design System §7.2; Personas P-08 | Assigned scope; original/new cost; saving/final profit calculation; failed-reason rule; delivery attribution; financial visibility. |
| **Outdoor visits** | §2 D-33, D-44, D-56; §3.8; §8 Outdoor roles; §9 Flow 2; §11.2, §12.2; §14.4, §14.9; §18; §19 R-06 | Module 12 | Coding Standards §§9, 11, 13; OpenAPI §§3–6; Design System §§4–8; Personas P-05–P-06 | Three mandatory fields only; outcome rules; supervisor boundary after handover; mobile UI; specific connection-unavailable message; online-only scope with brief-draft protection. |
| **Reports** | §2 D-23, D-25, D-27, D-33; §3.10; §4.7; §9 Flow 8; **§11 in full**; §12; §15 J-04–J-08; §17; §18 | Module 13 | Coding Standards §§7–10, 12–13; OpenAPI §§4–9; Design System §§5–7; Personas P-01–P-08 | Snapshot/version semantics; lifecycle; roles and CEO exception; timing/working days; no deletion; duplicate-period warning; scheduled catch-up. |
| **Dashboards** | §2 D-25, D-27, D-47; §3.5, §3.10; §5.4–5.5; §11; **§12 in full**; §15 J-09; §16.4; §19 | Module 14 | Coding Standards §§7, 10–11, 13; OpenAPI §§4–6, 10; Design System §§5–8; Personas P-01–P-04 | Pre-aggregation, currency separation, role metrics, CEO no drill-down, feature-flagged/deferred metrics, and exact figures. |
| **Search / Meilisearch** | §2 D-48; §4.8 DB-09; §10.2; §14.2, §14.5, §14.9; §15 J-13; §19 R-09 | Module 15 and build-plan §3 | Coding Standards §§5, 8–10, 12–13; OpenAPI §6; Design System §§5–6 | All searches use `SearchService`; Arabic normalization; permission filtering; fallback to ILIKE; performance trigger; indexing jobs. |

## 5. Cross-Cutting Task Map

| Task | Mandatory sources to load | Key checks |
|---|---|---|
| **New database table or migration** | CRM §4.8; Coding Standards §§5, 7, 10, 13; relevant module row | Soft delete/audit columns, FKs, constraints, indexes, up/down migration, generated types, transaction impact. |
| **New or changed API endpoint** | CRM §14.7; OpenAPI Contract in full; Coding Standards §§5, 8–10; relevant module row | Permission and row scope, envelope, pagination/filtering, error codes, idempotency, audit, OpenAPI schema, negative API test. |
| **New or changed UI screen** | Design System in full; relevant Personas; relevant module row | Correct role navigation, RTL/LTR, all three themes, loading/empty/error/denied states, accessible keyboard flow, API-enforced action permissions. |
| **Money, FX, tax, margin, discount, or profit** | CRM §5 in full; §6 where quotation-related; Coding Standards §6 and §13; OpenAPI §8 | Decimal only, captured FX, final-only rounding, snapshots, excluded PDF fields, focused unit tests. |
| **Role, permission, or user lifecycle** | CRM §3 in full; §10.1; §13; §14.3; Coding Standards §9; OpenAPI §3 | Dynamic configuration, API+row enforcement, negative test, required audit, Super Admin hiding, no prohibited account creation. |
| **File upload, download, or attachment** | CRM §17 in full; §3.12; §14.3 and §14.6; Coding Standards §9; OpenAPI §8 | Parent permission, true MIME, size, virus scan, UUID storage path, no direct/public URL, backup inclusion. |
| **PDF or export** | CRM §3.5/§3.10 as applicable; §14.6; §17–18; P-01; Coding Standards §§9, 12 | Generator/download role distinction, asynchronous job, embedded Arabic font, stored immutable output, no supplier/cost/margin leakage. |
| **Scheduled job or queue worker** | CRM §15 in full; §16.2; Coding Standards §§10, 12–13 | Idempotency, retry/failure, catch-up behavior, queue priority, monitoring, audit/log evidence. |
| **Backup, startup, monitoring, deployment** | CRM §14.10; §16 in full; Coding Standards §§10, 12, 14 | Startup order, health check, durable queue, restore test, rollback/runbook, service status. |
| **New external integration** | CRM §2 D-56; §14.9; §19; Coding Standards §12 | Confirm it is not deferred; interface/adapter, feature flag, circuit breaker, retry/backoff, fail-safe behavior. |
| **Security incident or vulnerability fix** | CRM §14.3, §14.8; §16.4; Coding Standards §§9–10, 13–14 | Minimal safe patch, audit/log review, affected sessions/secrets, negative tests, no sensitive error exposure. |

## 6. Decisions and Risks to Check Before Work

Always inspect the referenced decision numbers in the selected row. Additionally, stop for explicit direction when a task depends on:

| Item | Why it matters |
|---|---|
| **OD-01** — additional-item taxability | Blocks final quotation tax calculation. Use the provisional assumption only with approval. |
| **OD-03** — server specifications | Blocks final infrastructure sizing and real-server setup. |
| **OD-02** — PDF template | Required before final PDF module rendering. |
| ~~OD-04~~ | Closed by D-59 — Cloudflare Tunnel + Access. |
| **OD-05** — daily workload | Affects queue/storage sizing. |
| **OD-06** — holiday calendar | Required for final report-deadline behavior. |
| **OD-07** — automatic red supplier rating | Post-MVP only; do not implement in MVP. |
| **OD-08** — duplicate-match threshold | Must be empirically tuned; preserve warning-only behavior. |

## 7. Task Execution Checklist

1. Classify the task by module or cross-cutting row.
2. Load the session sources and all sources in that row.
3. Extract decision IDs, permissions, data rules, edge cases, acceptance criteria, and open decisions into a short implementation note.
4. State `step → verification`; obtain approval before multi-file edits.
5. Implement the smallest coherent change, keeping module boundaries intact.
6. Run unit, integration, API-negative, E2E, build, and smoke checks required by the changed area.
7. Report commands/results and a concise manual test checklist.
8. Update this map only when a new authoritative document or dependency changes the required context.

## 8. Context-Loading Examples

### Example A — “Add a margin warning to the quotation builder”

Load the Customer Quotations/Pricing row, then CRM §5 and §6 in full, §3.5, D-03/D-04/D-36/D-42, Design System §7.2, Coding Standards §§6, 9, 11, 13, and OpenAPI §§5, 8, 9. Do not load Reports or Outdoor Visits unless the change exposes a shared dependency.

### Example B — “Add a customer archive filter”

Load the Customers row, CRM §3.3, §4.2, §9 Flow 7, D-31/D-34/D-35, OpenAPI §§4–8, Design System §§5–6, and the Manager/Team Leader/Sales personas. Verify row scope, archive/restore authority, pagination, audit entry, and RTL/LTR filter UI.

### Example C — “Investigate a failed nightly job”

Load CRM §15 and §16.2–16.4, the job’s owning module row, Coding Standards §§10 and 12–13, and OpenAPI §10 if an admin API is involved. Verify idempotency, retry count, queue monitor evidence, startup catch-up requirement, and no partial data write.
