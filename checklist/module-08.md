> Frozen history of Module 8, cut verbatim from `CHECKLIST.md` on 2026-09-16 (commit `6d9d348`).
> Open boxes here are stale copies — the live ones are in `CHECKLIST.md`. Do not tick here.

## Module 8 — Approvals

> As a Team Leader, I want to review quotations and adjust tax and margin before approving, so that I
> protect the company's margin.

**Endpoints** `PATCH /:id/approve` · `/return` · `/edit-and-approve`

**Acceptance criteria**
- [x] Tax or margin edit → **mandatory audit entry** with old and new values *(1.3 #134: `QUOTATION_UPDATED` old→new before the approval row; browser 3.2: `23.000 → 30`)*
- [x] Returned quotation → mandatory note + returns to Draft, appears under "Incomplete" *(1.2 #133 blank note 422, `pending → draft`; 2.2 #137 the bucket; 3.3 #141 the panel and the note on the detail)*
- [x] Team Leader approving own quotation → `is_self_approved = true` + **yellow badge** +
      `SELF_APPROVAL` audit entry *(1.1 #132 flag + event instead of `QUOTATION_APPROVED`; 3.3 #141 the badge on list and detail; the actor is the Manager — the Team Leader is `D-a`'s refusal)*
- [x] Quotation waiting beyond SLA → red badge + "days waiting" column *(2.1 #136 server-computed from `limits.quotation_approval_sla_hours`; 3.1 #139 the column and the text-labelled badge)*
- [x] Team Leader and Manager → **same screen, same authority** *(3.1 #139 one `/approvals` on `quotation.approve` (`D-10`); **the Team Leader cannot reach it today** — `quotation.approve.team` resolves to no rows until a team entity exists (`D-a`, debt register) — proven as the Manager, the Team Leader as a refusal)*
- [x] No automatic escalation *(3.1 #139 `NoApprovalEscalationTest` reads `Schedule::events()` and refuses `/approv|escalat/i`, proven RED on a planted entry; a row past the SLA stays `pending` and stays listed)*

### Point list — published 2026-09-15, approved by merging #131

Supersedes #94 (drafted 2026-09-10 by the second developer against `fd2592d`, before Module 7
existed). What #94 waited on is now on `main`: the write path, `QuotationStatusTransition` with the
edges `pending → approved` and `pending → draft` (7 · 4.1), `submitted_at` (7 · 4.2, closes #94's
`D-b`), `is_self_approved` and `rejection_reason` (7 · 1.1), `AuditEvent::selfApproval()` (Module 0,
still uncalled), the permissions `quotation.approve` and `quotation.return_with_note` (§3.5, seeded),
the setting `limits.quotation_approval_sla_hours` (Module 2, `SystemLimit`), the list with buckets
(7 · 5.x) and the builder (7 · 6.6/6.7). Module 8 was reassigned to Yousef 2026-09-13, so #94's
reason for a separate `Approvals` module and a write-side contract — two owners in one directory —
no longer holds. Recommendation: close #94 unmerged and record it here as read.

**Decisions before Point 1.1 — defaults proposed, owner to confirm:**

- **Q1 · placement.** **Default: inside `crm/app/Modules/Quotations/`** — `Application/Writing/
  ApproveQuotation`, `ReturnQuotation`, the routes in the existing `quotations` group, no new
  deptrac module, no new interface. Approve and return are two more edges of the state machine
  Module 7 already owns, the same shape as `SubmitQuotation`. A separate module would need a
  `QuotationTransitionInterface` with one implementation — the waste audit's own definition of
  unnecessary complexity. Cost: "Module 8" is a delivery unit without a directory.
- **Q2 · what a return does.** **Default: the same row goes `pending → draft`** (4.1's edge; the
  `quotations` migration docblock: "a return produces a Draft, so there is no Returned row"), with
  two new nullable columns `returned_at` and `return_note` on `quotations` (new migration, `down()`),
  `submitted_at` cleared so "days waiting" restarts on resubmit, and the note mandatory (§6.3).
  The pending snapshot survives in the audit row's old values, not as a second row. Alternative:
  a full copy through 4.3 — needs a terminal status for the original that §6.1's nine do not
  contain, so it is a schema and a `D-xx`, not a default.
- **Q3 · "own" for self-approval.** **Default: actor = `created_by`** — §6.5 says "a quotation they
  built themselves". Alternative: the deal's `owner_id`, which 3.4 chose for the *create* scope; that
  would flag a Manager approving a quotation a subordinate built on the Manager's own deal.
- **Q4 · edit-and-approve's body.** **Default: `SaveQuotationRequest`'s body, exactly 6.7's
  `PATCH`**, one transaction (re-price, update, approve), `If-Match` required. The margin/tax
  guards are `UpdateQuotation::guardMarginAndTax` — reused, not copied — and `QUOTATION_UPDATED`'s
  old/new pair is the acceptance criterion's "mandatory audit entry". `D-c` (build plan names three
  routes) stands; `OpenAPI §7.2` is amended (1.4).
- **Q5 · badge counters.** §18.1 and Design System §5.1 permit *Approvals* and *My Quotations*
  badges; Module 7 left both to Module 8. **Default: one route `GET /api/v1/badges` returning the
  caller's counts** (2.3) — not in `OpenAPI §7`, flagged as a new requirement. Alternative: defer
  to a debt row and ship the screen without counters.
- **`D-a` stands** (#94): `quotation.approve.team` resolves to no rows until a team entity exists,
  so the Team Leader — the user story's own actor — is refused. Built and demonstrated as the
  Manager; the manual test list names the Team Leader path as a refusal to observe.

#### Step 1 — the three actions (backend)

- [x] **1.1** `ApproveQuotation` + `PATCH /quotations/{id}/approve` — `permission:quotation.approve`,
      row scope through `QuotationWriteAccess::open` (§3.5: Manager `All`; Team Leader `Team` fails
      closed, `D-a`), `If-Match` (`DB-12`), `pending → approved` through `QuotationStatusTransition`
      else `409`, `is_self_approved = true` when actor = `created_by` (Q3) and then the audit row is
      `SELF_APPROVAL` **instead of** `QUOTATION_APPROVED` (§6.5, `D-50`). *Verified by* approve,
      self-approve (flag + event type), draft/approved refused, stale token 409, Indoor Sales 403,
      Team Leader 403 named as `D-a`. *(2026-09-15, #132 — 18 tests; the Team Leader is a **404**, not
      a 403: `team` fails closed in `QuotationRowScope` as submit/edit/read do; the `QUOTATION_APPROVED`
      / `SELF_APPROVAL` choice was mutation-tested)*
- [x] **1.2** `ReturnQuotation` + `PATCH /quotations/{id}/return` — `permission:quotation.return_with_note`,
      body `{note}` required non-blank (422 on the field), migration adding `returned_at` +
      `return_note` (Q2, `DEV-03` tested), `pending → draft`, `submitted_at` null, audit
      `QUOTATION_RETURNED` carrying the note. *Verified by* return, blank note 422, non-pending 409,
      stale 409, 403s; migration rollback. *(2026-09-15, #133 — 23 tests; `submit`/`approve`/`return`
      folded into `QuotationDirectoryInterface::moveStatus()` as the 7·4.2 note planned; `returned_at`/
      `return_note` are on `QuotationDetail` for the audit, on the wire only at 2.1)*
- [x] **1.3** `PATCH /quotations/{id}/edit-and-approve` — `permission:quotation.approve`, 6.7's body
      (Q4), one transaction: `UpdateQuotation`'s path opened to `pending` for this caller only
      (re-price, `guardMarginAndTax` → `edit_margin`/`edit_tax`), then 1.1's approval;
      `QUOTATION_UPDATED` with old/new values (acceptance row 1) followed by `QUOTATION_APPROVED` or
      `SELF_APPROVAL`. *Verified by* a margin edit audited old→new, a tax edit likewise, no edit in
      the body still approves, a Draft refused, stale 409, refused without `edit_margin`. *(2026-09-15,
      #134 — 18 tests; `EditAndApproveQuotation` composes `UpdateQuotation` (opened to `pending` by a
      new `$editable` argument) and `ApproveQuotation` in one transaction; a Draft is 1.1's 409, not
      3.6's 422)*
- [x] **1.4** `docs/OpenAPI_Contract_EN.md` §7.2: add `/edit-and-approve`; give the three approval
      actions their request schema, permission, audit event, accepted and resulting state, and
      idempotency note (`D-c`). Docs only, no CI. *(2026-09-15, #135 — one table under §7.2, each cell
      grep-checked against the routes, events and edge table; no `Idempotency-Key`, as 7·4.2's Q6)*

#### Step 2 — the read surface

- [x] **2.1** Server-computed waiting: list rows and the detail carry `days_waiting` (from
      `submitted_at`, null unless `pending`) and `sla_exceeded` (`limits.quotation_approval_sla_hours`
      through `SettingReader`, `D-11`); the detail also carries `returned_at` and `return_note`.
      The SPA never computes either. *Verified by* a pending row past the SLA (`travel()`),
      one within it, a draft with nulls; the setting read, not a constant. *(2026-09-15, #136 — 7 tests;
      `ApprovalWaiting` in Application, threaded through `QuotationPayload`; calendar days, floored;
      `sla_exceeded` is **null** while the limit is unseeded — the reader's "do not guess" contract, an
      assumption for the owner; no SPA change until Step 3 reads the fields)*
- [x] **2.2** `filter[bucket]=incomplete` — drafts with `returned_at` not null (§8 "Quotations
      (including incomplete)"), row-scoped like the other buckets. *Verified by* a returned draft
      listed, a plain draft not, `unknown_bucket` unchanged for typos. *(2026-09-15, #137 —
      `status = draft AND returned_at IS NOT NULL` as a sibling of the `whereIn` branch; `BUCKETS`
      stays Q1's two status lists; one test method in the existing directory list test; the
      Incomplete tab is 3.3's)*
- [x] **2.3** `GET /api/v1/badges` (Q5) — `{approvals, my_quotations}`: pending quotations within the
      caller's `quotation.approve` scope; the caller's own returned drafts. `permission:` none beyond
      `auth` (a zero is the answer for a role without the grant). *Verified by* Manager counts, Sales
      sees `approvals: 0`, own returned draft counted once. *(2026-09-15, #138 — `BadgeCounts` =
      two `list()->total` reads over the caller's reach: `approvals` = `pending` within
      `AuthorizeAction::decide('quotation','approve')` (a denied decision has no scopes, so `0` without a
      branch), `my_quotations` = 2.2's `incomplete` within `own` (= deal owner, the 2026-09-11 ruling — not
      `created_by`; the two coincide for every seller today); Team Leader `0` under `D-a`; the 12th copy of
      the test fixture block — debt row below)*

#### Step 3 — the screen

- [x] **3.1** `/approvals` — nav item on `quotation.approve` (`navigation.ts`, badge slot
      `approvals`); one page for Team Leader and Manager (`D-10`); pending rows with employee,
      customer, total, `days_waiting`, a red **text-labelled** badge when `sla_exceeded` (`D-11`,
      Design System §6.4 "never colour alone"); Approve and Return (note dialog) inline, 6.5's 409
      banner on a stale token; refused-by-permission, empty and error states. *Verified by* vitest +
      Claude Browser AR/EN × desktop/mobile. **This is where `D-11` "no automatic escalation" is
      asserted: a row past the SLA is still `pending` and still here, and no scheduler entry names
      approvals** (a test greps the `Kernel`/schedule for the word). *(2026-09-15, #139 —
      `ApprovalsView` on `group_by=employee` + `filter[status]=pending`, so "employee" costs no new
      wire field; a list row has no etag, so Approve/Return read the detail first and act on its token;
      customer names via one `readCustomer` per distinct id (6.3's 100-row lookup missed row 234);
      `NoApprovalEscalationTest` reads `Schedule::events()` and failed on a planted entry; browser
      AR/EN × desktop/mobile — the 375px clip is the shell's known context-bar overflow, same on
      `/quotations`; the 409 banner is vitest-proven only, a real race cannot be staged by hand)*
- [x] **3.2** Edit-and-approve on screen — 6.7's builder opened from `/approvals` for a `pending`
      quotation by an approver, save calling 1.3 ("Edit & approve"); the non-Draft redirect of 6.7
      excepts this mode. *Verified by* vitest (route, body, headers) + browser. *(2026-09-15, #140 —
      a third route name on the same `QuotationBuilderView`, `quotation.approve`; `approving` picks the
      redirect's accepted status (`pending`) and the save's endpoint; browser AR/EN desktop: margin 23→30
      audited `QUOTATION_UPDATED` then `SELF_APPROVAL`; mobile is rendering-only — the pane could not
      dispatch a tap under emulation)*
- [x] **3.3** The yellow **"Self-approved"** chip (§6.5, Design System §6.4) on 6.3's list, 6.5's
      detail and 3.1's page; the returned draft's note on 6.5's detail and the **Incomplete** tab on
      6.3's list (2.2); nav badges from 2.3 on *Approvals* and *My Quotations*. *Verified by* vitest
      + browser. *(2026-09-16, #141 — `is_self_approved` added to the list row (the summary had none);
      `SelfApprovedBadge.vue` replaces 6.5's inline span and serves list + detail; **not on 3.1's page**:
      its rows are `pending` and the flag is set at approval, so the chip could never render there;
      `incomplete` is a third panel between Active and History, outside the "N quotations" count;
      sidebar counts read `GET /badges` on mount and on every route change, a zero draws nothing)*

#### Step 4 — close the module

- [x] **4.1** Arabic manual test list, freeze to `checklist/module-08.md`, stub here, ownership row. *(2026-09-16, #142 — 17 of 17; the six criteria ticked with the point that proves each; the Team Leader path is the one honest gap, `D-a`)*

**What this list does not cover:** notifications on approve/return (§18.2 lists no such mail);
the Team Leader's `team` scope (`D-a`, debt register); "Self-approvals" report column and dashboard
count (Modules 13/14); send / `sent_at` (Module 9); the Performance report; `q` (Module 15).

---

