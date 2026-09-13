> Frozen history of Module 5, cut verbatim from `CHECKLIST.md` on 2026-09-12 (commit `f8b993c`).
> Open boxes here are stale copies — the live ones are in `CHECKLIST.md`. Do not tick here.

## Module 5 — Requests / Deals ⭐

> As a Team Leader, I want to enter customer requests and assign them to employees, so that work
> flows down the right path.

**Tables** `deals` · customer-status engine (`recompute_customer_status`)

**Endpoints**
- [x] `GET`/`POST`/`PATCH` `/api/v1/deals` (Points 2.2–2.3) — no `DELETE` at any permission (`DB-01`)
- [x] `PATCH /api/v1/deals/:id/assign` (2.4) · `/approve` · `/reject` (2.5) · `/status` (2.6)
- [x] `POST /api/v1/deals/:id/documents` (4.1)

#### Step 1 — schema *(point order approved 2026-08-31)*

- [x] **1.1** `deals` — §4.3's fields, `DB-01`/`DB-02`'s block, `DB-09`'s four indexes, and closing
      the `deal_files.deal_id → deals` debt Module 0 left open.
      **`deal_documents` and `deal_status_history`, both named in the build plan, are not new
      tables.** `deal_files` (Module 0 Point 5.1) already is the first — a pivot built before its
      parent existed, exactly for this day. `deal_status_history` is deferred rather than built:
      `audit_log` already stores `event`/`entity_type`/`entity_id`/`old_values`/`new_values`/
      `user_id`/`created_at`, which is §4.4's "old status · new status · who · when" verbatim, and a
      second table recording the same fact is the defect DB-11 and AUD-02 exist to prevent. Recorded
      here rather than in `docs/`, awaiting a `D-xx`; the table line above is corrected to match.
      **None of §4.3 says "Required"**, unlike §4.2's one explicit marker on `name` — `customer_id`
      is `NOT NULL` on §4.1's entity map instead, not an annotation, and everything else follows
      Module 3 Point 1.1's reading exactly: nullable unless something other than the word "Required"
      forces otherwise. `status` is the one exception to that nullability, for the opposite reason —
      §4.4 draws no "unset" state, so it defaults to `lead` (Flow 1 step 2).
      ⚠️ **`approval_status` is nullable, and NULL is a fourth state, not a gap.** §4.3 ties the three
      named values to employee-entered requests only; a Team-Leader-entered deal (Flow 1) never goes
      through approval at all, which is a different fact from "pending" and needs a value none of the
      three named ones can hold. `D-63`'s null `tax_percent` is the precedent followed rather than
      reinvented.
      ⚠️ **`rejection_reason` is mandatory for exactly one rejection, and §4.4 quietly names a
      second one it does not cover.** §4.3 places the column directly under `approval_status`, so
      the CHECK ties it to `approval_status = 'rejected'` only. §4.4 separately requires a reason for
      a deal reaching **Lost**, and no field in §4.3's table is named for it — whether that transition
      reuses this column or needs its own is an **open owner question**, left to whichever point first
      builds the `Lost` transition rather than answered here.
      **`service_type` shares a name with `catalog_items.service_type` and nothing else** — the
      catalog's is an `enum_lists` code (installation, repair, …), this one is the request's own
      Product/Service split, the same shape as `catalog_items.kind`. No source relates the two, so no
      column here points at the other; stated so a future reader does not go looking for a
      relationship neither table documents.
      **`DB-09`'s four categories, and each has a named caller**: `customer_id` for the customer
      detail page's own-deals list, `owner_id` (`scopeIndex`, paired with `deleted_at` on
      `customers.sales_owner_id`'s precedent) for §3.4's `Own` scope, `status` for the Kanban board
      and the dashboards, `last_activity_at` for `J-03`.
      **49 tests · 1752 backend (10911 assertions) · pint 414 files · PHPStan level 10 clean ·
      deptrac violations 0 / uncovered 0 on both configs.** Not RED-first in the usual sense — the
      migration and its test were written together rather than the test first — stated rather than
      hidden, since this project's own discipline is to say so plainly instead of implying a process
      that did not happen.
      **Two deliberate breaks, both real regressions the tests had to catch on the second try.**
      (1) The `rejection_reason` mandatory-when-rejected CHECK deleted → exactly the two tests
      naming it failed, nothing else. Restored, confirmed with `shasum -a 256 -c`. (2) The
      `deal_files.deal_id → deals` key deleted → **every affected test still passed**, because
      `RefreshDatabase` does not re-run a migration whose filename it has already recorded, so the
      edited `up()` was never applied to `crm_test` at all until an explicit
      `migrate:fresh --force` against it forced the schema to match the file. Once it did, the
      constraint test *still* passed wrongly a second way: its random `file_id` alongside the random
      `deal_id` tripped `deal_files.file_id`'s own pre-existing key, so a `23503` came back for a
      reason that had nothing to do with the constraint under test. Fixed by inserting a real `files`
      row and leaving only `deal_id` invalid — the same "a decoy that cannot fail the intended way
      proves nothing" lesson Module 3 Point 2.1 recorded for `SearchService`'s escaping test, found
      here the same way: by breaking the code and watching the test fail to notice.
      **Not covered:** 1.1 is the table. No model, no repository, no endpoint, and no code generator
      — `document_sequences` (Module 0) still has no consumer; allocating a real `DL-2026-0001` inside
      a `DB-11` transaction is Step 2's `POST /deals`, not this point. No `recompute_customer_status`
      (that table line is the engine, not this schema). No row-scope resolution for `owner_id` beyond
      the index — §3.4's five scopes are Step 2's, and `CustomerRowScope`'s own `Asgn` case already
      says it resolves to nothing until this module exists; that debt is not repaid here, only the
      table it was waiting on now exists.

#### Step 2 — the Deals API *(point order approved 2026-08-31)*

- [x] **2.1** row-scope resolution (§3.4: All · Team · Out · Own · Asgn) + negative-authorization
      tests, on `CustomerRowScope`'s precedent (Module 3 Point 3.1) transcribed rather than shared —
      `deptrac.modules.yaml` gives `Deals` an empty ruleset, same as `Customers`.
      ⚠️ **`Asgn` still fails closed, and the reason changed rather than closed.** Customers' own
      `Asgn` failed because "`deals` is Module 5" — true until this week. §3.4's `Asgn` column
      belongs to **Procurement**, not to `owner_id` (§4.3: "assigned sales employee"), and no field
      anywhere in §4 says which procurement employee a deal is assigned to. Reusing `owner_id` would
      silently redefine what §4.3 already documents it as, so `Asgn` resolves to no rows here too —
      a fresh, still-open gap wearing the same name as the one Module 3 recorded, not the same gap
      closing. `Team` and `Out` fail for their original, unchanged reasons (no team entity; `visits`
      is Module 12).
      **`DealRowScopeTest` reads §3.2's code table out of the same document `CustomerRowScopeTest`
      reads**, so the two transcriptions are checked against one source rather than against each
      other — two modules quietly drifting on what `own` means would be worse than either being wrong
      on its own.
      **11 tests · 1763 backend (10940 assertions) · pint 416 files · PHPStan level 10 clean ·
      deptrac violations 0 / uncovered 0 on both configs** (`Deals`'s empty ruleset is satisfied
      outright — the class imports nothing but `InvalidArgumentException`).
      **One deliberate break:** `Team` changed to return `unrestricted` — exactly the two tests
      built to catch a widening scope failed (`test_that_a_scope_with_no_mechanism_permits_nothing`
      and `test_that_team_adds_nothing_to_own`), nothing else. Restored, confirmed with
      `shasum -a 256 -c`.
      **Not covered:** no controller, no route, no HTTP-level negative-authorization test — those
      arrive with 2.2's endpoint, the same order Module 3 used. No resolution of the `Asgn` gap; it
      is recorded, not repaid.
- [x] **2.2** `GET /deals` and `GET /deals/{id}` — `OpenAPI §6`'s query contract, §4.2's collection
      envelope, `§5.1`'s 404, `D-48`'s search — `CustomerController`'s shape (Module 3 Point 3.2), on
      the same reasoning throughout.
      **Filters are §4.3's own closed vocabularies** — `status`, `service_type`, `source`,
      `approval_status` — rather than an invented set; the same reading `CustomerListCriteria` gave
      §4.2's columns. **Default sort is `-last_activity_at`**, the one field §4.3 gives explicit
      operational meaning (`D-17`, `J-03`); `code` and `created_at` are the other two allowed.
      **`SearchIndex::Deals` added**, `title` the only searched column — §4.3's one free-text field,
      the same "one column, one line to change" floor `Customers` and `Suppliers` were given.
      `filterable()` is empty, on Suppliers' precedent rather than Customers': the row scope is
      applied to the builder directly in `EloquentDealDirectory`, the same mechanism
      `EloquentCustomerDirectory` actually uses regardless of what its own `filterable()` declares.
      **The visible cost of Point 2.1's fails-closed scopes, tested rather than left implicit:**
      Team Leader, Outdoor Supervisor and Procurement each see zero deals today, exactly as
      `CustomerListEndpointTest` found for the equivalent three roles in Module 3.
      **`deptrac.modules.yaml` grants `Deals` two entries fewer than a full Customers-style
      crossing** — `Framework`, `SharedContracts`, `IdentityContract` — and no `UserDirectoryInterface`
      use: §3.4 has no analogue to §10.1's deactivated-employee filter, so the crossing this point
      actually makes is narrower than the one it was granted room for.
      **18 tests · 1781 backend (11088 assertions) · pint 428 files · PHPStan level 10 clean (first
      run) · deptrac violations 0 / uncovered 0 on both configs.**
      **One deliberate break, on the controller-adjacent layer rather than the schema:**
      `EloquentDealDirectory::scoped()` changed to return every row unconditionally → exactly the
      four tests that depend on real scoping failed (`indoor sales sees only their own`, `a role
      whose scope has no mechanism sees nothing`, `a row outside scope is 404`, `q cannot reach
      outside the row scope`) and nothing else. Restored, confirmed with `shasum -a 256 -c`.
      **Problems found:** a test-fixture bug, not a production one — `substr(str_replace('-', '', $id), 0, 4)`
      for a throwaway `DL-` code takes UUIDv7's **leading** hex digits, which encode a timestamp, so
      several deals inserted in one test's loop collided on the same code and the insert raised
      `23505` instead of the test running. Fixed by taking the **trailing** four digits instead — the
      random tail — in all three files that had copied the pattern
      (`DealSchemaMigrationTest`, `DealListEndpointTest`, `FilesMigrationTest`). Caught by running the
      test, not by inspection.
      **Not covered:** no `POST`, no action routes (`/assign`, `/approve`, `/reject`, `/status`), no
      `/documents`, no `AuditContract` — all later points. The `Asgn` gap from 2.1 is unchanged.
- [x] **2.3** `POST /deals` and `PATCH /deals/{id}` — `SaveCustomer`'s shape (Module 3 Point 3.3) end
      to end: draft, ownership-within-scope, transaction, audit.
      **`document_sequences` (Module 0) gets its first consumer.** `EloquentDealDirectory::nextCode()`
      allocates `DL-YYYY-NNNN` with one `INSERT … ON CONFLICT (prefix, year) DO UPDATE … RETURNING`,
      not a read-then-write — two concurrent creates serialise on the row lock instead of racing to
      the same number, the same "the database enforces it" reasoning `D-71` gave the file-attachment
      keys.
      ⚠️ **`approval_status` is derived from *who* creates the deal, and that reading is recorded as
      an inference, not a documented rule.** Flow 1 has a Team Leader enter a deal straight to
      `Lead`, no approval; Flow 3 has an employee's request need one. Neither flow is named on the
      request itself, so the signal used is §3.4's create scope: `unrestricted` (`all` — Manager,
      Team Leader) skips approval (`null`); a scoped (`own`) create sets `pending`. **Flow 3's
      "stays inactive until approved" is not built** — §4.3 has no visibility column, and none is
      invented here; what "inactive" means structurally is left to whichever point builds
      `/approve`/`/reject`.
      **Five of §4.3's columns are prohibited on the boundary** (`code`, `status`, `approval_status`,
      `rejection_reason`, `last_activity_at`) and two more on `PATCH` only (`customer_id`, `owner_id`)
      — `SaveCustomerRequest`'s reading of which fields a generic write may never carry, extended by
      one: `customer_id` has no documented transfer operation at all, so it is not even a future
      action route the way `owner_id`'s `assign_owner` is.
      **`deptrac.modules.yaml` grants `Deals` its fourth entry, `AuditContract`**, on identical terms
      to every other module's first write.
      ⚠️ **`EloquentDealDirectory` is not part of the eight-class (now growing) "invisible writer"
      hole `AuditEnforcementTest` tracks.** `EloquentCustomerDirectory` and `EloquentSupplierDirectory`
      escape that scanner because neither imports `ConnectionInterface`; this one does, for the
      sequence allocator, so its `->save(` is actually found and the class is correctly registered
      with a reason rather than falling through unseen. Not a fix for the hole — a different class
      that happens not to have it.
      **23 tests · 1804 backend (11202 assertions) · pint 432 files · PHPStan level 10 clean (after
      one fix — see below) · deptrac violations 0 / uncovered 0 on both configs.**
      **Two deliberate breaks, each isolating one of the point's two genuinely new mechanisms.**
      (1) `nextCode()` changed to always return `…-0001` → exactly
      `test_that_two_created_deals_receive_different_codes` failed, on the `UNIQUE` constraint the
      migration already carries. (2) The ownership-within-scope refusal in
      `SaveDeal::ownedWithinScope()` deleted → exactly
      `test_that_indoor_sales_cannot_file_a_deal_under_somebody_else` failed. Both restored, both
      confirmed with `shasum -a 256 -c`.
      **Problems found:** (1) `customer_id` was missing from `Deal`'s `#[Fillable]` list — Eloquent's
      mass assignment silently drops an unfillable key rather than erroring, so the first create
      attempt inserted `customer_id = NULL` and failed on the column's own `NOT NULL`, not on
      anything this point wrote. Caught by running the create test, not by review. (2) PHPStan level
      10 rejected two things in the test file: `assertStringStartsWith()` against a `mixed` array
      value (fixed with `assertIsString()` first) and `(string)` on an untyped `stdClass` property
      read from `DB::table(...)->first()` (fixed on `columnType()`'s own precedent in
      `FilesMigrationTest`: assert the property exists, then cast with an explicit
      `@phpstan-ignore-line cast.string`, rather than trusting the cast silently).
      **Not covered:** no action routes (`/assign`, `/approve`, `/reject`, `/status`), no
      `/documents`. The `Asgn` gap from 2.1 is unchanged. Flow 3's "inactive" reading is an open
      owner question, not an implementation.
