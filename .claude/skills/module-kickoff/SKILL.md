---
name: module-kickoff
description: Start a CRM module or sub-task with the correct context loaded and a complete implementation scaffold. Use when beginning any module from the delivery order (0 Foundation through 15 Meilisearch) or a task that belongs to one. Loads the matching Documentation_Map row, extracts the governing decisions, and emits the module template with Given/When/Then criteria before any code is written.
disable-model-invocation: true
---

# Module Kickoff

Produces the pre-code scaffold required by `Documentation_Map_EN.md` §7 and `MVP_Build_Plan_EN.md` §1. Emits a plan — it does not write implementation code.

## Step 1 — Refuse to start if the gates are closed

Check and report before anything else:

- **P-01** (Arabic PDF prototype) and **P-02** (real-server deploy) must both have passed. No feature module begins until they do.
- **OD-01** (are additional items taxable) and **OD-03** (server specifications) must be resolved. Both block the start.
- The module immediately before this one in the delivery order must be complete: acceptance criteria, tests, permissions, audit records, RTL/LTR states, and a reversible migration.

If any gate is open, say so and stop. Do not scaffold around a blocker.

## Step 2 — Load exactly the right context

Open `Documentation_Map_EN.md` §4 and find this module's row. Load precisely what the row names — its CRM sections, its build-plan module, its "Also load" column — and nothing more. §3.3 forbids reading the whole master document for a narrowly scoped task.

Then read the row's "Must verify before coding" cell. Those are the traps for this module specifically.

## Step 3 — Extract the governing rules

List, with IDs, every rule that constrains this module:

- Decisions (`D-xx`), database rules (`DB-xx`), scheduled jobs (`J-xx`)
- Architecture/security requirements (`AP-xx`, `SEC-xx`, `API-xx`, `DB-xx`, `ST-xx`)
- The permission matrix rows this module touches (§3.3–3.11) and the §3.12 overrides
- Any open decision (`OD-xx`) the module depends on

An implementation note that cites no source is a new requirement, not a task. Stop and request a decision.

## Step 4 — Emit the scaffold

```
## Module N — <name>

### User story
As a <role>, I want <capability>, so that <outcome>.

### Acceptance criteria
Given <state> · When <action> · Then <result>      (one block per criterion)

### Tables and relationships
<table>: columns, PK, FKs, constraints, indexes for intended joins/filters/sorts
Every table carries soft delete and created_by/created_at/updated_by/updated_at (DB-01, DB-02).

### Migration
up: ... / down: ...   (both tested)

### Endpoints
METHOD /api/v1/<resource> — permission <resource.action.scope> — audit <event> — idempotency <yes/no>
Paginated. Unified envelope. Documented error codes.

### Frontend
Screens, RTL and LTR, plus loading / empty / error / disabled / permission-denied states.

### Tests
Unit: <domain rules>
Integration: <workflows, constraints, audit writes>
E2E: <critical path>
Negative authorization: <one per permission-sensitive operation>

### Traceability
Implements: D-xx, DB-xx, J-xx, <acceptance criterion>
```

## Step 5 — Get approval before editing

Present `step → verification` and wait. Coding_Standards §2 requires approval before any change spanning more than one file.

## Reminders that catch most mistakes here

- Money is `Decimal`. Calculations run in the backend. Rounding applies to the final total only.
- Permissions are enforced at API *and* row level; a hidden button is not authorization.
- No hard deletes. Deactivate or archive.
- No hard-coded strings, enums, permissions, or theme values — configuration lives in the database.
- Every search call goes through `SearchService`, from Module 3 onward.