- [x] **2.4** `PATCH /deals/{id}/assign` — `AssignCustomer`'s shape (Module 3 Point 3.5), on the
      same reasoning throughout: idempotent, silent when nothing changed, nobody notified (§18.1's
      badge list and §18.2's five-name email list both omit it).
      ⚠️ **§3.4 grants `assign_owner` to Manager (`All`) and Team Leader (`Team`) only, and `Team`
      still has no mechanism (Point 2.1).** A Team Leader reaches this endpoint for every deal in
      the company and finds none of them — the exact half-unreachable permission row
      `customer.assign`'s own `Team` grant already carries. Not resolved here; the debt is 2.1's.
      **`DealDraft::forAssignment()` added**, the one field `forUpdate()` deliberately refuses, on
      `CustomerDraft::forAssignment()`'s precedent — the transfer goes through its own permission
      and its own route, never the generic `PATCH`.
      **15 tests · 1819 backend (11262 assertions) · pint 435 files · PHPStan level 10 clean ·
      deptrac violations 0 / uncovered 0 on both configs.**
      **One deliberate break:** the "already theirs" no-op guard deleted → exactly
      `test_that_assigning_to_the_current_owner_changes_nothing_and_records_nothing` failed, nothing
      else. Restored, confirmed with `shasum -a 256 -c`.
      **Not covered:** `/approve`, `/reject`, `/status`, `/documents`. The `Team` gap on this row and
      the `Asgn` gap from 2.1 are both unchanged.
- [x] **2.5** `PATCH /deals/{id}/approve` and `/reject` — Flow 3's decision, one use case and one
      permission for both directions, on `ArchiveCustomer`'s shape (Module 3 Point 3.4) with one
      deliberate difference.
      ⚠️ **Re-deciding is refused, not treated as an idempotent repeat.** Archive/restore's
      idempotence rests on Flow 7's own select-all UI, where "already in that state" is the ordinary
      case. No source describes re-approving an approved deal or un-rejecting a rejected one, so both
      — and deciding a deal whose `approval_status` is `NULL` (Flow 1, never submitted) — are refused
      with **`409 state_transition_invalid`**, this codebase's first use of that `OpenAPI §5.1` row.
      `DealApprovalRefused` is the new exception; `ApiExceptionRenderer`/`bootstrap/app.php` gain
      their sixth and first-409 handler pair.
      **`deal.approve` is the only permission — there is no `deal.reject` row**, on `customer.archive`
      covering both `archive` and `restore`. Both routes carry `permission:deal.approve`.
      **`rejection_reason` is validated at the boundary before it ever reaches the CHECK** (Point
      1.1) that would otherwise turn a blank reason into a 500 — `RejectDealRequest`'s `regex:/\S/`
      beside `required`, on `SaveCustomerRequest`'s reading of `name`.
      **`EloquentDealDirectory::reviewApproval()` sets `approval_status`/`rejection_reason` directly**,
      never through `DealDraft`, on `nextCode()`'s precedent (Point 2.3): neither field is ever
      caller-writable, only ever set by a use case that has already decided the value.
      **18 tests · 1837 backend (11332 assertions) · pint 439 files · PHPStan level 10 clean ·
      deptrac violations 0 / uncovered 0 on both configs** (`ReviewDealApproval` needed no
      `AuditEnforcementTest` register entry — it calls `reviewApproval(`, not a DML verb the scanner
      matches, the same hole `ArchiveCustomer`'s `setArchived(` call already has).
      **One deliberate break:** the pending-only guard deleted → exactly the four tests naming a
      non-`pending` source state failed (never-submitted, already-approved-then-approve,
      already-rejected-then-approve, already-approved-then-reject), nothing else. Restored, confirmed
      with `shasum -a 256 -c`.
      **Not covered:** `/status`, `/documents`. The `Team` and `Asgn` gaps are unchanged. Approving
      does not touch `status` or `owner_id` — Flow 3's "activated and assigned" is not built; nothing
      in §4.3 names what "activated" sets, and `owner_id` is already set at creation (2.3) for the
      one case (`Own`-scoped, employee-entered) that reaches `pending` at all.
- [x] **2.6** `PATCH /deals/{id}/status` — §4.4's transition graph, closing the `lost_reason` open
      question Point 1.1 recorded, and this module's first two-permission action.
      **A new migration adds `lost_reason`, not a reuse of `rejection_reason`.** That column is tied
      by its own CHECK to exactly one rejection (Flow 3's pre-pipeline `approval_status = 'rejected'`);
      `Lost` is a deal that *entered* the pipeline and did not close, a different fact. Its own
      mandatory-when-`lost` CHECK mirrors the rejection one exactly. Full reasoning in the migration's
      own docblock.
      **`DealStatusTransition` is built from §4.4's table, not its ASCII diagram** — the diagram draws
      `↘ Lost` once, near `Won`, which reads as branching from one place; the table names both real
      sources explicitly (`Quotation Sent` and `Negotiations`), and the table is what this class
      follows. `Lost` and `Delivery Complete` are terminal — an empty edge list, not a self-loop.
      ⚠️ **§4.4's "Who changes it" column is not enforced beyond the coarse `deal.change_status`
      scope, on purpose.** Every name in that column is a role already covered by `change_status`'s
      own seeded scopes, and no second permission exists in `PermissionMatrix` for any transition
      except one. Building finer-grained checks the matrix does not seed would be inventing
      authorisation, the same restraint every row-scope class in this module already exercises.
      **`Delivery → Delivery Complete` is the one exception, and it needed a real second permission
      check inside the use case rather than route middleware** — `D-14`'s four roles
      (`deal.mark_delivery_complete`) are a genuinely *different* set from `change_status`'s (Outdoor
      Supervisor and Outdoor Sales hold one and not the other), and which permission applies depends
      on the request body's target status, which middleware cannot see. `ChangeDealStatus` asks
      `AuthorizeAction` directly — legal because `Deals` already holds `IdentityContract` — and
      resolves a *second* `DealRowScope` from that decision to check the row again.
      **`DealStatusTransitionRefused` is a new exception, not a reuse of `DealApprovalRefused`**,
      despite sharing `409 state_transition_invalid`: the two guard different rules (§4.4's graph vs.
      Flow 3's one-time decision), the same way `InvalidCustomerListQuery`/`InvalidSupplierListQuery`
      stay separate despite an identical rendered shape.
      **19 endpoint tests + 6 migration tests · 1862 backend (11416 assertions) · pint 446 files ·
      PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on both configs.**
      **Three deliberate breaks.** (1) The `lost_reason` CHECK deleted → exactly the two tests naming
      it failed. (2) `DealStatusTransition::isAllowed()` changed to always return true → exactly the
      five undocumented-edge tests failed, nothing else. (3) The `mark_delivery_complete` re-check
      deleted from `ChangeDealStatus` → exactly `test_that_an_outdoor_sales_owner_cannot_mark_delivery_complete`
      failed — the one test that actually proves the second permission does anything. All three
      restored, all confirmed with `shasum -a 256 -c`.
      **Problems found:** Point 1.1's own `test_that_each_status_section_4_4_draws_is_accepted` broke
      the moment the `lost_reason` CHECK existed — it iterates all twelve statuses including `lost`
      with no reason, which the new constraint correctly refuses. Fixed by giving that one iteration
      a reason; caught by running the full suite after this point's migration landed, not by
      inspecting the older test.
      **Not covered:** `/documents`. The `Team`/`Out`/`Asgn` gaps from 2.1 are unchanged — a Team
      Leader still reaches this endpoint for every deal and finds none of them. `recompute_customer_status`
      (Won → customer becomes "Customer"; all-Lost → "Deal Not Completed") is **not built** — it is a
      cross-module event this point deliberately does not reach into Customers for; it needs its own
      point, most likely a domain event `DealStatusChanged` with a listener, on `AP-05`'s "event-driven
      internally" principle rather than a direct write into `customers` from here.

#### Step 3 — `recompute_customer_status` *(§4.5, `D-49`, `J-02`; not a pre-approved step —
flagged here for review rather than assumed)*

- [x] **3.1** `J-02`'s event-triggered half — wired into `POST /deals` and `PATCH /deals/{id}/status`,
      the two writes that change what §4.5's rule reads. The nightly correction half (`J-02`'s other
      trigger) is **not this point** — it is the first scheduled job this codebase would implement at
      all (`Reports`/`Notifications`/`Outdoor`/`Procurement`/`Quotations` are still `.gitkeep`), and
      deserves its own point rather than riding in on this one's size.
      ⚠️ **This point uses a direct interface call, not the domain-event-with-listener shape 2.6's own
      "not covered" note speculated on.** `CLAUDE.md` names interfaces *or* domain events as the two
      legitimate crossings, and every existing cross-module side effect in this codebase already
      picked the first — `AuditRecorderInterface` is called directly, synchronously, inside the same
      transaction as the write it records, not dispatched as an event with a listener. `DB-11` needs
      this recompute in the *same* transaction as the deal write it follows, and a direct call makes
      that trivially visible in `SaveDeal`/`ChangeDealStatus` rather than resting on a listener being
      registered synchronously. 2.6's note also specifically objected to *"a direct write into
      `customers` from here"* — this point does not do that: `Deals` never touches the `customers`
      table or `Customer` model, only `CustomerStatusWriterInterface`, a contract **Customers** exposes
      for exactly this.
      **`Customers` is split into `CustomersContract`/`CustomersDriver`**, on Identity/Audit/Storage's
      exact precedent (`deptrac.modules.yaml`) — the crossing that file's own header predicted:
      *"when a legitimate shared interface appears it is added here as a named exception"*. Before this
      point nothing outside Customers depended on it, so the flat layer cost nothing; `Deals` is now
      the first, granted `CustomersContract` only — nothing reaches `CustomersDriver`, and nothing
      should. `CustomerStatusWriterInterface` is write-only and one method: Deals never needs to read
      a stored status back, since §4.5 derives it fresh every time.
      **"Won or beyond, now or historically" is read off `DealStatusTransition`'s graph, not restated**:
      a deal's *current* status already proves it, because the graph has no edge leaving
      `won`/`purchasing`/`delivery`/`delivery_complete` back toward an earlier state.
      ⚠️ **One interpretation recorded rather than documented**: §4.5 rules 2 and 3 read as if a
      customer has one deal. With several concurrent ones (Business Invariants), this reads *any fresh
      active deal keeps the customer Prospect* — owed a `D-xx` if the owner disagrees.
      ⚠️ **Two gaps, both named rather than approximated.** Rule 3's *"or a quotation went Expired with
      no reply"* clause needs Module 7 (`app/Modules/Quotations` is still `.gitkeep`) — absent, not
      guessed at. `SystemLimit::StaleDealDays` (`limits.stale_deal_days`) is one of the enum's own
      documented "deliberately unvalued" limits — `null` until an administrator sets it, and `null`
      here means rule 3's clause never fires (every active deal reads as fresh), the same reading
      already established for `OD-08`'s similarity threshold. `SettingReader` gained
      `nullableInteger()` for this — `integer()`'s config-file floor would have been exactly the
      invented default `SystemLimit`'s docblock warns against, on the technicality of living in PHP
      instead of a database row.
      **No new audit entry for the derived write.** `AUD-01` is satisfied one layer out, by whichever
      of `DEAL_CREATED`/`DEAL_STATUS_CHANGED` triggered the recompute — a derived projection of an
      already-audited fact is not a second decision to record, the same disposition `EloquentDealDirectory`
      itself already carries.
      **`EloquentCustomerStatusWriter` *is* visible to `AuditEnforcementTest`'s scanner** — measured,
      not assumed, and initially wrong the first time: an early draft's own docblock explained the
      scanner's four signals by name, including the literal string `Eloquent\Model`, which the
      scanner reads from raw file text and does not distinguish from code. The comment describing why
      the class *should* be invisible made it visible. Rewritten without the literal signal string;
      re-run confirmed it is, in fact, invisible to scan() like its siblings — not audited, and not
      meant to be, for the same one-layer-out reason above.
      **6 new tests (35 assertions) · 1913 backend (11636 assertions) · pint 456 files · PHPStan
      level 10 clean · deptrac violations 0 / uncovered 0 on both configs.**
      **One deliberate break.** The fresh-deal comparison flipped from `>=` to `<` → exactly the one
      test exercising a configured threshold against a stale deal failed; the unset-threshold test and
      every rule-1/rule-4/rule-5 test kept passing, which is the coverage this rule's five-way branch
      needs. Restored, confirmed with `shasum -a 256 -c`.
      **Problems found:** the audit-scanner false positive above; `EloquentDealDirectory::activityForCustomer()`
      initially failed PHPStan level 10 (`array` vs `list`) — `Collection::map()->all()` cannot be
      proven a list by static analysis alone, fixed with `array_values()`. The Form Request's rejection
      reason field is `reason`, not `lost_reason` (the column name) — caught by two failing tests in
      this point, not assumed from the column.
      **Not covered:** the nightly correction half of `J-02` — a customer whose only active deal simply
      goes stale with no new event triggers nothing yet. `/documents` is still open (2.1's note). The
      quotation-expiry clause and the multi-deal interpretation above are both recorded gaps, not
      silent ones.

#### Step 4 — `POST /deals/{id}/documents` *(§17, D-38, D-71; the module's last open endpoint)*

- [x] **4.1** §17's upload flow, with `AttachmentParent::Deal` as the parent — the first write
      `StorageServiceInterface::store()` has ever had a database row put behind it, and the first
      real answer to `D-38` for any of the four parents `AttachmentPermissionInterface` names.
      **Permission is `SaveDeal`'s pattern, not a new mechanism.** §3.4 seeds no separate "attach
      document" row; the route carries `permission:deal.edit` and the use case resolves the same
      `DealRowScope` every other deal write does, letting `DealDirectoryInterface->find()` return
      null mean "absent or out of reach" — `DealNotFound`'s own 404, not a new refusal shape.
      **`Customers` is not touched; `Storage` is the crossing.** `deptrac.modules.yaml` grants `Deals`
      `StorageContract` — `StorageServiceInterface`, `UploadValidatorInterface`, and the new
      `FileWriterInterface`, Storage's write-only sibling to `FileRepositoryInterface` on
      `CustomerStatusWriterInterface`'s precedent (Point 3.1): a contract split by verb, not bolted
      onto a reader framed from the start as "reads the `files` row".
      **`files.id` is read off the stored path, not generated twice.** The migration's own comment
      says `{uuid}` in the §17 path *is* `files.id`, "so no second identifier column exists to
      drift" — `StoragePath::fileId()` is new, and it is the only way to get that uuid back out of
      `store()`, which mints it internally and never hands it back on its own.
      **`DealAttachmentPermission` answers D-38 for `Deal` and still denies the other three.**
      `SupplierQuotation`, `PurchaseOrder` and `Report` are still `.gitkeep`; the class checks
      `$link->parent` first and falls through to `false` for anything that is not a deal — the same
      fail-closed shape `DenyAllAttachmentPermission` had, now narrowed by one case instead of zero.
      Lives in `Application`, not `Infrastructure`: `deptrac.layers.yaml` refuses Infrastructure
      depending on any Application layer, and answering `mayView` means calling `AuthorizeAction`
      outside a route's own middleware — caught by deptrac on the first run, not anticipated.
      **The virus scan runs after the transaction commits, not inside it.** `DB-11` covers the `files`
      row, the `deal_files` pivot and the audit entry as one fact; the scan is not that fact, and a
      `ScannerUnavailable` inside the transaction would roll the whole upload back on a scanner
      outage. Caught and left `pending` instead — the upload already succeeded, and `pending` is
      already "not yet servable", the same state a scan that simply has not run yet would leave.
      ⚠️ **A decision, not a further deferral, closes `AuditEnforcementTest`'s standing debt.**
      `DatabaseFileRepository`'s entry has named this point as owner since Point 5.5: "record
      `FILE_SCANNED` or say here why a scan result is not an auditable change." Decided: not
      audited, because a scan result is the system's own classification of bytes
      `DEAL_DOCUMENT_ATTACHED` already names, not an actor's decision, and `files.scan_status` is not
      user-editable — nothing an audit row would catch that the column does not already show.
      **15 new tests (87 assertions) · 1940 backend (11762 assertions) · 565 frontend (34 files) ·
      pint 466 files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on both configs.**
      **One deliberate break.** The reach check flipped from `=== null` to `!== null` → 10 of 12
      `DealDocumentUploadTest` tests failed; only the two that never reach the line (unauthenticated,
      missing-file validation) kept passing. Restored, confirmed with `shasum -a 256 -c`.
      **Problems found:** two, both caught by the gates rather than assumed away. (1)
      `AttachDealDocument` called `filesize()` directly — `StorageServiceTest`'s filesystem-boundary
      scan failed on it, fixed by adding `StorageServiceInterface::sourceSizeBytes()` so the one
      remaining raw call lives in `LocalStorageService`, where the scan already permits it. (2)
      `DealAttachmentPermission` first lived in `Infrastructure` — `deptrac.layers.yaml` refused it
      for depending on `AuthorizeAction` (Application); moved to `Application\Access`.
      **Not covered:** nothing retries a scan left `pending` by a scanner outage — no such trigger
      exists yet. Image compression (§17's own row) is not implemented; no compression contract
      exists anywhere in `Storage`, and building one was judged out of scope for wiring this
      endpoint's first consumer. The quotation-expiry clause and multi-deal interpretation recorded
      under Point 3.1 remain open for the same reasons given there.
- [x] **4.2** `J-02`'s nightly half (§4.5, `D-49`) — Point 3.1's own deferral, closed. A customer
      whose only active deal simply goes stale, with neither `POST /deals` nor `PATCH
      /deals/{id}/status` ever firing again, now gets recomputed anyway.
      **Reuses `RecomputeCustomerStatus` rather than re-deriving §4.5.** The new class,
      `RecomputeStaleCustomerStatuses`, only decides *which* customers to ask and *when*; the five
      rules stay in exactly one place, `CustomerStatusDerivation`, called once per candidate the same
      way the event-triggered half already does.
      **The candidate query, `DealDirectoryInterface::customerIdsWithActiveDeals()`, excludes only
      what §4.5 proves is unaffected by the clock.** `status != 'lost'` is the whole filter — a
      customer with only `Lost` deals is `Deal Not Completed` regardless of elapsed time (rule 4
      fires on `active === []`, not on a duration), and a customer with no deals at all is
      `Prospect` for the same reason (rule 5). A `Won`-or-beyond customer is *included* rather than
      filtered out separately, and simply reconfirms the same answer — narrowing further would mean
      restating rule 1's reading here instead of leaving it where `CustomerStatusDerivation` alone
      decides it.
      **Queued onto `maintenance`, not scheduler-run like `J-15`.** §15's default is a queued job;
      `J-15` is its one documented exception, for a failure mode (`audit_log` silently running out of
      months while nothing drains a queue with no Horizon to watch it) this job does not share — a
      missed night here is repaired by tomorrow's run or the next deal-write event, whichever comes
      first. `RecomputeStaleCustomerStatusesJob` dispatches onto `QueueName::Maintenance`, where
      `worker-maintenance` already drains it with its own `--tries=3`.
      **No catch-up entry (`D-55`, `ST-05`), on `J-15`'s own precedent.** `routes/console.php`'s
      comment on `J-15` records why *it* needs none: idempotent and always evaluated against *now*,
      so a run missed for a week is repaired by the next run's fresh read rather than by replaying
      the nights that did not happen. `CustomerStatusDerivation::derive()` is exactly that kind of
      function — a pure read of current deals against the current clock — so the same reasoning
      carries over exactly, not by analogy.
      **9 new tests (22 assertions) · 1981 backend (11958 assertions) · 582 frontend (34 files) ·
      pint 471 files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on both configs.**
      Covers the candidate query directly (won/lost/mixed/no-deal fixtures), the sweep's effect
      through `run()`, the job's `handle()` resolved through the container, the schedule's frequency
      (`0 0 * * *`, `Schedule::job()`'s `CallbackEvent`, on `EnsureAuditPartitionsTest`'s own
      schedule-testing pattern), and — separately — that firing the scheduled event actually
      dispatches onto `maintenance` (`Queue::fake()` plus `$event->run($this->app)`, since a
      `CallbackEvent`'s closure is otherwise opaque to a test).
      **One deliberate break.** The candidate query's filter flipped from `!= 'lost'` to `=
      'lost'` — inverted exactly which customers the sweep considers → 4 of 9 tests failed (the
      candidate-query test, the won-reconfirms test, the stale-becomes-no_response test, the job
      test, the idempotency test); the unconfigured-threshold test passed coincidentally, since an
      excluded customer that was already `Prospect` stays `Prospect` either way. Restored, confirmed
      with `shasum -a 256 -c`.
      **Problems found:** one. `customerIdsWithActiveDeals()`'s return type (`Deal::query()->pluck('customer_id')->all()`)
      failed PHPStan level 10 the same way `activityForCustomer()` did in Point 3.1 — `pluck()`'s
      element type is not provably `string` — fixed with an explicit `->map()` cast, the same
      `@phpstan-ignore-line cast.string` precedent `DatabaseFileRepository` already uses for a
      property read PHPStan cannot narrow either.
      **Not covered:** the quotation-expiry clause of rule 3 and the multi-deal interpretation remain
      open, unchanged from Point 3.1 — this point processes existing deals against the existing
      derivation, and neither gap is in that function's reach. No manual trigger command exists for
      an operator who wants to run the sweep on demand outside its nightly schedule; the job is
      dispatched only by `Schedule::job()`. Module 5's own manual test list — required before Module
      6 work is allowed to start — has not been published yet; this was the last point blocking it.

#### Step 5 — the deal timeline, read back *(point list approved 2026-09-08)*

> **Why this step exists.** §4.4 ends with "Every status change is written to the deal timeline: old
> status · new status · who · when", and the acceptance criterion below names the same four fields.
> Point 1.1 deliberately did **not** build `deal_status_history`, on the reading that `audit_log`
> already stores `event`/`entity_type`/`entity_id`/`old_values`/`new_values`/`user_id`/`created_at`
> — §4.4's four fields verbatim — and that a second table recording the same fact is the defect
> `DB-11` and `AUD-02` exist to prevent. That reading is kept. What it left owing is the **read**:
> §3.4 seeds `deal.view_timeline` for all seven roles, and today the string appears nowhere in the
> codebase but `PermissionMatrix` itself and one docblock. A seeded permission no route ever checks
> is not a feature.

- [x] **5.1** An audit **reader**. `Modules/Audit` was write-only — `Presentation/` a bare
      `.gitkeep`, the contracts `AuditRecorderInterface` and `AuditEntryWriterInterface`.
      `AuditEntryReaderInterface` joins them, with `DatabaseAuditEntryReader` beside
      `DatabaseAuditEntries` and two read models, `AuditRecord` and `AuditRecordPage`.
      **`AUD-03` blocks `UPDATE`, `DELETE` and `TRUNCATE` — never `SELECT`**, and the interface says
      so rather than leaving the next reader hunting for a trap that is not there. Append-only is not
      write-only, and reading a permanent record is the reason it is permanent.
      **A separate class from `DatabaseAuditEntries`, deliberately.** That class's docblock says
      "insert and nothing else", and it is the sentence telling a reader why no `update` method is
      missing by accident. Adding a `SELECT` would make it false; two classes over one table is the
      cheaper price.
      **`AuditRecord` is not `AuditEntry`.** The write-side object demands an `AuditContext`,
      normalises the timestamp and refuses a float on its way to a permanent row (`DB-07`, `D-30`);
      re-running those on a row already written is re-litigating a decision the database has already
      recorded. **Four fields are on the row and deliberately not on the read model** —
      `ip_address`, `user_agent`, `request_id`, `correlation_id`: forensic fields for `AUD-05`'s
      structured log, not fields a timeline shows a salesperson, and `SEC-10`'s reason for storing
      the impersonator does not extend to publishing an IP to whoever holds `deal.view_timeline`.
      **No authorization here, on purpose.** The port takes an entity type and an id; `SEC-08`'s row
      scoping belongs to the module that owns the rows, because only it knows what reaching one
      means. Point 5.2 is where a caller who may not see the deal is refused.
      ⚠️ **`deptrac.modules.yaml` needed nothing.** The point list said to add `Deals → AuditContract`;
      it has been there since Point 2.3, the module's first write. Checked before editing rather than
      after — the plan was wrong and the file was right.
      **11 tests. RED first: the binding could not resolve, so all 10 then-existing tests failed.**
      ⚠️ **A probe found a real hole and the point closed it.** Reversing `orderByDesc('created_at')`
      reddened **nothing**: `id` is a UUIDv7 (`D-61`) and already time-ordered, so in a test whose
      rows are written microseconds apart the tie-break silently carried the whole promise, and
      "newest first" was an untested claim. An eleventh test now builds two rows whose keys
      **disagree** — the older row given the newer id — so only `created_at` can produce the
      documented order. The same probe now reddens 2 tests. Back-dating with an `UPDATE` was tried
      first and is impossible by design: `AUD-03`'s trigger answered SQLSTATE `AUD03`, which is the
      immutability rule proving itself inside a test that was not looking for it.
      **Two more probes, both reddened and restored** (`shasum -a 256 -c` each time): dropping
      `where('entity_type', …)` from the page query reddened the same-id-different-type case (1 red),
      and removing the UTC normalisation reddened the four-fields case (1 red).
      **Problems found — one real defect of mine, caught by running it.** `new DateTimeImmutable($s,
      new DateTimeZone('UTC'))` **ignores the zone argument** when `$s` carries an offset, and
      Postgres hands back `…+00`: the object came back named `+00:00`, the same instant wearing a
      different name, failing every assertion on the zone. Fixed with `setTimezone` after
      construction. One wrong expectation of mine too, in the test rather than the code — a
      `DEAL_CREATED` row's payload is `['status' => 'lead']`, not null.
      **Waste audit:** no new dependency; no new deptrac grant (the one named was already there);
      `AuditRecordPage` transcribes `DealPage`'s arithmetic rather than sharing a base class, on
      `DealPage`'s own precedent against `CustomerPage` — a shared pagination parent would be a
      dependency between modules with no business knowing each other. `oldValue()`/`newValue()` exist
      because three call sites would otherwise repeat the same null-and-type dance and the third
      would do it differently.
      **Not covered:** no route, no controller, no permission check, no row scope — all Point 5.2.
      Nothing reads this port yet; `deal.view_timeline` is still unreachable until 5.2 lands. The
      reader is unfiltered by event, so 5.2 decides whether a timeline shows all seven `DEAL_*`
      events or only `DEAL_STATUS_CHANGED`.
- [x] **5.2** `GET /deals/{deal}/timeline`, appended to the `prefix('deals')` group,
      `->middleware('permission:deal.view_timeline')` — the first route in the codebase to check that
      permission, seeded to all seven roles since Module 1 and until now present only in
      `PermissionMatrix` and one docblock.
      **The scope is the one the middleware resolved for *this* route's permission**, not
      `deal.view`'s. §3.4 gives them separate rows; they hold the same seven grants today, and a
      matrix where they diverge finds this code already correct rather than quietly reusing the wrong
      column. Unlike Point 2.6's `mark_delivery_complete`, no second `AuthorizeAction` lookup is
      needed: which permission applies does not depend on the request body, so route middleware can
      see it.
      **The row is settled before the history is read, never after.** `ListDealTimeline` finds the
      deal under the caller's scope first and throws `DealNotFound` — `OpenAPI §5.1`'s 404 for "does
      not exist **or** is not visible; do not reveal which case applies" — before the audit port is
      ever asked. Reading first and filtering after would be the same defect in a different order:
      the `total` alone tells a caller how busy a deal they may not see has been.
      **`DealTimelineCriteria` declares `page` and `per_page` and nothing else**, and each absence is
      a decision. **No `sort`** — newest-first is the port's promise, and a timeline ordered oldest
      first puts what just happened on the last page nobody opens. **No `q`** — `D-48` routes free
      text through `SearchService` and there is no audit index, so a search box would be a control
      with nothing behind it. **No `filter[event]`**, though six other `DEAL_*` events share the
      table: §4.4 describes what must be *in* the timeline, not what to filter out of it, and no
      source describes an event filter — **owed a `D-xx` before it exists**. §6.2's undeclared
      parameter is a **400**, not a silent ignore: a caller who sent a sort and got an unsorted list
      would believe it had been applied.
      **`old_status`/`new_status` are lifted out of the stored payloads**, and an event that changed
      something else — a `DEAL_UPDATED` on the title — keeps its entry with both fields null rather
      than being hidden. A history with holes in it is not a history.
      ⚠️ **`actor_id` and not an actor name.** The name belongs to Identity and `CLAUDE.md` forbids
      this module reading another module's rows — Module 6 Point 6.2's answer for a currency it could
      not resolve, taken again. On the debt register.
      **19 tests · pint 546 files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on
      both configs.**
      ⚠️ **A test of mine asserted the wrong thing and the run caught it.** The first version
      data-provided a role that should get a 403 and named the Super Admin — who holds
      `unconditional_access` (§3.11) and is admitted by it, answering 200. §3.4's `view timeline` row
      fills all seven business columns, so **there is no role to refuse**. Replaced with a stronger
      test that *withdraws* the grant: the Manager keeps `deal.view`, loses `deal.view_timeline`, and
      the route must refuse — which a route reading the wrong column would fail, since `GET
      /deals/{id}` still answers 200 in the same test.
      **Three probes, all reddened and restored** (`cp` from a pre-probe copy, re-verified green):
      pointing the route at `permission:deal.view` reddened exactly the withdraw test (1 red);
      removing the reach check reddened the two 404 cases and the "history is not read" case (3 red);
      ignoring undeclared parameters instead of refusing them reddened the sort, search and event
      filter cases (3 red).
      **Problems found:** besides the wrong test above, PHPStan level 10 rejected `int` against the
      port's `positive-int` — annotated through `DealTimelineCriteria` rather than relaxed on the
      interface, because the parser genuinely guarantees it (`^[1-9][0-9]*$` refuses zero and below).
      Two pint fixes, both cosmetic.
      **Waste audit:** one new lang key per language (`list_query.unknown_parameter`), both rendered
      by a test; `DealTimelineCriteria` transcribes `DealListCriteria`'s bounds rather than sharing
      them, on this module's own precedent — two resources agreeing on a page size today is not a
      reason to make one change when the other's contract does. No new deptrac grant needed.
      **Not covered:** no screen — Step 6 draws this. The actor is an id on the wire. No event
      filter, and no `DEAL_*` event is excluded, so a deal with many document uploads shows them all.
      §3.4's `Team`/`Out`/`Asgn` gaps are unchanged and now visible on this route too: a Team Leader
      holding `view_timeline` as `Team` reaches no deal at all, which the tests assert rather than
      work around.

#### Step 6 — the frontend *(point list approved 2026-09-08)*

> **Why this step exists.** Five of this module's acceptance criteria are unticked and every one of
> them names something only a person at a screen can see. Module 5 shipped API-only — the second
> module to do so — and `resources/js/pages/deals` does not exist. Module 6 reached the same wall and
> answered it with its own Step 6; this is that step, on the same pattern and citing the same
> sources.
>
> ⚠️ **Design System §5.2 assigns Deals the Kanban view, and §9 criterion 4 names "a deal Kanban
> board" outright. This step builds a table instead**, on the reading that no open acceptance
> criterion names a board while §5.2's own Table/List row asks for exactly the server pagination
> `DealListCriteria` already implements. The pipeline board is **owed a point list of its own** and is
> on the debt register below — deferred, not dropped, and **awaiting a `D-xx`**.
>
> ⚠️ **Three ceilings this step does not remove and must not paper over.** (1) `Team`, `Out` and
> `Asgn` resolve to **zero rows** (`DealRowScope`, Point 2.1), so a Team Leader, Outdoor Supervisor
> and Procurement each hold `deal.view` and see an empty list — an authenticated 200, not a refusal —
> and "Pending Approval **for the Team Leader**" is demonstrable only as the Manager today. (2) There
> is no `GET /deals/{id}/documents`; upload and generic download exist, a per-parent list does not,
> which is the same ceiling Module 6 Point 6.5 recorded and accepted. (3) Flow 3's "inactive until
> approved" has no structural backing — §4.3 has no visibility column, so a `pending` deal is an
> ordinary row and the screen may badge it but may not claim the server hides it.

- [x] **6.1** `services/deals.ts` and its spec — the typed client for the ten routes, modelled on
      `services/customers.ts` rather than Module 6's client, because Customers is the one that already
      carries the `q`-plus-closed-filters shape this list needs.
      **The query surface is `DealListCriteria`'s exactly:** filters `status`, `service_type`,
      `source`, `approval_status`; sorts `code`, `created_at`, `last_activity_at`; default
      `-last_activity_at`, the server's own — a caller opening the list is asking "what needs
      attention". An unset filter is **omitted**, never sent empty, and null and `''` are treated
      alike: `filter[status]=` asks a different question and `OpenAPI §6.2` answers the unknown shape
      with a 400.
      **Unlike Module 6, `q` is real.** `SearchIndex::Deals` indexes `title`, §4.3's one free-text
      field, so this list declares a search and a search box is a control with a server behind it —
      the one place the two modules' list clients genuinely differ.
      **The draft types encode what the server prohibits, rather than documenting it.** `code`,
      `status`, `approval_status`, `rejection_reason` and `last_activity_at` are `prohibited`
      (a 422, not a silent drop) and so are **absent from `DealDraft`**, not optional on it;
      `customer_id` and `owner_id` live on a separate `DealCreateDraft` because `PATCH` prohibits
      both — §3.4 makes `assign_owner` its own permission, and a deal's customer is the one thing
      about it that cannot change.
      **`changeDealStatus` omits `reason` unless the target is `lost`.** `ChangeDealStatusRequest`
      makes it `required` there and `prohibited` everywhere else, so a null would 422 on every other
      status: prohibited means absent, not empty.
      **The timeline's contract is smaller and stricter**, and the client is built not to be tempted:
      `page` and `per_page` only, never a sort, a search or an event filter, each of which
      `DealTimelineCriteria` refuses with a 400 rather than ignoring.
      **15 tests. RED first: the module did not exist, so the suite failed to import.**
      ⚠️ **The `FormData` key is asserted from the start.** Module 6 Point 6.1 found by probe that
      renaming the upload field from `document` reddened **nothing**, while 422ing every upload *and*
      pointing `ApiExceptionRenderer`'s hard-coded `'field' => 'document'` at a control that does not
      exist. That assertion exists here before the probe rather than after it.
      **Three probes, all reddened and restored:** renaming the upload field `document` → `file`
      (1 red — the assertion above), sending `reason` on every status rather than only `lost` (1 red),
      and sending empty filters instead of omitting them (1 red).
      **Problems found:** `vue-tsc` rejected three `page.items[0].x` reads under `noUncheckedIndexedAccess`
      — the runtime tests passed with the unsafe shape because the fixture always has the row. Fixed
      with `?.`, the same class of defect Module 6 Point 6.2 recorded against its own fixtures.
      **Waste audit:** no new dependency; five exported constant lists (`DEAL_STATUSES`,
      `DEAL_SOURCES`, `DEAL_SERVICE_TYPES`, `DEAL_APPROVAL_STATUSES`, `DEAL_SORTS`) are vocabularies
      the next three points render as options — each transcribed from the server's own closed set, and
      **not** a transition graph: `DealStatusTransition` stays on the server (`D-67`, Design System
      §7.1's "the server decides … allowed transition").
      **Not covered:** no screen, no route, no nav entry — 6.2 onward. Nothing renders these types
      yet. The client performs no authorization and no validation beyond shape; `SEC-09` keeps both at
      the API.
- [x] **6.2** `DealsView.vue` — the list, its route behind `deal.view`, and its nav entry in
      `nav.group.sales`. Four filters, the search, two sortable headers, server pagination and the
      four states from `components/states/`.
      ⚠️ **A table, where §5.2 assigns Deals the Kanban view and §9's criterion 4 names "a deal
      Kanban board" outright.** Deliberate and temporary: none of the five open criteria names a
      board, while §5.2's own Table/List row asks for exactly the server pagination
      `DealListCriteria` already implements. The board is owed its own point list, is on the debt
      register, and is **awaiting a `D-xx`** — deferred, not dropped.
      **Everything is asked of the server, and the spec reads the URL to prove it.** Every assertion
      about a filter, a sort or the search reads the query string rather than the rendered rows,
      because a client-side filter narrows the 25 rows in hand and silently claims to have narrowed
      all of them (§5.2, §6.5).
      **Unlike Module 6's list, the search box is real.** `SearchIndex::Deals` indexes `title`, so
      `q` is a control with a server behind it — the one place the two modules' lists genuinely
      differ, Module 6 having had to omit a search that would 400.
      **The nav and route follow §3.4, not §8.** §8 lists Requests/Deals for five roles and omits the
      **CEO** and **Outdoor Sales**, while §3.4 grants the CEO `deal.view` as `All` and Outdoor Sales
      as `Own`. The owner's standing ruling of 2026-08-31 applies unchanged — keying the menu on §8
      would leave a screen a person may open with no way to reach it. Followed as precedent, still
      **awaiting a `D-xx`**. The CEO's reach is asserted at the screen.
      ⚠️ **§5.1 permits a badge on Requests and none is drawn.** `navigation.ts` says nothing counts
      anything yet; a counter here would be a number this application cannot produce. Asserted as
      absent rather than left to drift.
      ⚠️ **The empty state says "no deals are visible to you", never "there are no deals".** §3.4 is
      scoped and `Team`, `Out` and `Asgn` resolve to zero rows (Point 2.1), so a Team Leader, an
      Outdoor Supervisor and Procurement are each answered with an authenticated **200 and an empty
      page** rather than a refusal. The screen cannot tell that case from an empty table, and the
      wording is true in both. A filtered empty page says something different again.
      ⚠️ **Two ceilings, both measured, neither invented here.** The customer names come from one
      `listCustomers({ perPage: 100 })` — `MAX_PER_PAGE` — so a customer past the hundredth shows as
      an identifier, the same ceiling Module 6 Point 6.2 recorded for suppliers. And **the owner is
      not resolved at all**: Identity publishes no list this module may match an id against, so the
      column shows the identifier. Both asserted, so neither can be quietly forgotten.
      *Closes* **"Deal codes follow `DL-2026-0001`"** — the code column renders what the server
      allocated, digit for digit, the SPA never building one (`SaveDealRequest` prohibits the field)
      — and **"customer with an active deal + new request → two independent deals, separate
      statuses"**: two rows for one customer, `Lead` and `Negotiations` side by side, neither grouped
      nor merged.
      **21 tests · 673 frontend (39 files) · vue-tsc clean · pint 546 files · PHPStan level 10 clean ·
      deptrac violations 0 / uncovered 0 on both configs.**
      **`NoHardCodedTextTest`'s pinned list gained `DealsView.vue`**, the scan having been run against
      it first and passed — the test's own stated terms. `LogicalPropertiesTest` needed no pin: it
      data-provides over the tree, and the component uses `text-start`/`text-end` and
      `border-block-start` so RTL mirrors (§6.5).
      **Three probes, all reddened and restored:** drawing a 403 as an empty list reddened the
      refusal *and* the fault case (2 red — the two states are told apart by one line); keeping the
      page number when a filter changes reddened the first-page case (1 red); dropping `q` from the
      query reddened the search case (1 red).
      **Problems found:** none in this point. The component was written after the client, and both
      the scan and the type-check passed on the first run.
      **Waste audit:** 47 new lang keys per language, every one rendered (the status, source,
      service-type and approval vocabularies are each a full `DEAL_*` set the filters and the table
      both read); no new dependency; no shared component added — the table and pager are hand-rolled
      to the same shape as `SuppliersView` and `SupplierQuotationsView`, which is the house pattern
      rather than an omission.
      **Not covered:** no create, edit, approve, reject, status or assign control — 6.3 through 6.7
      build them, so this screen is read-only today and draws no write affordance at all. No row
      links anywhere: the detail view is 6.6. Module 6's `deal_id` filter is still a raw identifier
      box; giving it a picker is not this point's job and is not yet done.
- [x] **6.3** `DealFormModal.vue` — create and edit in one component, on
      `SupplierQuotationFormModal`'s shape, plus the two write controls it gives the list.
      **Five fields, and every absence is the server's rule.** `code`, `status`, `approval_status`,
      `rejection_reason` and `last_activity_at` are `prohibited` in `SaveDealRequest` — a 422, not a
      silent drop — and are absent from `DealDraft`, so this dialog could not send one by accident.
      **`customer_id` and `owner_id` are create-only and are not drawn at all on an edit**, rather
      than drawn disabled: both are `prohibited` on a `PATCH`, a disabled control invites the question
      "why", and the answer is a different screen. The customer is the one thing about a deal that
      cannot change; the owner moves through `PATCH /deals/{id}/assign`, which §3.4 gives its own row
      (`assign_owner`, two roles where `edit` reaches five).
      ⚠️ **No owner picker, on Module 3's precedent.** Filling one needs a user list `deal.create`
      does not carry, and `CustomerFormModal` recorded the same wall for `sales_owner_id` as a
      narrowing rather than a decision. A plain identifier box, labelled as one — the same ugly,
      stated ceiling Module 6 accepted for its `deal_id` filter.
      **`""` means "not given", which on the wire is `null`** and never an empty string: the server's
      vocabularies have no empty member, so an empty `source` would be a 422.
      **Two permissions, two computeds, never one "may write".** §3.4 gives `create` and `edit`
      different columns — the CEO holds neither, **Procurement holds `edit` and has no `create` cell
      at all** — so a single computed would draw a control that role cannot complete. Asserted with a
      Procurement profile.
      **A form-level refusal is a local key; a field refusal is the server's own sentence**, already
      localised, rendered verbatim through `ApiError.messageFor()`.
      **The unsaved-change warning is a panel in the dialog, never `confirm()`** — that dialog is
      neither translatable nor mirrored for RTL (§5.2, §6.1: Escape closes "without discarding
      silently", so Cancel and Escape take one path). Dirty is measured against **what the dialog
      opened with**, not against blank, so an edit that changes nothing closes straight away.
      **The list refetches after a save rather than patching the row:** a write refreshes
      `last_activity_at`, which is the default sort, so the saved row may not belong where it was.
      **14 dialog tests + 5 new list tests · 692 frontend (40 files) · vue-tsc clean · pint 546 files
      · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on both configs.**
      ⚠️ **A gate caught a defect of mine that no eye would have.** The scrim was written as
      `var(--color-scrim)` — **a token that does not exist**. `LogicalPropertiesTest`'s colour-token
      check failed on it (`§3.2` fixes the set a component may consume), and the fix is the same
      `color-mix(in srgb, var(--color-text) 45%, transparent)` `SupplierQuotationFormModal` already
      uses. An invented token renders as nothing and would have shipped an invisible scrim.
      **Three probes, all reddened and restored:** resending `customer_id` on an edit (1 red),
      discarding unsaved changes silently (3 red — Cancel, Escape and the decline path are one rule
      seen three times), and sending `''` instead of `null` for an unset field (1 red).
      **`NoHardCodedTextTest`'s pinned list gained `DealFormModal.vue`**, the scan having been run
      against it first and passed.
      **Waste audit:** 15 new lang keys per language, all rendered; no new dependency; the dialog
      reuses `action.cancel`, `action.save` and `action.edit` rather than adding module-local twins.
      **Not covered:** no approve, reject, status, assign or document control — 6.4 through 6.7. The
      dialog cannot set an owner on an existing deal, by design. Nothing here validates beyond the one
      required-customer courtesy; `D-67` keeps every rule that matters on the server.
- [x] **6.4** The approval controls — `DealApprovalControls.vue`: §6.4's badges, and Approve /
      Reject behind `deal.approve`, drawn in the list's approval column.
      **One permission for both directions, because §3.4 seeds one.** There is no `deal.reject` row —
      `deal.approve` carries both routes, on `customer.archive`'s precedent for archive/restore — so
      one computed draws both buttons and a role that may approve may reject.
      **The badge is a state, not a colour.** §6.4 makes pending a **warning** ("amber icon +
      label") and rejected a **danger** with "mandatory reason/action clear", so each badge carries
      an icon, a word **and** a token-defined colour; the rejection reason is rendered **beside** the
      badge rather than behind it, §4.3 making it mandatory precisely so the employee can read it.
      **Null is not pending.** Flow 1 leaves `approval_status` null for a deal a Manager or Team
      Leader entered — never submitted, which is a different fact from "waiting for a decision". No
      badge is drawn at all in that case; a "pending" chip would invent a queue nobody is in.
      **The reason is collected before submission, never after** (§6.6), and whitespace is refused
      here as well as at the server — `RejectDealRequest` is `required` + `regex:/\S/`, and the same
      check made where the person can still fix it saves a round trip that would only tell them what
      they already know.
      **A decision that cannot succeed is not offered:** the buttons are drawn only on a `pending`
      deal, because `ReviewDealApproval` answers a second decision — and a decision on a
      never-submitted deal — with `409 state_transition_invalid`. When a 409 does arrive because the
      row in hand is stale, it is surfaced inline as exactly that, with the honest instruction to
      reload.
      **A field refusal is the server's own sentence** (`ApiError.messageFor('reason')`); a 403 says
      it is a permission problem and not a bad reason.
      *Closes* **"Employee-entered request → 'Pending Approval' for the Team Leader"** and
      **"Rejected request → mandatory reason + badge for the employee"**.
      ⚠️ **Both are demonstrable as the Manager only.** §3.4 grants `deal.approve` to the Manager
      (`All`) and the Team Leader (`Team`), and `Team` resolves to **no rows at all** (Point 2.1) — so
      a Team Leader holds the permission, is drawn the buttons, and reaches no deal to press them on.
      The role the criterion names cannot demonstrate it today. That is recorded backend debt, not a
      defect of this component; the module's manual test list says so in as many words, and the spec's
      own docblock does too.
      **13 tests · 705 frontend (41 files) · vue-tsc clean · pint 546 files · PHPStan level 10 clean ·
      deptrac violations 0 / uncovered 0 on both configs.**
      **Three probes, all reddened and restored:** submitting a blank reason (2 red — the blank and
      whitespace cases are one rule seen twice); keying the controls on `deal.edit` instead of
      `deal.approve` (**9 red**, the whole decision surface); and reducing the badge to a colour and
      an icon with no word (2 red — the badge test and the list's own dictionary test).
      **Problems found — two, both mine, both caught by a gate rather than by eye.** `vue-tsc`
      rejected a `fetchMock.mock.calls[0] as [string, RequestInit]` cast the runtime tests passed
      with, the inferred tuple being empty; fixed by going through `unknown`. And the list's
      "renders a stored code through the dictionary" assertion had to move from `toBe` to `toContain`,
      the approval cell now holding a badge whose text carries the icon beside the word — the
      assertion was right and the cell changed under it.
      **Waste audit:** 9 new lang keys per language, all rendered; the three badge colours are §3.2
      tokens that already exist (`--color-warning`, `--color-danger`, `--color-success`) — checked in
      `tokens.css` **before** writing them, having invented `--color-scrim` one point earlier and been
      caught; no new dependency.
      **Not covered:** no status control (6.5), no detail view (6.6), no documents or assign (6.7).
      The controls live in the list's approval column, so a decision is made from the list rather than
      from a deal's own page — which does not exist yet. Nothing here shows *who* decided or when;
      that is the timeline, and it is 6.6's.
- [x] **6.5** The status control — `DealStatusControl.vue`: §4.4's twelve statuses, drawn in the
      list's status column behind `deal.change_status`.
      **The SPA does not own the transition graph.** `D-67`, and Design System §7.1 says it again for
      this exact control: "The server decides authorization and allowed transition."
      `DealStatusTransition` is built from §4.4's table and lives in `Deals\Domain`; transcribing it
      into TypeScript would put one rule in two places, and the copy is the one that rots — §4.4
      gains a status and the screen keeps offering the old graph. So the control offers **the
      vocabulary**, not the reachable set, and renders the server's `409 state_transition_invalid`
      when an edge is refused. That is a worse experience than a narrowed list and the honest one
      available; publishing `allowed_transitions` on `DealPayload` is the better answer and is on the
      debt register as a backend point.
      **`delivery_complete` is offered even though a second permission gates it.** `D-14`'s
      `mark_delivery_complete` is a genuinely different set of four roles — the Outdoor Supervisor
      holds one and not the other — and which permission applies depends on the request body, which
      is why `ChangeDealStatus` checks it inside the use case rather than at the route. Hiding the
      option from a role that might hold the second grant would be the SPA guessing at an
      authorization decision; the 403 and the 409 are drawn as the different answers they are.
      **`lost` is the one status carrying a reason**, and the field appears only for it: §4.4's table
      reads "Lost | Sales (mandatory reason)", and `ChangeDealStatusRequest` makes `reason` `required`
      there and `prohibited` everywhere else. Blank and whitespace are refused here as well as at the
      server, where the person can still fix them.
      ⚠️ **A probe found a rule written in two places, and the point deleted one.** The component
      first passed `needsReason ? reason : undefined`, and making that unconditional **reddened
      nothing** — `changeDealStatus` already applies the rule, so the component's copy was
      unprovable. Two places enforcing one rule with only one of them testable is worse than one
      place, so the component now passes the reason unconditionally and the service owns the wire
      shape alone. Breaking it there reddens **2** tests, one of them this component's.
      **12 tests · 716 frontend (42 files) · vue-tsc clean · pint 546 files · PHPStan level 10 clean ·
      deptrac violations 0 / uncovered 0 on both configs.**
      **Two further probes, both reddened and restored:** never asking for the loss reason (4 red),
      and narrowing the vocabulary in the component by filtering `delivery_complete` out (2 red — the
      whole-vocabulary case and the second-permission case, which is the pair that pins §7.1).
      **`NoHardCodedTextTest`'s pinned list gained `DealStatusControl.vue`**, the scan having been run
      against it first and passed.
      **Waste audit:** 10 new lang keys per language, all rendered; `DEAL_STATUSES` was already
      exported by Point 6.1 and is reused rather than re-listed — the vocabulary exists once in the
      SPA; no new dependency.
      **Not covered:** no detail view (6.6), no documents or assign (6.7). The control lives in the
      list's status cell, so a transition is made from the list rather than from a deal's own page.
      §4.4's "Who changes it" column is not enforced beyond `change_status`'s own scopes — the same
      restraint Point 2.6 exercised on the server, and the screen does not invent a finer rule than
      the matrix seeds. Nothing here shows the history of a transition; that is the timeline, and it
      is 6.6's.
- [x] **6.6** `DealDetailView.vue` — §5.2's Detail view on `CustomerDetailView`'s shape: "summary
      first, related data/timeline second, action controls only by permission", plus the route
      `/deals/:id` and the list's row link into it.
      **A 404 is one state and stays one state.** `OpenAPI §5.1` defines it as "does not exist **or**
      is not visible to the caller — do not reveal which case applies", which is why `DealNotFound`
      is a single exception covering both. The screen says the deal could not be opened and stops
      there; a page that said "you do not have access to this deal" would undo §5.1 in the one place
      a person reads it. A **403** is a different answer and gets a different screen: it is about
      `deal.view` and says nothing about any row. Asserted in both directions.
      **The timeline is a second permission and gets a second, contained refusal.** §3.4 gives `view
      timeline` its own row and Point 5.2's route carries `deal.view_timeline`, so the history is
      loaded separately: a caller who may read the deal and not its history sees the summary with a
      refusal **inside the timeline section**, not an error page over a deal that loaded perfectly
      well. The route itself carries `deal.view`, so that caller still reaches the page.
      **A history with holes in it is not a history.** An entry that changed no status —
      `DEAL_UPDATED` on a title — keeps its place and is drawn by its event; a creation is drawn as
      "opened as Lead" rather than as a change from nothing. §4.4 describes what must be *in* the
      timeline, not what to filter out of it.
      **`SEC-10`'s second identity is published.** When `impersonated_user_id` is set the entry says
      so — a history naming only the actor would read identically whether or not the change was made
      through somebody else's account, which is the whole reason the column exists.
      ⚠️ **The actor is an identifier.** `DealTimelinePayload` sends `actor_id` and Identity publishes
      no list this module may resolve a name against; `CLAUDE.md` forbids reading another module's
      tables. Module 6 Point 6.2's answer for a currency it could not resolve, taken again. On the
      debt register.
      *Closes* **"Status change → timeline entry with old status, new status, who, when"** — the last
      of the five criteria, and the one Point 1.1 deliberately left owing when it declined
      `deal_status_history` on the grounds that `audit_log` already stored those four fields. Read
      back through 5.1's port and 5.2's route, and now visible to a person.
      **14 tests · 730 frontend (43 files) · vue-tsc clean · pint 546 files · PHPStan level 10 clean ·
      deptrac violations 0 / uncovered 0 on both configs.**
      **The row link names a route, never a path** — `{ name: 'deal-detail' }` — so a renamed route
      fails at the router instead of silently producing a dead link, the same discipline
      `navigation.ts` is held to.
      **Three probes, all reddened and restored:** escalating a timeline refusal to a page error
      (1 red), dropping the old status from a change entry (1 red — the criterion's own test), and no
      longer telling a 404 apart from a fault (1 red).
      **Problems found:** none in this point; the component and all 14 tests were green on the first
      run, and both scans passed on arrival.
      **Waste audit:** 16 new lang keys per language, all rendered — the seven `DEAL_*` event names
      included, each of which a timeline can legitimately show; the summary fields are a list rather
      than markup per field, so a field cannot be added to §4.3 and drawn in one place only; the
      approval and status controls are the components 6.4 and 6.5 already built, reused rather than
      redrawn.
      **Not covered:** no documents panel and no assign control — both are 6.7, and building them here
      would be building past the approved plan. No Kanban link: the board is deferred with a `D-xx`
      owed. The timeline is unpaginated on screen — it asks for the first page and shows it, and a
      deal with more than 25 events has more history than the page reveals. Stated rather than
      hidden; the endpoint pages correctly and a control for it is owed.
- [x] **6.7** The documents panel and the assign control — `DealDocumentsPanel.vue`, on the detail
      view.
      **The form field is `document` and the test says so.** `ApiExceptionRenderer` hard-codes
      `'field' => 'document'` when mapping the server's refusal back onto a control, so a rename 422s
      every upload **and** points the error at a field that does not exist. Module 6 Point 6.1 found
      that by probe with nothing asserting the key; here both `deals.spec.ts` and this panel's own
      spec assert it.
      ⚠️ **The panel lists this session's uploads only, and says so on the screen** — not merely in a
      docblock. There is **no `GET /deals/{id}/documents`**: Point 4.1 built the upload, Module 0 a
      generic `GET /files/{id}/download`, and no per-parent list endpoint exists anywhere;
      `DealPayload` carries no documents array either. A reload empties the list while the files stay
      perfectly safe on the server. Module 6 Point 6.5 hit the identical wall and answered it the
      same way. **On the debt register.**
      **`scan_status` is shown as itself.** `SEC-15` gates the download on the scan and not on
      ownership, and a scan can be `pending` when the scanner was unavailable — so a file that is not
      yet clean is drawn as what it is rather than as a download that silently fails, and an infected
      one is refused **to the person who uploaded it**.
      **The upload carries `deal.edit`, because §3.4 seeds no attach row.** Unlike §3.6, which gives
      Supplier Quotations its own `upload_attachment` grant, §3.4 has no such cell — so the control
      carries the closest documented permission, exactly as `AttachDealDocument`'s own docblock
      explains choosing over inventing a grant nothing in §3 asks for.
      **Assign is its own permission and its own section.** §3.4 gives `assign_owner` a row reaching
      **two** roles where `edit` reaches five, so holding `edit` is not holding this — asserted with
      an Indoor Sales profile that has `edit` and no assign section. A blank owner is refused here
      (§3.4 has no unassign row and `AssignDealRequest` requires the field), and the server's own
      sentence is rendered when it refuses the value.
      ⚠️ **Half of `assign_owner`'s row is unreachable.** `Team` resolves to no rows (Point 2.1), so
      a Team Leader holding it reaches the use case for every deal in the company and finds none of
      them — which `routes/api.php` already records against the route itself.
      **11 tests · 741 frontend (44 files) · vue-tsc clean · pint 546 files · PHPStan level 10 clean ·
      deptrac violations 0 / uncovered 0 on both configs.**
      **Three probes, all reddened and restored:** keying the assign section on `deal.edit` instead of
      `deal.assign_owner` (1 red), drawing a pending scan as a working download (1 red), and
      submitting a blank owner (1 red).
      **Problems found:** none in this point; all 11 tests were green on the first run and both scans
      passed on arrival.
      **Waste audit:** 16 new lang keys per language, all rendered; the download goes through the
      existing shared `services/files.ts` rather than a module-local copy — `GET /files/{id}/download`
      is Storage-owned infrastructure, which is why Module 6 put the helper there; no new dependency.
      **Not covered:** no documents list on load, by necessity rather than choice. Nothing here shows *who* attached a file; the timeline's
      `DEAL_DOCUMENT_ATTACHED` entry does, and it is 6.6's.
- [x] **6.8** Close the module — the criteria resolved, the Arabic manual test list published, the
      ownership table's **State** column updated, and the debt register appended.
      ⚠️ **Four criteria are `[x]` and one is `[~]`.** The fifth was ticked here on 2026-09-09 and
      **downgraded on 2026-09-10** after a `/code-review` spec pass pointed out that its text carries
      two clauses and only one is built — see the criterion itself. The review was right, and the
      correction belongs in this entry rather than quietly in the box.
      **The criteria, each with the point that resolved it and the evidence:**
      `DL-2026-0001` (6.2 — the code column renders what the server allocated, digit for digit, the
      SPA never building one); two independent deals per customer (6.2 — two rows for one customer,
      `Lead` and `Negotiations` side by side, neither grouped nor merged); rejected request →
      mandatory reason + badge (6.4 — the reason collected before submission and rendered beside the
      badge, whitespace refused as the server refuses it); and status change → timeline entry with
      old status, new status, who, when (5.1 → 5.2 → 6.6 — the read port, the route, and the screen,
      closing what Point 1.1 left owing when it declined `deal_status_history`).
      **The fifth, at `[~]`:** "Pending Approval" for the Team Leader (6.4 — §6.4's amber badge with
      icon **and** word, and Approve/Reject behind `deal.approve`; the criterion's second clause,
      "inactive until approved", is not built and needs a `D-xx` granting §4.3 a visibility column).
      ⚠️ **Two of the five are demonstrable as the Manager only**, and the test list says so rather
      than the tick implying otherwise: §3.4 grants `deal.approve` to the Manager (`All`) and the Team
      Leader (`Team`), and `Team` resolves to no rows (Point 2.1) — so the role the criterion names
      holds the permission, is drawn the buttons, and reaches no deal to press them on.
      **The manual test list is above: 75 checks in Arabic**, grouped by screen in walk order, each
      naming its required role and written as action ⇒ expected result. It covers every acceptance
      criterion by name, both languages and both directions, the loading / empty / error /
      refused-by-permission states on every screen, the keyboard path, and — in its own section «ي» —
      **seven things that cannot be tested yet and why**, so a tester meeting an empty list for a
      Team Leader knows it is recorded debt rather than an escape.
      **The ownership table's State column is updated now and not before**, per the owner's rule of
      2026-08-31: only when a module is entirely finished. Module 5 reads **finished**; Module 4 and
      Module 6 are Yousef's own to mark.
      **Debt register — seven entries, each with its reason:**
      1. **The Kanban board.** §5.2 assigns Deals the Kanban view and §9's criterion 4 names "a deal
         Kanban board"; no open acceptance criterion named one, so Step 6 shipped §5.2's Table/List
         instead. Owed its own point list, **awaiting a `D-xx`**.
      2. **`allowed_transitions` on `DealPayload`.** The status control offers §4.4's whole vocabulary
         and lets the server refuse, because `D-67` and §7.1 keep the graph on the server. Publishing
         the reachable set would narrow the control honestly; a backend point.
      3. **`GET /deals/{id}/documents`.** None exists anywhere, so the documents panel lists this
         session's uploads only and says so on screen. The identical ceiling Module 6 Point 6.5
         recorded.
      4. **Actor and owner names.** `DealPayload` and `DealTimelinePayload` publish identifiers;
         Identity publishes no list this module may resolve one against, and `CLAUDE.md` forbids
         reading another module's tables. Module 6's answer for an unresolvable currency, taken again.
      5. **Timeline pagination on screen.** `GET /deals/{id}/timeline` pages correctly per
         `OpenAPI §6`; the detail view asks for the first page and shows it, so a deal with more than
         25 events has more history than the page reveals.
      6. **`Team`, `Out` and `Asgn` still resolve to zero rows** (Point 2.1) — unchanged by this step
         and now visible on three screens rather than only in an endpoint test.
      7. **`refusalKey()` and the scoped `<style>` block repeat** across the module's controls and
         five `.vue` files respectively. Both follow a convention four existing screens already use,
         so the fix is a repo-wide one — a shared error-key helper and a shared form utility class —
         and belongs to whoever changes that convention, not to this branch.
      8. **`arabic/CHECKLIST_AR.md` is ~8,700 lines behind `CHECKLIST.md`** and has not been updated
         since 2026-08-12. Every module since 3 is missing from it. Belongs to both developers.
      9. **§4.3's "inactive until approved"** (Flow 3) has no column behind it, so a `pending` deal is
         an ordinary row the screen badges rather than the server hides. ⚠️ **This is the one debt
         entry that holds an acceptance criterion open** — it is why that criterion stands at `[~]`
         and not `[x]`, and closing it needs a `D-xx` before a column can be added.
      **Two things this step clears for the other developer, and does not touch itself.** Module 6
      Point 6.2's `deal_id` filter is a raw identifier box because "there is no deals screen" — there
      is one now, and giving that filter a picker is **Module 6's** to take. And Module 6's own
      out-of-order warning reads "Module 5 still has open acceptance criteria owned by a second
      developer", which is **no longer true**: all five are closed above. Both live in Yousef's block
      of this file, and the workflow rule is that a shared file is appended to inside your own block
      and never edited inside somebody else's — so the fact is recorded here and the edits are his to
      make. The same applies to Module 7's identical note.
      **Full gates at close:** 754 frontend (45 files) · vue-tsc clean · 2404 backend · pint 546 files
      · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on both configs.
      **Not covered:** everything in the seven debt entries above, and nothing else that this module's
      own acceptance criteria name.
#### قائمة الاختبار اليدوي — الوحدة 5 كاملة *(النقطة 6.8، 2026-09-09)*

> **هذه هي القائمة التي تُغلق الوحدة.** لم تُنشر للوحدة 5 قائمة قبلها — النقطة 4.2 سجّلت أن نشرها
> كان محجوزًا حتى تنتهي الوحدة، وهذه هي.
>
> ⚠️ **قبل البدء:** إن كنت متقمّصًا حسابًا آخر فأنهِ التقمّص وسجّل الدخول من جديد، وإلا فالقائمة
> ستقرأ صلاحيات غير التي تظنها.
>
> ⚠️ **اقرأ القسم «ي» أولًا.** ثلاثة قيود في الخادم تجعل بعض الأدوار ترى صفحة فارغة بدلًا من رسالة
> رفض، وهذا سلوك مقصود ومسجَّل — لا تُبلّغ عنه كعُطل.

**أ — القائمة الجانبية والوصول** *(معيار: `deal.view` في §3.4، لا §8)*

| # | الدور | الخطوة ⇒ النتيجة المتوقعة |
|---|---|---|
| 1 | مدير | افتح النظام وانظر القائمة الجانبية ⇒ يجب أن ترى **«الطلبات / الصفقات»** ضمن مجموعة «المبيعات» |
| 2 | الرئيس التنفيذي | افتح القائمة الجانبية ⇒ يجب أن ترى البند **أيضًا** (§3.4 يمنحه `view` بنطاق `All`، خلافًا لـ §8 — قرار المالك 2026-08-31) |
| 3 | مندوب مبيعات خارجي | افتح القائمة الجانبية ⇒ يجب أن ترى البند **أيضًا** (§3.4 يمنحه `Own`، و§8 لا يذكره — القرار نفسه) |
| 4 | المدير الأعلى (Super Admin) | افتح القائمة الجانبية ⇒ البند **موجود** (صلاحية غير مشروطة، §3.11) |
| 5 | مدير | اضغط البند ⇒ يجب أن تفتح شاشة الصفقات على `/deals` |
| 6 | مدير | افتح `/deals` مباشرة من شريط العنوان ⇒ تفتح الشاشة نفسها، لا صفحة «ممنوع» |
| 7 | مدير | **اطلب من المدير الأعلى سحب `deal.view` من دورك** ثم أعد التحميل ⇒ **رسالة رفض صريحة**، لا قائمة فارغة (`SEC-09`) — ثم أعِد المنح |

**ب — شاشة الصفقات: الحالات الأربع**

| # | الدور | الخطوة ⇒ النتيجة المتوقعة |
|---|---|---|
| 8 | مدير | افتح الشاشة وراقب اللحظة الأولى ⇒ **حالة تحميل** ظاهرة قبل وصول البيانات |
| 9 | مدير | أوقف خدمة `nginx` ثم أعد التحميل ⇒ **رسالة خطأ مع زر إعادة المحاولة**، لا صفحة فارغة — ثم أعِد التشغيل |
| 10 | مدير | اضغط زر إعادة المحاولة بعد عودة الخدمة ⇒ تُحمَّل القائمة دون إعادة تحميل الصفحة |
| 11 | مدير | احذف كل الصفقات من قاعدة الاختبار وأعد التحميل ⇒ **«لا توجد صفقات ظاهرة لك»** — وليس «لا توجد صفقات» |
| 12 | قائد فريق | افتح الشاشة ⇒ **قائمة فارغة برسالة الحالة نفسها**، لا رسالة رفض (انظر «ي-1») |

**ج — القائمة: المحتوى والترتيب والترشيح**

| # | الدور | الخطوة ⇒ النتيجة المتوقعة |
|---|---|---|
| 13 | مدير | أنشئ صفقتين وانظر عمود الرمز ⇒ **`DL-2026-0001`** و**`DL-2026-0002`** بالضبط *(معيار «رموز الصفقات تتبع `DL-2026-0001`»)* |
| 14 | مدير | أنشئ صفقتين **لنفس العميل** وغيّر حالة إحداهما ⇒ **صفّان مستقلان، لكلٍّ حالته**، واسم العميل نفسه في الصفّين *(معيار «عميل له صفقة نشطة + طلب جديد ⇒ صفقتان مستقلتان»)* |
| 15 | مدير | انظر عمود العميل ⇒ **اسم** العميل لا معرّفه |
| 16 | مدير | أنشئ صفقة لعميل ترتيبه بعد المئة ⇒ يظهر **المعرّف** مكان الاسم *(سقف مذكور، انظر «ي-4»)* |
| 17 | مدير | انظر عمود المسؤول ⇒ **معرّف** لا اسم *(سقف مذكور، انظر «ي-5»)* |
| 18 | مدير | اكتب في مربّع البحث جزءًا من عنوان طلب واضغط «تطبيق» ⇒ تُرشَّح القائمة من **الخادم**؛ افتح أدوات المطوّر وتأكد أن الطلب يحمل `q=` |
| 19 | مدير | اختر حالة من مرشّح «الحالة» ⇒ طلب جديد يحمل `filter[status]=`، والقائمة تعود إلى **الصفحة الأولى** |
| 20 | مدير | جرّب المرشّحات الأربعة (الحالة، الاعتماد، النوع، المصدر) ⇒ كلٌّ منها يُرسَل باسمه الخادمي، والمرشّح غير المختار **لا يُرسَل أصلًا** |
| 21 | مدير | اضغط رأس عمود «الرمز» ⇒ `sort=-code`؛ اضغطه ثانية ⇒ `sort=code` |
| 22 | مدير | افتح الشاشة أول مرة وانظر الطلب ⇒ الترتيب الافتراضي **`-last_activity_at`** |
| 23 | مدير | استخدم قارئ شاشة على رأس العمود المرتَّب ⇒ يُعلن `aria-sort` بالاتجاه الصحيح |
| 24 | مدير | أنشئ أكثر من 25 صفقة ⇒ يظهر شريط الصفحات؛ زر «السابق» **معطَّل** في الصفحة الأولى |
| 25 | مدير | اضغط «التالي» ⇒ `page=2`، ثم غيّر مرشّحًا ⇒ **تعود إلى الصفحة الأولى** |

**د — إنشاء صفقة وتعديلها**

| # | الدور | الخطوة ⇒ النتيجة المتوقعة |
|---|---|---|
| 26 | مدير | انظر أعلى الشاشة ⇒ زر **«صفقة جديدة»** ظاهر |
| 27 | الرئيس التنفيذي | افتح الشاشة ⇒ **لا زر إنشاء ولا زر تعديل**، والقائمة تُقرأ عاديًا (§3.4 يمنحه `view` فقط) |
| 28 | مشتريات | افتح الشاشة ⇒ **زر تعديل موجود وزر إنشاء غير موجود** (§3.4 يمنحه `edit` ولا يمنحه `create`) |
| 29 | مدير | اضغط «صفقة جديدة» واحفظ دون اختيار عميل ⇒ **«العميل مطلوب»** قبل أي طلب للخادم |
| 30 | مدير | اختر عميلًا واكتب عنوانًا واحفظ ⇒ تُحفظ، ويظهر الصفّ في القائمة **بعد إعادة سؤال الخادم** |
| 31 | مدير | افتح تعديل صفقة ⇒ **لا يظهر حقل العميل ولا حقل المسؤول** (§4.3: كلاهما ممنوع على `PATCH`) |
| 32 | مدير | عدّل العنوان ثم اضغط «إلغاء» ⇒ **لوحة تأكيد داخل الحوار**، لا نافذة متصفّح |
| 33 | مدير | كرّر السابق واضغط **Esc** بدل «إلغاء» ⇒ اللوحة نفسها (§6.1: الإغلاق لا يتجاهل بصمت) |
| 34 | مدير | افتح تعديلًا ولا تغيّر شيئًا ثم «إلغاء» ⇒ **يُغلق فورًا** دون سؤال |
| 35 | مدير | اكتب عنوانًا أطول من 255 حرفًا واحفظ ⇒ **جملة الخادم نفسها** تحت الحقل، لا رسالة عامة |

**هـ — الاعتماد والرفض** *(معياران)*

| # | الدور | الخطوة ⇒ النتيجة المتوقعة |
|---|---|---|
| 36 | مندوب مبيعات داخلي | أنشئ طلبًا ⇒ يظهر في القائمة بشارة **«بانتظار الاعتماد»** كهرمية اللون **مع أيقونة وكلمة** *(معيار «طلب من موظف ⇒ بانتظار الاعتماد»)* |
| 37 | مدير | افتح القائمة ⇒ الشارة نفسها، ومعها زرّا **«اعتماد»** و**«رفض»** |
| 38 | مندوب مبيعات داخلي | افتح القائمة ⇒ **الشارة بلا أزرار** (§3.4 لا يمنحه `approve`) |
| 39 | مدير | اضغط «رفض» ثم أرسل دون كتابة سبب ⇒ **«السبب مطلوب لرفض الطلب»** ولا طلب للخادم |
| 40 | مدير | اكتب مسافات فقط كسبب وأرسل ⇒ **الرفض نفسه**، مطابقًا لقاعدة الخادم |
| 41 | مدير | اكتب سببًا حقيقيًا وأرسل ⇒ تتحول الشارة إلى **«مرفوضة»** حمراء |
| 42 | مندوب مبيعات داخلي | افتح صفقتك المرفوضة ⇒ **الشارة والسبب معًا** *(معيار «طلب مرفوض ⇒ سبب إلزامي وشارة للموظف»)* |
| 43 | مدير | اضغط «اعتماد» على طلب معلّق ⇒ الشارة تصبح **«معتمَدة»** خضراء |
| 44 | مدير | افتح تبويبين على الطلب نفسه واعتمده في الأول ثم ارفضه في الثاني ⇒ **«سبق البتّ في هذا الطلب»**، لا فشل صامت |
| 45 | مدير | أنشئ صفقة **بنفسك** وانظر عمود الاعتماد ⇒ **شرطة `—`، لا شارة** (لم تُقدَّم للاعتماد أصلًا، وهي حقيقة مختلفة عن «معلّقة») |

**و — تغيير الحالة**

| # | الدور | الخطوة ⇒ النتيجة المتوقعة |
|---|---|---|
| 46 | مدير | اضغط «تغيير الحالة» ⇒ قائمة تحوي **الاثنتي عشرة حالة كلها** (§4.4)، لا مجموعة مختصرة |
| 47 | مدير | من حالة «مبدئي» اختر «اكتمل التوريد» وأرسل ⇒ **«هذا التغيير غير مسموح به من حالة الصفقة الحالية»** — الخادم هو من يقرر (§7.1) |
| 48 | مدير | من «مبدئي» اختر «تم التواصل» ⇒ تُحفظ وتظهر الحالة الجديدة |
| 49 | مدير | اختر «خاسرة» ⇒ يظهر حقل السبب؛ اختر غيرها ⇒ **يختفي** |
| 50 | مدير | اختر «خاسرة» واترك السبب فارغًا ⇒ **«السبب مطلوب عند خسارة الصفقة»** ولا طلب للخادم |
| 51 | الرئيس التنفيذي | افتح القائمة ⇒ **لا زر لتغيير الحالة** (§3.4 لا يمنحه `change_status`) |

**ز — صفحة الصفقة والجدول الزمني** *(معيار)*

| # | الدور | الخطوة ⇒ النتيجة المتوقعة |
|---|---|---|
| 52 | مدير | اضغط رمز صفقة في القائمة ⇒ تفتح صفحة الصفقة على `/deals/<id>` |
| 53 | مدير | انظر أعلى الصفحة ⇒ **الملخّص أولًا** (الرمز، العنوان، العميل، المسؤول، آخر نشاط) |
| 54 | مدير | غيّر حالة الصفقة ثم انظر أسفل الصفحة ⇒ **مدخل جديد في الجدول الزمني** يحمل **الحالة القديمة ← الجديدة، ومَن، ومتى** *(معيار «تغيير الحالة ⇒ مدخل في الجدول الزمني»)* |
| 55 | مدير | انظر خانة «مَن» ⇒ **معرّف** لا اسم *(سقف مذكور، انظر «ي-5»)* |
| 56 | مدير | عدّل عنوان الصفقة ثم انظر الجدول ⇒ **المدخل موجود** بوصف «عُدِّلت الصفقة»، ولم يُحذف لأنه لم يغيّر حالة |
| 57 | مدير | افتح صفقة جديدة تمامًا ⇒ أول مدخل يقرأ **«فُتحت بحالة مبدئي»**، لا «من ← إلى» |
| 58 | مدير | افتح `/deals/<معرّف-غير-موجود>` ⇒ **«تعذّر فتح هذه الصفقة»** — ولا تذكر الرسالة سببًا (`OpenAPI §5.1`) |
| 59 | مندوب مبيعات داخلي | افتح `/deals/<معرّف صفقة ليست لك>` ⇒ **الرسالة نفسها بالضبط**، لا «ليس لديك صلاحية» |
| 60 | مدير | **اطلب سحب `deal.view_timeline` من دورك فقط** ثم أعد تحميل صفحة الصفقة ⇒ **الملخّص يظهر** ورسالة رفض **داخل قسم الجدول الزمني وحده** — ثم أعِد المنح |
| 61 | مدير | افتح صفقة لم يحدث لها شيء ⇒ **«لم يحدث شيء لهذه الصفقة بعد»** |

**ح — الملفات والإسناد**

| # | الدور | الخطوة ⇒ النتيجة المتوقعة |
|---|---|---|
| 62 | مدير | افتح صفحة صفقة ⇒ قسم «الملفات» يحمل **جملة صريحة** بأن المعروض هو ملفات هذه الصفحة فقط |
| 63 | مدير | ارفع ملف PDF ⇒ يظهر في القائمة باسمه الأصلي |
| 64 | مدير | **أعد تحميل الصفحة** ⇒ **القائمة تفرغ** — والملف سليم على الخادم *(قيد مذكور، انظر «ي-2»)* |
| 65 | مدير | ارفع ملفًا واضغط «تنزيل» ⇒ يُنزَّل الملف نفسه |
| 66 | مدير | ارفع ملفًا بينما خدمة الفحص متوقفة ⇒ **«لم ينتهِ فحص الفيروسات»** بدل زر التنزيل |
| 67 | الرئيس التنفيذي | افتح صفحة صفقة ⇒ **لا حقل رفع** (§3.4 لا يمنحه `edit`) |
| 68 | مدير | انظر قسم «المسؤول» ⇒ موجود؛ **مندوب مبيعات داخلي** ⇒ **غير موجود** (§3.4 يمنحه `edit` ولا يمنحه `assign_owner`) |
| 69 | مدير | أرسل الإسناد وحقل المسؤول فارغ ⇒ **«المسؤول مطلوب»** ولا طلب للخادم |
| 70 | مدير | أسنِد الصفقة إلى معرّف موظف صحيح ⇒ يتغيّر المسؤول، **ويظهر مدخل في الجدول الزمني** |

**ط — اللغة والاتجاه**

| # | الدور | الخطوة ⇒ النتيجة المتوقعة |
|---|---|---|
| 71 | مدير | بدّل اللغة إلى الإنجليزية ⇒ **كل** نص في الشاشات الثلاث يتبدّل، ولا يبقى نص عربي مكتوب في الشيفرة |
| 72 | مدير | بدّل إلى العربية ⇒ الاتجاه **RTL**: الشريط الجانبي يمينًا، والأعمدة تُقرأ من اليمين |
| 73 | مدير | في العربية، انظر أعمدة التواريخ والأرقام ⇒ **محاذاة منطقية** لا مقلوبة، والأرقام جدولية |
| 74 | مدير | بدّل السمة (فاتح/داكن) في اللغتين ⇒ الشارات الثلاث تبقى **مقروءة**، والحالة ليست باللون وحده |
| 75 | مدير | تنقّل بلوحة المفاتيح فقط عبر القائمة والحوار ⇒ **مسار كامل**، تركيز ظاهر، ولا فخّ تركيز خارج الحوار |

**ي — ما لا يمكن اختباره بعد، ولماذا**

1. **قائد الفريق والمشرف الخارجي والمشتريات يرون قائمة فارغة، لا رسالة رفض.** نطاقات `Team` و`Out`
   و`Asgn` لا تملك آلية في الخادم (النقطة 2.1: لا كيان فريق، ولا ربط بالزيارات، ولا عمود إسناد
   مشتريات)، فتُجيب الواجهة بـ **200 وصفحة فارغة**. ⚠️ **ولهذا فإن المعيار «طلب من موظف ⇒ بانتظار
   الاعتماد لقائد الفريق» يمكن إثباته اليوم بحساب المدير فقط** — قائد الفريق يملك الصلاحية ويرى
   الأزرار ولا يصل إلى صفقة يضغطها عليها. دَين مسجَّل في الخادم، لا عُطل في الشاشة.
2. **قائمة الملفات لا تُقرأ بعد التحميل.** لا وجود لـ `GET /deals/{id}/documents` في المشروع كلّه،
   والملفات محفوظة لكنها غير قابلة للسرد. نفس السقف الذي سجّلته الوحدة 6 في نقطتها 6.5.
3. **«غير نشط حتى يُعتمد» في الانسياب 3 غير مبني.** §4.3 لا يحوي عمود ظهور، فالصفقة المعلّقة صفّ
   عادي؛ الشاشة تضع شارة ولا تدّعي أن الخادم يخفيها.
4. **أسماء العملاء تقف عند 100 عميل.** العميل بعد المئة يظهر بمعرّفه.
5. **المسؤول ومنفّذ الإجراء يظهران كمعرّفات.** لا تنشر وحدة الهوية قائمة تسمح لهذه الوحدة بمطابقة
   اسم بمعرّف، و`CLAUDE.md` يمنع قراءة جداول وحدة أخرى.
6. **الجدول الزمني يعرض أول 25 مدخلًا فقط.** نقطة النهاية تُصفّح صحيحًا، لكن الشاشة لا تعرض أداة
   تصفّح بعد.
7. **لوحة المسار (Kanban) غير مبنية.** §5.2 و§9 يسندان للصفقات عرض لوحة؛ لم يطلبها أي معيار مفتوح،
   وهي مؤجّلة بقائمة نقاط خاصة بها **بانتظار `D-xx`**.

#### Follow-up after the owner tested it — **6.7a**, the owner picker *(2026-09-10)*

- [x] **6.7a** The assign control becomes a **picker** over `GET /users`, keeping the identifier box
      as a fallback.
      ⚠️ **Reported by the owner, running the module for the first time:** «حقل المالك يجب أن يكون
      بصيغة UUID سليمة — مش عارف اسند اي صفقه لموظف». The field demanded a UUID nobody knows by
      heart, so the control was correct and unusable, which Point 6.3 and Point 6.7 had both recorded
      as an accepted ceiling rather than a defect.
      **The reasoning behind that ceiling was wrong, and measuring settled it.** Point 6.3 reused
      `CustomerFormModal`'s line — "a control that 403s for the person looking at it is worse than no
      control" — without checking whether anyone was actually in that position. `GET /users` carries
      `admin.create_user` (§3.11: Super Admin and Manager); `deal.assign_owner` (§3.4) reaches the
      Manager and the Team Leader. **The only role that would be refused the list is the Team
      Leader — whose `assign_owner` is `Team`, which resolves to no rows at all (Point 2.1), so they
      cannot reach a deal to assign in the first place.** Every role that can use this control today
      can also list employees. Verified live: Manager `GET /users` → 200 with 7 rows (the Super Admin
      correctly absent, §3.12); Team Leader → 403.
      **Best-effort, on this module's own established pattern** — the same `try`/`catch` fallback
      `DealsView` uses for customer names and `DealDetailView` for the customer: a picker when the
      list reads, the identifier box when it does not, and the list is never requested at all without
      `deal.assign_owner`, a call that could only be refused.
      **4 new tests · 745 frontend (44 files) · vue-tsc clean · pint 546 files · PHPStan level 10
      clean · deptrac violations 0 / uncovered 0 on both configs.**
      ⚠️ **A probe found the `catch` untestable and the point fixed that rather than the probe.**
      Replacing the fallback with a rethrow reddened **nothing**: an unhandled rejection leaves
      `employees` empty exactly as the catch does, so asserting on the empty list proved neither.
      "The list was refused" and "the list is empty" are different facts, so the catch now sets an
      explicit `employeesUnavailable` flag which the screen renders as a sentence — and the same probe
      now reddens.
      ⚠️ **And that flag's first draft broke the control outright.** The new `<span>` was written
      **between** the `v-if` select and the `v-else` input, which severs the chain so the `v-else`
      never renders and the field vanished entirely. The spec caught it on the next run; the span is
      now a sibling after the pair, with a comment saying why it cannot go back.
      **Three existing tests needed updating, not because they were wrong but because the call order
      changed:** the picker reads `/users` on mount, so `fetchMock.mock.calls[0]` was no longer the
      upload or the assign. They now find a call by its path, which is what they always meant.
      **Waste audit:** 2 new lang keys per language, both rendered; `listUsers` already existed in
      `services/identity.ts` and is reused rather than re-declared; no new dependency.
      **Not covered:** the picker lists the first page of employees (`per_page` default 25) and does
      not page — a company past that many active employees needs a search, which is owed. Nothing
      here filters by role: §4.3 calls `owner_id` the "assigned sales employee", and no source
      restricts assignment to a role list, so inventing one would be inventing authorisation.

- [x] **6.7b** The **create** dialog's owner control, on the same terms — and gated on the permission
      that actually decides it.
      ⚠️ **Two more findings from the owner's own testing session.** First: «مبيظهرش عندو الموظفين في
      الافتة بتاعت اسين الصفقة» — Point 6.7a replaced the identifier box on the *assign panel* and
      left the identical box on the **create form**, so the Manager still had to type a UUID to set an
      owner at creation. Same picker, same best-effort fallback, same `listUnavailable` sentence.
      Second, and the more interesting one: **Indoor Sales was shown an owner field at all.**
      **The server was already right; the screen was lying.** `SaveDeal::ownedWithinScope` refuses an
      `Own`-scoped creator who names anyone but themselves — measured live, `permission_denied` — and
      assigns them automatically when none is named (`owner -> 01a05389…`, `approval -> pending`). So
      for Indoor Sales and Outdoor Sales the field could only ever hold their own id, and every other
      value produced a 403. A control that looks like a choice and is not one is worse than no
      control, which is the *correct* application of the line Point 6.3 misapplied one point earlier.
      The field is therefore keyed on **`deal.assign_owner`** — §3.4's own row for choosing an owner —
      and drawn for nobody else, rather than drawn disabled: the server is going to set the owner
      regardless, and saying so with a dead box is noise.
      **The list is not requested when it cannot be used:** not on an edit (`owner_id` is `prohibited`
      on a `PATCH`) and not without the permission — a call that could only be refused.
      **5 new tests · 750 frontend (44 files) · vue-tsc clean · pint 546 files · PHPStan level 10
      clean · deptrac violations 0 / uncovered 0 on both configs.**
      **Two probes, both reddened and restored:** keying the control on `deal.create` instead of
      `deal.assign_owner` (**3 red**, including the list-refetch case), and fetching the employee list
      regardless of edit-or-permission (2 red).
      **The spec's harness had to be reworked, not just extended.** It stubbed a bare `fetch` and read
      `mock.calls[0]`; the dialog now signs a profile in and reads `/users` on open, so the harness
      signs in, answers the list separately, and finds the write call by its path. Three refusal tests
      moved their forced 422/403/network response into the harness, because their own
      `mockResolvedValue` was being overwritten by it — they had been passing on a mock that no longer
      reached the code under test.
      ⚠️ **A third finding, in the dev data rather than the code:** Indoor Sales could see **zero
      customers** (`customer.view` is `Own`, and no seeded customer had a `sales_owner_id`), so they
      could not create a deal at all through the UI regardless of this fix. One customer was assigned
      to them in the dev database so the Flow 3 approval path can be walked. Not a code defect and not
      committed — dev data only.
      **Not covered:** the picker still shows the first 25 active employees with no search, and still
      does not filter by role — unchanged from 6.7a and owed the same way.

#### After the `/code-review` pass — **6.8a**, the three fixes it earned *(2026-09-10)*

- [x] **6.8a** Three findings from a two-axis review of `main...HEAD`, all acted on. The review was
      run against the whole branch, with the **Spec** axis reading Module 5's five criteria as they
      stood at `main` and the **Standards** axis reading `CLAUDE.md`, `AGENTS.md`,
      `Coding_Standards_EN.md`, `Design_System_EN.md` plus a Fowler smell baseline.
      **1 — The undocumented route (Standards, hard violation).** `Coding_Standards §8`: "Keep
      OpenAPI schemas, request validators, response serializers, and tests aligned. **A route is
      incomplete if it is undocumented.**" `GET /deals/{id}/timeline` was added while the code cited
      `OpenAPI §6`/`§8` as though they already governed it, and the contract's own route tables list
      every sibling. **Verified before editing:** no generated OpenAPI document exists anywhere in the
      repo, so `OpenAPI_Contract_EN.md` *is* the documentation, and `timeline` appeared in it zero
      times. ⚠️ **Wider than the review said:** `/deals/{id}/assign` and `/deals/{id}/documents` were
      **also** absent, and absent at `main` — Points 2.4 and 4.1 skipped the same step, so this
      branch added the third omission rather than the first. All three are now in §7.1/§7.2, and in
      `arabic/docs/OpenAPI_Contract_AR.md` in the same edit: an English document and its Arabic
      companion differing is a defect by the documents' own definition. Verified identical afterwards.
      **2 — The criterion that overstated itself (Spec).** "Employee-entered request → 'Pending
      Approval' for the Team Leader, **inactive until approved**" was ticked `[x]` while its own note
      said the second clause is not built. The disclosure was accurate and in the wrong place: a
      reader skimming boxes never reaches it. The row is now `[~]` and names the missing clause in the
      line itself; the ownership table, this step's own 6.8 entry, and debt entry 7 all say the same
      thing. **The tick was the defect, not the prose** — which is the failure mode the owner's own
      "State column goes stale" rule exists to prevent.
      **3 — The owner picker, written twice (Standards, judgement call).** Duplicated Code, and a
      small Shotgun Surgery: the loader, two refs, the `<select>`/`<input>` pair and the "list
      unavailable" sentence were byte-identical in `DealFormModal` and `DealDocumentsPanel`, because
      Point 6.7a fixed one and Point 6.7b had to fix the other. Collapsed into `DealOwnerPicker.vue`,
      which owns the best-effort fetch and both states; each caller keeps its own `field-id`/`test-id`
      and its own decision about **whether** to draw it — the create form only for
      `deal.assign_owner`, the panel only inside its assign section. **The extraction pays for itself
      in the probes:** breaking the fallback now reddens **both** callers' tests from one edit, where
      before each copy needed its own probe.
      **Two probes on the extracted component, both reddened and restored:** removing the fallback
      (2 red, one per caller) and never drawing the unavailable sentence (2 red).
      **750 frontend (44 files) · vue-tsc clean · pint 546 files · PHPStan level 10 clean · deptrac
      violations 0 / uncovered 0 on both configs · 203 localisation/design tests.**
      **Findings deliberately not acted on**, and why: `refusalKey()` repeating in two controls and
      the scoped `<style>` block repeating across five files are both judgement calls the reviewer
      itself noted follow existing repo precedent (`CustomersView`, `SuppliersView`, `CatalogView`,
      `SupplierQuotationsView` already do the same) — changing them is a repo-wide convention change,
      not this branch's to make, so they go on the debt register. A stray docblock above `onDecided`
      in `DealsView` is a comment, not a defect.
      ⚠️ **A twin this step did not repair:** `arabic/CHECKLIST_AR.md` is **427 lines against this
      file's 9,158** and was last touched 2026-08-12, before Modules 3 through 7 existed. Neither
      developer has mirrored a checklist entry into it in a month. Mirroring one criterion row into a
      file ~8,700 lines behind would imply a synchronisation that does not exist, so it is recorded
      here as **pre-existing debt for both developers** rather than half-fixed.

**Acceptance criteria**
- [x] Customer with an active deal + new request → **two independent deals**, separate statuses
      *(Point 6.2. Two rows for one customer, `Lead` and `Negotiations` side by side, neither grouped
      nor merged — the server was already correct and the screen is where a person can see it.)*
- [x] Deal reaches Won → customer status becomes **"Customer"** automatically and permanently
- [x] All deals Lost → status **"Deal Not Completed"**
- [~] Employee-entered request → "Pending Approval" for the Team Leader — **the badge and the
      approval are built; "inactive until approved" is NOT**
      *(Point 6.4, and deliberately **not** a `[x]`. This criterion has two clauses and only one of
      them is met, so a tick would claim more than the code does — the clause that is missing is in
      the row a reader scans, not only in the note beneath it. Raised by a `/code-review` spec pass
      on 2026-09-10, which was right: the previous `[x]` overstated the work while the prose under it
      disclosed the gap correctly, and a reader skimming boxes would never reach the prose.
      §6.4's amber badge with an icon **and** a word, and Approve/Reject behind
      `deal.approve`. ⚠️ **Demonstrable as the Manager only**: §3.4 grants the permission to the
      Manager (`All`) and the Team Leader (`Team`), and `Team` resolves to no rows (Point 2.1), so
      the role this criterion names holds it and reaches no deal. ⚠️ **"inactive until approved" is
      not built** — §4.3 has no visibility column, and inventing one would be inventing a documented
      field, so a `pending` deal is an ordinary row the screen badges and does not claim the server
      hides. **This is what keeps the box at `[~]` rather than `[x]`**, and closing it needs a `D-xx`
      granting §4.3 a visibility column. Both on the debt register.)*
- [x] Rejected request → mandatory reason + badge for the employee
      *(Point 6.4. The reason is collected **before** submission (§6.6) and whitespace is refused as
      `RejectDealRequest` refuses it; the badge and the reason are drawn together, §4.3 making the
      reason mandatory precisely so the employee can read it.)*
- [x] Status change → timeline entry with old status, new status, who, when
      *(Points 5.1, 5.2 and 6.6 — the read port, the route and the screen. This is what Point 1.1
      left owing when it declined `deal_status_history` on the grounds that `audit_log` already
      stored those four fields; the reading stands and the debt is repaid rather than the table
      being added. ⚠️ The actor is an identifier, and the screen shows the first 25 entries only.)*
- [x] Deal codes follow `DL-2026-0001`
      *(Point 6.2. The column renders what `DocumentNumberAllocator` allocated, digit for digit; the
      SPA never builds a code, `SaveDealRequest` prohibiting the field outright.)*
