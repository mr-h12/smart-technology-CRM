> Frozen history of Module 7, cut verbatim from `CHECKLIST.md` on 2026-09-14 (commit `59179de`).
> Open boxes here are stale copies — the live ones are in `CHECKLIST.md`. Do not tick here.

## Module 7 — Customer Quotations ⭐ (the hardest module)

> As a sales employee, I want to build a price quotation for my customer using supplier prices and a
> profit margin, so that I can send it after my Team Leader's approval.

**Tables** `quotations` · `quotation_items` · `quotation_additional_items` · `user_term_suggestions`

**Endpoints**
- [x] `POST /api/v1/quotations` · `GET /:id` *(3.4 #97, 3.5 #100)*
- [x] `PATCH /:id/submit-for-approval` *(4.2 #105)*
- [x] `POST /:id/new-version` *(4.3 #106)*
- [x] `GET /api/v1/quotations?group_by=employee|customer` *(5.4 #119, 5.5 #120)*

**Frontend** quotation builder — dynamic suppliers via (+) up to 10 · products per supplier · live
calculation · confirmation preview before saving · SmartTermInput

**Acceptance criteria — the most important in the project**
- [x] Cost 1000, margin 20% → selling price **1200** automatically *(2.1 `PricedLineTest`)*
- [x] Quotation margin 20%, line margin 30% → line uses **30%** *(2.1 `PricedLineTest`)*
- [x] Suppliers in different currencies → converted at the FX rate captured at creation, one
      quotation currency *(2.1, 3.3 `CreateQuotationTest`)*
- [x] Rounding on: total 1234.67 EGP → final total **1235**, `rounding_diff` **0.33** *(1.2 `QuotationMoneyInvariantTest`, 3.3)*
- [x] Rounding on: total 1234.678 USD → final total **1234.68** (rounding unit 0.01) *(1.2, `CurrencyMatrixDataTest`)*
- [x] Rounding off for the currency → final total keeps full precision, `rounding_diff` **0** (`D-65`) *(1.2, 3.3)*
- [x] Items 10,000 + delivery 1,000, discount 1%, tax 14% → tax base **9,900**, tax **1,386** *(2.2, 2.3 `QuotationTotalsTest`)*
      (discount first per `D-64`; delivery outside the base per `D-62`)
- [x] Customer flagged tax-exempt, or `tax_percent` null → **no tax line at all**, not a zero line (`D-63`) *(2.3, 3.3, 6.5 no tax row)*
- [x] Quantity above the supplier's recorded amount → **inline red warning**, not a block *(3.4 `meta.warnings`, 6.6 red line)*
- [x] Product with no recorded price → **save is blocked** *(3.3, 3.4 `422 business_rule_blocked`, 6.6)*
- [x] Supplier price changed after the quotation was built (Draft) → warning + "refresh prices" *(4.5 warning; "refresh" = Edit → Save re-prices, no separate route)*
- [x] Employee and Team Leader edit simultaneously → **409 Conflict** *(3.6 `If-Match`, 6.7 banner)*

**Money rules — no exceptions**
- [x] `Decimal` everywhere; no float touches a price *(`PrecisionTest` NUMERIC; BCMath in `Domain/Pricing`)*
- [x] All calculations in the backend *(the SPA sends inputs, shows `final_total` from the response — 6.6/6.7)*
- [x] No intermediate rounding — final total only, and only when rounding is enabled (`D-65`) *(2.x truncation rows, 3.3)*
- [x] Discount subtracted **before** tax, reducing the tax base (`D-64`) *(1.2 CHECK, 2.2)*
- [x] Editing an FX rate never alters an existing quotation *(`fx_rate_at_time` on the line, 1.3/2.1; 4.5 never compares the rate)*
- [x] Unit tests for every formula, rounding boundary, conversion, discount, tax, additional item *(`PricedLineTest`, `QuotationTotalsTest`, `QuotationMoneyInvariantTest`)*

#### Step 1 — schema and domain *(shipped 2026-09-07, PRs #83–#89; boxes added 2026-09-12)*

The seven points below shipped one PR each on 2026-09-07 and left only debt-register entries here
— no point list and no boxes — so Steps 2 and 3 cite "Point 1.1 … 1.7" by numbers this file never
carried. Recorded retroactively in the one-line form; whether the step's point list was approved
before the first PR is not recorded anywhere and is not claimed here.

- [x] **1.1** `quotations` table — `D-63`'s nullable `tax_percent`, `D-65`'s rounding snapshot, `version`/`version_token` *(2026-09-07, #83)*
- [x] **1.2** §5.2's money identities as six CHECKs on `quotations` (tax base, net, total-before-round, final total, rounding-off diff, tax null-pairing) *(2026-09-07, #84)*
- [x] **1.3** `quotation_items` — `moneyWithContext('unit_cost')`, `line_no` *(2026-09-07, #85)*
- [x] **1.4** `quotation_additional_items` — never taxed, `D-62` *(2026-09-07, #86)*
- [x] **1.5** `customers.is_tax_exempt` for `D-63` *(2026-09-07, #87)*
- [x] **1.6** `App\Support\Database\DocumentNumberAllocator` extracted from the Deals and Supplier Quotations directories for the `QT-` code *(2026-09-07, #88)*
- [x] **1.7** Quotations domain layer and its Eloquent directory — `QuotationDraft`, `QuotationDirectoryInterface`, `QuotationSummary`, `EloquentQuotationDirectory` *(2026-09-07, #89)*

#### Step 2 — pricing engine *(point list approved 2026-09-07)*

Pure `Quotations/Domain/Pricing/` classes: no framework, no database, and **no import outside
their own namespace**, which `deptrac.layers.yaml`'s empty `Domain` ruleset requires. §5.2's last
two lines are deliberately absent — `Admin\Domain\Money\RoundingRule::apply()` already *is* them
("§5.2's last two lines, and nothing else"), and the rounding acceptance rows already pass against
it in `tests/Feature/Seed/CurrencyMatrixDataTest.php`. Step 3's Application layer composes the
two, which is the crossing `Catalog/Application` already makes; the engine therefore stops at
`total_before_round` and **Step 2 changes neither deptrac configuration**. Every output is brought
to `D-68`'s money scale by truncation — `bcadd($v, '0', 6)`, the idiom `RoundingRule`'s disabled
path already uses — because BCMath truncates where PostgreSQL would round, and the four additive
CHECKs of Point 1.2 must hold exactly at scale 6.

- [x] **2.1** `PricedLine` — §5.1's four formulas: `unit_cost_base = unit_cost × fx_rate_at_time`
      (`D-09`), the margin inheritance (`D-03`), `unit_price = unit_cost_base × (1 + margin / 100)`
      (`D-04`), `line_total`, `line_cost`. **A `null` line margin inherits the quotation's; `'0'`
      does not** — zero is a real margin, the numeric form of the `array_key_exists` distinction
      the drafts already make. A negative margin stays legal, as Point 1.3's schema allows.
      *Verified by* acceptance rows 1 (`1000` at `20%` → `1200.000000`), 2 (a line's `30%` beats
      the quotation's `20%`) and 3 (conversion at the captured rate), plus a truncation row and
      `SCALE` asserted equal to `Precision::MONEY_SCALE` — restated, not imported, exactly as
      `RoundedTotal::SCALE` is and for the same reason.

- [x] **2.2** `QuotationTotals` through the tax base — `subtotal = Σ line_total`,
      `additional_total = Σ amount`, `discount_amount` (`D-07`), and
      `tax_base = subtotal − discount_amount` (`D-64`). **Additional items are summed and then
      kept out of the tax base** (`D-62`, `OD-01`); that exclusion is the one Point 1.2's CHECK
      cannot catch, because the identity it constrains has no `additional_total` term.
      *Verified by* acceptance row 7's first half (items `10,000` + delivery `1,000`, discount
      `1%` → tax base **`9,900`**), a test that fails if delivery enters the base, and the empty
      quotation returning `0.000000` rather than an error.

- [x] **2.3** Tax and the net chain — `tax_amount = tax_base × tax_percent / 100`,
      `net_amount = subtotal + additional_total − discount_amount`,
      `total_before_round = net_amount + tax_amount`. **A null `tax_percent` yields a null
      `tax_amount`, never `'0'`** (`D-63`): `quotations_tax_amount_matches_tax_percent` refuses the
      mixed pair and `quotations_tax_percent_not_zero` refuses a zero percent, so an exempt
      quotation has no tax line at all rather than a zero one. Also extends `DB-07`'s float-token
      scanner over `Domain/Pricing` by giving it a directory list instead of one path.
      *Verified by* acceptance rows 7 (tax **`1,386`**) and 8 (exempt), the two remaining additive
      identities, and §5.2's worked example end to end — `7,368.42` → `total_before_round`
      **`8,315.998812`** → `final_total` **`8,316.000000`**, composed with `RoundingRule` in the
      test only. The document prints `8,315.9988`; the exact scale-6 value carries two more digits
      and the final total is unchanged.

#### Step 3 — the write path *(point list approved 2026-09-07)*

The write half of §6: row scope, the directory's two child tables, the use case that composes
Step 2's engine, and the four `OpenAPI §7.1` routes. The engine stops at `total_before_round`
(Step 2), so **Point 3.3's Application layer** is where `Admin\Domain\Money\RoundingRule::apply()`
finishes §5.2 and where `AdminContract` is first added to `Quotations` in `deptrac.modules.yaml`
— the crossing `Catalog/Application` already makes; the Domain and Infrastructure points below add
no dependency. Three owner decisions are still open and each blocks a later point, not an earlier
one: what "own" means for a quotation (`created_by` vs the deal's owner) and the `view cost & margin`
permission slug both block **3.5**; where the `Idempotency-Key` store lives — `AuditEnforcementTest`
forbids `app/Http`, `app/Support` and `routes` from writing to the database — blocks **3.7**.
*Ruled 2026-09-11, in Point 3.4 (#97):* a quotation's **"own" is its deal's `owner_id`** (so a scoped
`create` constrains which deal may be quoted, `team` fails closed until a team entity exists);
**`customer_id` must be the deal's customer** (`422` on `customer_id`); and **`fx_rate_missing`** is a
distinct `422 business_rule_blocked` detail code beside `supplier_price_missing`. 3.5 inherits the
first and still waits on the slug; 3.7 still waits on the store.
*Ruled 2026-09-12, in Point 3.7 (#102):* the store is a **new module, `app/Modules/Idempotency`** (not
Audit's, whose rows never expire, and not Quotations-local, since §9.1 names five resources); and Point
3.6's assumption stands — **a Draft is re-priced at the FX rate effective at the edit**, `D-09`'s "at
creation" governing the first pricing only.

- [x] **3.1** `QuotationRowScope` — §3.5's `own | team | asgn | all` resolved to owner-id lists,
      the third transcription of the shape `CustomerRowScope` and `DealRowScope` share (verified
      byte-identical once comments are stripped). `asgn` has no backing mechanism yet, so
      Procurement sees no quotation — fail-closed and a real functional gap, asserted by name in
      `QuotationRowScopeTest`. *Shipped in PR #91.*

- [x] **3.2** `EloquentQuotationDirectory::create()` writes Points 1.3/1.4's `quotation_items` and
      `quotation_additional_items` — one generic `writeChildren()`, batched with no Eloquent model
      exactly as Module 6's `writeLines()`, one `now()`, UUID ids, `DB-02`'s actor on every line.
      `line_no` is **positional (1-based)**, the one divergence from Module 6 whose item table has
      no such column; a user-orderable list stays on the debt register. The child rows arrive
      through the draft's new `withLines()` — priced by Step 2, never a caller's, because §5 puts
      all pricing in the backend. **No transaction here**; Point 3.3 owns it (`DB-11`).
      *Verified by* three tests in `EloquentQuotationDirectoryTest`: the priced lines reach
      `quotation_items` with their FK, actor and `line_no` 1/2; the additional items reach their
      table; a childless quotation writes no child rows. Each broken on purpose first — dropping
      `line_no` trips the NOT NULL, a constant `line_no` fails the order assertion, skipping one
      write empties one table alone.

- [x] **3.3** `CreateQuotation` — one transaction (`DB-11`): composes `PricedLine` + `QuotationTotals` + `RoundingRule`, captures the FX rate per line and the currency's rounding at creation, derives `tax_percent` from `customers.is_tax_exempt` (`D-63`), blocks on a missing price or FX rate (§5.6, `D-09`), warns on over-quantity, records `QUOTATION_CREATED`. Crossed four modules through named interfaces (`Admin`, `Audit`, `Customers`, a new `SupplierQuotationsContract`), not the one the note predicted. *(2026-09-11, #96 — built as one point by the owner's decision, not the 3.3b/3.3c split)*

- [x] **3.4** `POST /api/v1/quotations` — Form Request mirroring the tables' CHECKs and `DB-07`'s decimal-string triple, `permission:quotation.create`, `201` `{id, code}`, `422 business_rule_blocked` with `supplier_price_missing` / `fx_rate_missing` and `field = lines.N…`, `quantity_exceeds_recorded` in `meta.warnings`. The scoped create is applied to the **deal** through a new `DealFactsInterface` (`DealsContract`); `Quotations` also gained `IdentityContract` here, not in 3.5. *(2026-09-12, #97 — three owner rulings, see the Step 3 note above)*

- [x] **3.5** `GET /api/v1/quotations/{id}` — `find()` unscoped in the directory, `QuotationRowScope` applied in `ShowQuotation` to the deal's `owner_id` through Point 3.4's `DealFactsInterface` (no subquery on `deals`); `404 resource_not_found` for absent-or-invisible (§5.1), `team`/`asgn` fail closed; cost fields **absent** without `quotation.view_cost_and_margin` (slug confirmed by the owner 2026-09-12); `etag: quotation:<id>:<version_token>` (§9.2). *(2026-09-12, #100 — own = deal owner via the seam, not a join)*

- [x] **3.6** `PATCH /api/v1/quotations/{id}` — full editable body re-priced by §5 through `PriceQuotation` (lifted out of `CreateQuotation`, one implementation); `If-Match` missing/malformed → `400 invalid_request`, stale → `409 concurrency_conflict` with `current_etag` (`API-12`, never 412), the `UPDATE … WHERE version_token = ?` bumps the token; non-Draft → `422 business_rule_blocked` `quotation_not_draft`; `edit_margin`/`edit_tax` asked only when the body moves them; `QUOTATION_UPDATED` with old/new. *(2026-09-12, #101 — a Draft is re-priced at the FX rate effective at the edit, stated as an assumption)*

- [x] **3.7** `Idempotency-Key` on the POST (`OpenAPI §9.1`) — the owner ruled 2026-09-12 that the store is its own module, `app/Modules/Idempotency`: one table `idempotency_keys` UNIQUE `(user_id, route, key)`, claimed by `INSERT … ON CONFLICT DO NOTHING` before the use case runs and completed with the final status and body after; the `idempotency` route middleware runs **after** `permission:` so a replay re-checks the grant (§9.1); missing header → `400 invalid_request`, changed payload or key still in flight → `409 idempotency_conflict`; a 5xx releases the key. *(2026-09-12, #102 — quotations only; the retention period §9.1 calls "defined" is undefined, see the debt register)*

#### Step 4 — actions on one quotation *(point list approved 2026-09-12 with defaults Q1–Q6, #103)*

What §6 asks of a single quotation between the builder (Step 3) and the list (Step 5), and what
Modules 8, 9 and 10 will call rather than rebuild: the status graph, the two `OpenAPI §7.2`
actions the build plan puts under Module 7, `D-46`'s delete, and `D-36`'s price-drift warning.
Approve, return, send and the customer's response are **not** here — the Documentation Map files
them under Modules 8 and 10, and the delivery order holds. Nothing in this step touches
`resources/js`; `user_term_suggestions` (SmartTermInput) is the builder screen's table and waits
for the frontend step.

**Owner decisions this list needs — each names its default, and the default is what ships if the
owner says only "approved":**

- **Q1 · the returned quotation.** §6.4 draws `return with note ──► Draft (v2)`. Read as the same
  row going back to `draft` (the graph edge `pending → draft`, Point 4.1) with the copy being
  Module 8's call — *or* as a new version through Point 4.3, the source leaving `pending` by some
  edge the graph does not draw. **Default: the edge `pending → draft` exists; what Module 8 does
  with it is Module 8's list.**
- **Q2 · `submitted_at`.** `D-11`'s "days waiting" needs the moment a quotation entered `pending`.
  §6.2's Tracking group does not list it; the audit row carries it, but a list screen cannot read
  a partitioned audit table per row. **Default: add `quotations.submitted_at` in Point 4.2, set on
  submit, cleared on the way back to `draft`.**
- **Q3 · a new version's number.** `quotations.code` is UNIQUE (Point 1.1) and §4.7 numbers
  documents, so a copy cannot carry its parent's `QT-` code as the schema stands. **Default: a
  new version takes the next `QT-` number; the link is `parent_id` + `version`, as §6.3 says.**
- **Q4 · which statuses may open a new version.** §6.3 names Partial, Counter and Returned.
  **Default: `partial`, `counter`, `expired`** — the three where the document is finished with the
  customer and the deal continues (`J-01` produces `expired`); `rejected` archives and the deal is
  Lost (Module 10), `draft`/`pending` are still live, `sent`/`accepted`/`approved` are the
  customer's to answer.
- **Q5 · the delete route.** `D-46` and §3.5 grant "delete (Draft only)", and `OpenAPI §7.1`
  lists no `DELETE` for any resource — customers archive through `PATCH /archive`. **Default:
  `DELETE /api/v1/quotations/{id}` answering `204`**, recorded as a contract addition for
  `OpenAPI §7.1` rather than an `archive` action, because the document says delete and Module 10
  already owns "archive" for a rejected quotation.
- **Q6 · `Idempotency-Key` on submit.** §9.1 requires it for "actions that change
  irreversible-equivalent business state"; a submit is undone by a return, and `If-Match` (§9.2)
  already makes a repeated submit a `409`. **Default: `If-Match` only on submit and delete;
  `Idempotency-Key` on `new-version`, which §9.1 names ("versions").**

- [x] **4.1** `QuotationStatusTransition` — §6.1's nine statuses and §6.4's arrows as one edge
      table in `Domain/Status/`, on `DealStatusTransition`'s exact shape (`isAllowed`,
      `allowedFrom`): `draft → pending` · `pending → approved | draft` · `approved → sent` ·
      `sent → accepted | partial | counter | rejected | expired`; `accepted`, `partial`, `counter`,
      `rejected`, `expired` terminal — Partial and Counter continue through a **copy** (§6.3), not
      an edge. `QuotationWriteRefused` gains `invalidTransition(from, to)` → `409
      state_transition_invalid` (`OpenAPI §5.1`), the row `dealStatusTransitionRefused` already
      renders — one exception class per module's write refusals, no new renderer. Domain only:
      no route, no database. *Verified by* a unit test transcribing every row of the table, one
      asserting each terminal status has no edge, and one that `sent → draft` is refused.
      *(2026-09-12, #104 — edge table + 409 factory; no route until 4.2)*

- [x] **4.2** `PATCH /api/v1/quotations/{id}/submit-for-approval` — `permission:quotation.submit_for_approval`
      (§3.5: All / Team / Own / Own) with `QuotationRowScope` applied to the deal's owner as 3.4
      and 3.5 do; `If-Match` on 3.6's terms (`400` missing, `409 concurrency_conflict` stale);
      `draft` only through 4.1, anything else `409 state_transition_invalid`; the
      `UPDATE … WHERE version_token = ?` moves `status`, bumps the token and (Q2) sets
      `submitted_at`; audit `QUOTATION_SUBMITTED` with old/new status (`AUD-01`); `200` with
      3.5's `detail()` body and the new etag. No `Idempotency-Key` (Q6). *Verified by* the
      role matrix row by row including Team Leader fail-closed and Procurement/CEO `403`; a second
      submit with the old etag → `409 concurrency_conflict`; a submit of a `pending` quotation
      with a fresh etag → `409 state_transition_invalid`; the audit row; and the verifier broken
      by removing the 4.1 check.
      *(2026-09-12, #105 — `submitted_at` added; `QuotationEtag` + `QuotationWriteAccess` extracted from 3.6)*

- [x] **4.3** `POST /api/v1/quotations/{id}/new-version` — §6.3 / `D-08`'s "full copy": one
      transaction (`DB-11`) inserting a new `quotations` row with `parent_id = {id}`,
      `version = parent.version + 1`, `status = draft`, its own `QT-` code (Q3), every header
      field, every `quotation_items` and `quotation_additional_items` row **verbatim** — captured
      `unit_cost`, FX rate and rounding included, because the copy is the document the customer
      answered; the first `PATCH` on the copy re-prices at the edit (3.6), which is §10.3's
      "refresh". Accepted from Q4's statuses only, else `409 state_transition_invalid`; the source
      row is not touched. `permission:quotation.edit` with the row scope (whoever may edit the
      next draft); `Idempotency-Key` required (§9.1 "versions"), through 3.7's alias; audit
      `QUOTATION_VERSION_CREATED` carrying the parent id; `201 {id, code, version}`. The UNIQUE
      `(parent_id, version)` (Point 1.1) refuses a second copy of the same parent at the
      database. *Verified by* a copy whose `detail()` equals the parent's except id, code,
      version, status, etag and timestamps; a second `new-version` on the same parent →
      `409`; a `draft` parent → `409 state_transition_invalid`; the replayed `Idempotency-Key` →
      one copy.
      *(2026-09-12, #106 — `replicate()` minus the answer's marks; `23505` → `409 version_exists`; `store()` keeps `{id, code}`)*

- [x] **4.4** `DELETE /api/v1/quotations/{id}` (Q5) — `D-46`: `permission:quotation.delete`
      with the row scope, `If-Match` required, `draft` only else `422 business_rule_blocked`
      `quotation_not_draft` (3.6's reason, reused — a delete outside Draft is the same rule 3.6
      enforces, not a transition); soft-deletes the row and both child tables in one transaction
      (`DB-01`, no `forceDelete`); audit `QUOTATION_DELETED`; `204`. A deleted quotation answers
      `404 resource_not_found` on 3.5's read afterwards. *Verified by* the role matrix, a
      `pending` quotation refused with the row intact, the three tables' `deleted_at` set, the
      audit row, and the read returning `404`.
      *(2026-09-12, #107 — `204`; children soft-deleted in the same guarded transaction; `lockedRow()` now the one `version_token` guard)*

- [x] **4.5** `D-36` / §10.3's price-drift warning on the read — for a `draft` or `pending`
      quotation, `ShowQuotation` compares each line's captured `unit_cost` and currency with the
      supplier line's current price through the existing `SupplierItemPricingInterface` (3.3's
      seam, no new crossing) and lists each difference in `meta.warnings` as
      `{field: "lines.N", code: "supplier_price_changed", message}` — the same shape 3.4's
      `quantity_exceeds_recorded` uses; `sent` and beyond compare nothing (§10.3 "completely
      unaffected — fixed snapshot"). No "refresh prices" route: the button calls 3.6's `PATCH`,
      which re-prices at the edit and is Draft-only as §10.3 requires. *Verified by* a line whose
      supplier price moved after creation warning on `draft` and `pending`, the same line silent
      on `sent`, an unmoved line silent, and the key absent when nothing moved.
      *(2026-09-12, #108 — `lines.N.unit_cost`; gone line or unrecorded currency counts as moved; FX rate never compared, `D-09`)*

**What Step 4 leaves for its neighbours, named so nobody assumes it is here:** approve / edit &
approve / return with note and `D-50`'s `SELF_APPROVAL` (Module 8); send and the PDF (Module 9);
accepted / partial / counter / rejected and `J-01`'s expiry (Module 10); the list, its
`group_by` and §6.6's views (Step 5, which still needs the set-based owner seam
`ShowQuotation`'s `ponytail:` note records); the builder screen and `user_term_suggestions`
(the frontend step).

#### Step 5 — the list *(point list published 2026-09-12 on #110; approved by the owner the same day with defaults Q1–Q7)*

`GET /api/v1/quotations` — the endpoint the stub above names, §6.6's views for the Team Leader
and Manager, and the same list for a sales employee inside §3.5's `view` scope. `OpenAPI §6`'s
query contract in Domain first (Module 6 Step 4's shape: criteria → directory → use case →
route), then §6.6's two groupings. Nothing here touches `resources/js`: the toggle, the split
and "the system remembers the user's last choice" are the frontend step's, and the list answers
whatever that screen asks with `filter[]`/`group_by`.

**Owner decisions this list needs — each names its default, and the default is what ships if the
owner says only "approved":**

- **Q1 · "active quotations · history".** §6.6 splits every view in two and defines neither
  word. **Default: `active` = `draft | pending | approved | sent`, `history` = `accepted |
  partial | counter | rejected | expired`** — 4.1's terminal statuses are the history — served as
  `filter[bucket]=active|history` so the screen makes two requests and the server owns the
  definition. A quotation is in exactly one bucket.
- **Q2 · "employee".** §6.6 filters and groups by employee; the owner ruled on 2026-09-11 that a
  quotation's owner is its **deal's** `owner_id`, which is Deals' column. **Default: `employee` is
  the deal's owner, read through a set-based method on `DealFactsInterface`** (Point 5.1) — not
  `created_by`, and not a new column on `quotations`.
- **Q3 · "amount range".** A quotation carries one currency and no base-currency total, so a
  range over `final_total` across currencies ranks EGP against USD (Module 6 refused to sort
  `total_price` for the same reason). **Default: `filter[amount_min]` / `filter[amount_max]`
  apply to `final_total` and are accepted only together with `filter[currency]`; without it,
  `400 invalid_request`.**
- **Q4 · "period".** Nothing says which date. **Default: `filter[from]` / `filter[to]` on
  `quotation_date`** (§6.2's Core group), inclusive, ISO dates; `created_at` is a tracking field.
- **Q5 · `q`.** §6.6 lists no search box. **Default: no `q` on this list** — `OpenAPI §6.2` makes
  the allowlist the point; Module 15's Meilisearch step adds it if a screen asks.
- **Q6 · the row.** **Default: `QuotationSummary` as 3.4 answers it plus what §6.6's columns
  need** — `status`, `customer_id`, `deal_id`, `currency_id`, `final_total`, `quotation_date`,
  `valid_until`, `submitted_at`, `version`, `parent_id`, `created_at`, `updated_at` — and **no
  cost, margin or supplier field** (§3.5's `view cost & margin` is the detail's business, and a
  list that leaks it to a role without the grant is `SEC-07` broken at scale).
- **Q7 · the customer group's label.** Customers exposes `CustomerTaxStatusInterface` to
  this module and nothing that answers a name (checked 2026-09-12). **Default: the group's
  `label` is the `customer_id` and the name is the frontend step's lookup through
  `GET /api/v1/customers`** — the alternative, a `namesOf(list<string>)` on Customers' contract,
  is one more crossing for a label, and the screen already lists customers.

- [x] **5.1** The set-based owner seam — `DealFactsInterface::dealIdsOwnedBy(string $ownerId):
      list<string>` and `ownersOf(list<string> $dealIds): array<string, ?string>`, with the
      Eloquent implementation in Deals. The first answers "own" for the list (`WHERE deal_id IN`)
      and `filter[employee]`; the second answers `group_by=employee` for one page. Both read
      `deals` through the module's own model, `DB-01` soft-deleted deals excluded, on
      `factsOf()`'s terms. `ShowQuotation::one()` and `QuotationWriteAccess` keep `factsOf()` — a
      single-row read has no set to ask for; the `ponytail:` note on `ShowQuotation` is retired
      and the third scope-check copy the debt register names is **not** touched here (it is its
      own row). *Verified by* a feature test on the Eloquent adapter: owned ids only, a soft-deleted
      deal absent from both answers, an unknown id mapping to `null` in `ownersOf()`, and the
      empty list answering `[]` without a query. **Ceiling, stated:** `dealIdsOwnedBy()` returns
      an unbounded set — fine for one employee's deals, and the point to denormalise
      `owner_id` onto `quotations` is when a Manager's `filter[employee]` on a ten-thousand-deal
      owner is measured slow, not before. *(2026-09-13, #113 — unknown or soft-deleted id is absent from `ownersOf()`, not `null`; `null` is an unowned deal)*

- [x] **5.2** `QuotationListCriteria` · `InvalidQuotationListQuery` · `QuotationPage` in
      `Domain/Listing/`, on `DealListCriteria`'s exact shape (`fromQuery()`, `offset()`,
      `DEFAULT_PER_PAGE = 25`, `MAX_PER_PAGE = 100`). **Filters:** `status` (the nine of §6.1,
      repeatable), `bucket` (Q1), `employee` (Q2, a user id), `customer_id`, `deal_id`,
      `currency` (a code, as 3.4's request names it), `amount_min` / `amount_max` (Q3),
      `from` / `to` (Q4). **Sorts:** `quotation_date`, `created_at`, `updated_at`, `code`,
      `final_total` — the last accepted **only with `filter[currency]`**, Q3's reason. Default
      `-updated_at`. **`group_by`:** `employee | customer` (the stub's own line), nothing else.
      No `q` (Q5), no `include`. Everything outside these lists is
      `InvalidQuotationListQuery` → `400 invalid_request` (`OpenAPI §6.1`, §6.2 "never ignore
      them silently"), rendered by the row `ApiExceptionRenderer` already has for
      `InvalidDealListQuery`. *Verified by* a unit test transcribing every allowlist, one 400 per
      rejected shape (unknown filter, unknown sort, unknown group, `per_page=101`, `page=0`,
      `amount_min` without `currency`, `sort=final_total` without `currency`, `from` after `to`),
      and the default sort. *(2026-09-13, #114 — `q`/`include` answered `unknown_parameter`; `amount_min > amount_max` not refused, not in the list)*

- [x] **5.3** `QuotationDirectoryInterface::list(QuotationListCriteria, QuotationRowScope):
      QuotationPage` and its Eloquent implementation. The scope is applied **in the query**:
      `unrestricted` adds nothing; an `ownerIds` scope becomes `WHERE deal_id IN (…)` from 5.1's
      `dealIdsOwnedBy()` for each owner; `permitsNothing()` answers an empty page without a
      query (the read's rule, `OpenAPI §5.1`). `filter[employee]` intersects the same way. Rows
      are `QuotationSummary` (Q6) — `QuotationSummary` grows the fields Q6 names, `store()`'s
      `{id, code}` answer unchanged (4.3's lesson). `total` counted after scoping, before
      serialisation (`OpenAPI §6.1`). *Verified by* the feature test on the adapter: each filter
      alone, two together, the bucket split (a quotation is in exactly one), the `IN` scope
      (own sees own deals' quotations only; another owner's absent; a soft-deleted quotation
      absent), pagination arithmetic (`total`, `total_pages`, last page), and `-updated_at` by
      default. *(2026-09-13, #115 — `own` = two ANDed `IN`s on `deal_id`, `filter[employee]` can only narrow; unknown currency code = empty page; `create()` now `refresh()`es)*

- [x] **5.4** `ListQuotations::handle()` · `GET /api/v1/quotations` →
      `permission:quotation.view` with the row scope resolved from the held scopes, as
      `ListDeals::handle()` does. `OpenAPI §4.2`'s collection envelope with `meta.pagination`;
      `QuotationPayload::summary()` serialises Q6's row and **nothing from
      `QuotationLine::COST_FIELDS`** — the list never asks `revealsCosts()`, because it carries
      nothing that needs it. *Verified by* the endpoint test on `QuotationReadEndpointTest`'s
      fixtures (no new fixture copy — the debt row counts): 401; Manager sees every quotation;
      Own-scoped roles see their own deals' only; Team Leader an empty page (fail-closed);
      Procurement/CEO — §3.5's `view` cell — per the matrix; a withdrawn grant 403; every 400 of
      5.2 reaching the wire as `invalid_request` with the offending parameter in
      `error.details[0].field`; `per_page` default 25 and cap 100; `meta.request_id` present. *(2026-09-13, #119 — 5.2's 25 refusals reused via `DataProviderExternal`; `Payload::pagination()` now the 10th copy, the 2026-09-12 row's count of 8 is stale)*

- [x] **5.5** `group_by=employee|customer` — the same page, grouped server-side (`OpenAPI §6.2`
      "server-side grouping only"): `data` becomes `[{key, label, count, items: [...]}]` in the
      page's sort order within each group, groups ordered by `label`; pagination still counts
      quotations, not groups, so a page may open or close a group mid-way — **stated, not
      hidden**: §6.6's screen groups what it shows, and a group that spans pages is the price
      of `OpenAPI §6.1`'s bound on every list. `employee` groups by 5.1's `ownersOf()` (a deal
      with no owner groups under `null` / "Unassigned", the label from the lang file); `customer`
      groups by `customer_id` (Q7). *Verified by*
      the endpoint test: two employees' quotations land in two groups with the right counts; a
      customer group; an unassigned deal's quotation under the `null` key; `group_by=deal` →
      400; the ungrouped shape untouched when `group_by` is absent. *(2026-09-13, #120 — label = key, `null` → `quotations.groups.unassigned`; first production caller of `ownersOf()`; Catalog's flat `group_by` shape differs — debt row)*

**What Step 5 leaves for its neighbours:** the toggle, the two-panel split and the remembered
choice (the frontend step — `localStorage` per §6.6's "remembers", or a user setting if the owner
wants it to follow the user across devices: **a question for that step, not this one**); `D-11`'s
red badge and "days waiting" (Module 8's approvals screen, §6.4); `q` (Module 15); export.

#### Step 6 — the screens *(point list approved 2026-09-13 on #121 with defaults Q1–Q7)*

Module 7's frontend: the list §6.6 describes, the quotation itself, and the builder the build plan
names (`MVP §Module 7` "Frontend"). Everything Steps 1–5 shipped is consumed, nothing is
recomputed — the SPA "displays backend results and may preview; it never owns a calculation, a
permission decision, or a state transition" (`D-67`, `Coding Standards §11`). Every point that
draws a screen is verified in Claude Browser at desktop and mobile widths, Arabic (RTL) and English,
and the report lists the steps taken (`CLAUDE.md` "UI Verification"). House pattern is the Deals
screens: `services/<feature>.ts` + `pages/<feature>/`, `components/states/*` for the four states,
`useAuth().hasPermission()` for hiding what the API will refuse anyway (§3.12 "hiding a button is
not the same as blocking an action").

**What the read of the code found, so the list is honest about backend work it needs:**
- `api.ts`'s `request()` takes no headers and returns none; the SPA has never sent `If-Match` or
  `Idempotency-Key`, and never read `meta.warnings`. The builder needs all three (Points 3.6, 3.7,
  3.4, 4.5).
- `GET /supplier-quotations/{id}` returns lines **without `id`** — Module 6 Point 2.3 left it out
  because "nothing addresses a single line yet". A quotation line is `supplier_quotation_item_id`
  (Point 3.3), so the builder cannot pick a line the backend can price. One backend field.
- `user_term_suggestions` has no migration, no endpoint, no OpenAPI row — only its name in the
  build plan's table list and "SmartTermInput" in its frontend line.
- No endpoint answers "employee name for id" to a Team Leader: `GET /api/v1/users` is behind
  `admin.create_user`. Step 5's Q7 put the customer label on the frontend's `GET /customers`
  lookup; the employee label has nowhere to look.
- No preview endpoint exists. "Live calculation · confirmation preview before saving" (build plan)
  meets `D-67`'s "never owns a calculation".

**Owner decisions this list needs — each names its default, and the default is what ships if the
owner says only "approved":**

- **Q1 · "the system remembers the user's last choice" (§6.6).** **Default: `localStorage`, key
  `crm.quotations.view`, the `theme.ts` shape (try/catch, silent when storage is unavailable),
  remembering the toggle (`employee | customer | flat`) and nothing else.** Per-browser, not
  per-account: no settings endpoint exists, and `Design System §3.1`'s only stated per-account
  preference is the theme. A user setting is one endpoint plus one column if the owner wants the
  choice to follow the user across devices — say so and Q1 becomes a backend point.
- **Q2 · the employee group's label.** **Default: the server fills `label` for `group_by=employee`
  with the owner's display name through a `namesOf(list<string>): array<string, string>` on the
  Identity facts contract the Quotations module already depends on; `null` stays
  `quotations.groups.unassigned`.** This re-rules half of Step 5's Q7 on new evidence — the
  customer half stands (the screen lists customers anyway); the employee half cannot, because the
  only users endpoint is an admin's. The alternative, a read-only `GET /api/v1/users` for anyone
  with `quotation.view`, is a new route and a new `SEC-07` surface for a label.
- **Q3 · "confirmation preview before saving".** **Default: no preview endpoint. The builder saves
  the Draft, and the detail screen (Point 6.5) is the confirmation — the server's totals, the
  server's warnings, edit or delete one click away.** A Draft is free: `quotation.edit` and
  `quotation.delete (Draft only)` are the employee's own (§3.5), and `D-67` forbids the SPA the
  calculation a client-side preview would need. A `POST /quotations/preview` that prices without
  saving is not in `OpenAPI §7` — if the owner wants it, it is a new requirement (`CLAUDE.md`
  "Requirements Traceability") and one backend point.
- **Q4 · the supplier line's `id` on the wire.** **Default: expose it** — `SupplierQuotationLine`
  gains `id`, `SupplierQuotationPayload::detail()` writes it, `services/supplier-quotations.ts`
  reads it. Module 6's frozen `checklist/module-06.md` is not edited; Point 6.2 below records the
  change. The alternative — a Quotations-side endpoint listing priceable lines for a deal — is a
  second read of the same rows.
- **Q5 · `user_term_suggestions` and SmartTermInput.** **Default: the table is filled by the
  server, not the user — saving a quotation upserts `(user_id, field, term)` for `payment_terms`,
  `warranty`, `delivery_terms`; one read route `GET /api/v1/user-term-suggestions?field=`
  returns the caller's own terms, most recent first, capped at 20; the input is a native
  `<datalist>` (the house pattern in Catalog and Settings).** `Design System §6.3` says
  "reusable suggestions; suggestions never force a structured payment schedule" — a datalist
  cannot force anything. The route is not in `OpenAPI §7`: a new requirement, flagged here.
  Alternative: defer the whole thing to a debt row and ship plain textareas.
- **Q6 · builder as a page, not a modal.** Module 6's form is a modal; the quotation builder has
  header + up to 10 suppliers × lines + additional items + totals + terms. **Default: routes —
  `/quotations` (list), `/quotations/new`, `/quotations/:id`, `/quotations/:id/edit`** — the
  `DealDetailView` precedent, `Design System §4.3` "multi-column forms" at ≥ 1024px, cards under
  640px.
- **Q7 · cost and margin on screen.** §3.5's `view cost & margin` is granted to every role that
  can view a quotation. **Default: the SPA shows the cost fields when the detail carries them and
  nothing when it does not** — Point 3.4's `withCosts` already strips `QuotationLine::COST_FIELDS`
  for a caller without the grant; the SPA makes no permission decision of its own (`D-67`).

- [x] **6.1** The client — `services/quotations.ts`: `listQuotations(query)` building
      `page/per_page/filter[*]/sort/group_by` from Step 5's allowlist (empty filters omitted, the
      Deals rule), `readQuotation`, `createQuotation`, `updateQuotation`, `submitQuotation`,
      `createQuotationVersion`, `deleteQuotation`; TypeScript types for the 14-key summary, the
      grouped `{key, label, count, items}` shape, the detail with optional cost fields, and
      `meta.warnings` `{field, code, message}`. `api.ts`'s `request()` gains one optional
      `headers` argument so `If-Match` (`API-12`) and `Idempotency-Key` (`OpenAPI §9.1`) can be
      sent; the detail's `etag` is read from the body, where Point 3.6 put it. *Verified by* vitest
      on the query string per filter and on the two headers reaching `fetch`. No screen. *(2026-09-13, #122 — first `If-Match`/`Idempotency-Key`/204 in the SPA; `Page<T>` now exported from `api.ts`, six older copies are a debt row)*
- [x] **6.2** The supplier line's `id` (Q4) — backend: `SupplierQuotationLine::$id`,
      `SupplierQuotationPayload::detail()` writes `items[].id`; frontend:
      `SupplierQuotationLine.id` in `services/supplier-quotations.ts`, its doc comment corrected.
      *Verified by* Module 6's `GET /{id}` feature test asserting the id, and the existing SQ view
      spec still green. Recorded here because `checklist/module-06.md` is frozen. *(2026-09-13, #123 — `items[].id` first key; Module 6's `PATCH` still replaces the set)*
- [x] **6.3** The list — route `/quotations` behind `quotation.view`, nav item on the reserved
      `my-quotations` slot (`navigation.ts:24`, badge count is Module 8's), table on the summary's
      columns (`code`, `version`, `status`, customer, `final_total` with its currency code,
      `quotation_date`, `valid_until`, `updated_at`), §6.6's six filters (`status`, period =
      `from`/`to`, `employee`, `customer_id`, `currency`, `amount_min`/`amount_max` — the amount
      pair disabled until a currency is chosen, Step 5 Q3), sort on the five allowed fields,
      server pagination, the four states plus `PermissionDeniedState` on 403. Customer names via
      `listCustomers({perPage: 100})` (Deals' ceiling, id as fallback); status as text with the
      `Design System §6.4` badge colour, never colour alone. Flat list only — the toggle is 6.4.
      *Verified by* vitest on the query string per control and on each state; Claude Browser at
      desktop + mobile, ar + en. *(2026-09-14, #124 — owner's ruling A: rows and the detail carry `currency` (ISO code) beside `currency_id`, resolved server-side; no employee filter — no name lookup exists for a non-admin, 6.4's employee groups carry the server's label instead; `badge` left unset until Module 8 counts)*
- [x] **6.4** §6.6's views — the toggle **by employee · by customer · flat** at the top
      (`group_by`), the fixed **active · history** split in every mode (two requests,
      `filter[bucket]`, Step 5 Q1), grouped rows rendered from `{key, label, count, items}` with a
      group heading row (Catalog's `<th scope="colgroup">` precedent), and the remembered choice
      (Q1). Pagination counts quotations, so a group may continue on the next page — the heading
      says so. *Verified by* vitest: the stored choice restores the toggle, an unavailable
      `localStorage` falls back to flat; Claude Browser as above. *(2026-09-14, #125 — Q2's employee label is the server's: `UserFactsInterface::namesOf()` under `Identity/Domain/Contracts`, `EloquentUserFacts` withholds the hidden Super Admin so its group shows the key; the customer group is named on the screen from the same `listCustomers` lookup; one refusal for both buckets, one 403 being one permission)*
- [x] **6.5** The quotation — route `/quotations/:id` on `readQuotation`: header (customer, deal,
      status, version, dates, currency), lines with the cost columns present only when the body
      carries them (Q7), additional items, the totals block in `Design System §7.2`'s groups
      (subtotal · additional · discount · tax base · tax · rounding · final) with **no tax row
      when `tax_amount` is null** (`D-63`) and a rounding row only when `rounding_enabled`
      (`D-65`); `meta.warnings` `supplier_price_changed` as the red line warning `Design System §7.2` names
      (`D-36`); the version chain — `parent_id` link and "new version" (`POST /new-version`,
      `Idempotency-Key`, Draft opens in the builder); actions by status and grant: **Edit**
      (Draft, `quotation.edit`), **Submit for approval** (Draft, `quotation.submit_for_approval`,
      `If-Match`), **Delete** (Draft, `quotation.delete`, confirm dialog `Design System §6.6`);
      `409` → a "changed by someone else — reload" banner, never a silent retry (`API-12`). Approve,
      return, send, PDF are Modules 8–10 and are **not** drawn. *Verified by* vitest per action and
      per state; Claude Browser as above. *(2026-09-14, #126 — **merged on local gates by the owner's word: GitHub Actions refused to start (billing), so no CI conclusion exists for `9650bf8`** — `QuotationDetailView.vue`; the list's code is now the link in; a 409 `concurrency_conflict` is the reload banner, any other refusal the server's `message`; new version opens the copy's page until 6.7 registers the builder; Edit links `/quotations/:id/edit` for 6.7; the supplier behind a line is not named)*
- [x] **6.6** The builder, create — route `/quotations/new?deal=` (from the deal, `Design System
      §2.1` "empty state with the permitted next action" on the deal's page is where the link
      lives), header fields (`currency`, `default_margin`, `discount_percent`, `tax_percent`
      nullable = exempt, dates, terms as plain textareas until 6.8), suppliers via (+) up to 10
      (§6.1): each picks a supplier quotation (`listSupplierQuotations({dealId})`, then any) and
      lists its lines from `readSupplierQuotation` (6.2's ids), `quantity` and an optional line
      `margin_percent` per line; additional items (`description`, `amount`); the button atop the
      supplier-item list that jumps to Module 6's form and returns (owner's ruling 2026-09-11,
      debt-register row); `POST /quotations` with `Idempotency-Key = crypto.randomUUID()` minted
      once per form open (a retry replays, a new form mints anew); `422 supplier_price_missing` at
      the line it names (save blocked, `§5.6`), `meta.warnings` `quantity_exceeds_recorded` inline
      red on the line without blocking (`§5.6`), `fx_rate_missing` at the form; on 201 → 6.5 as the
      confirmation (Q3). Money stays strings, `inputmode="decimal"` (`DB-07` on the client side:
      no `Number`). *Verified by* vitest on the payload shape, both warning paths, the blocked save,
      the idempotency header; Claude Browser as above, including the 10-supplier cap. *(2026-09-14, #127 — `QuotationBuilderView.vue`; `customer_id` comes from `readDeal`; both `QuotationNotPriceable` codes arrive on `lines.N.supplier_quotation_item_id` — `supplier_price_missing` shown at that line, `fx_rate_missing` at the form; a 201 that carries `quantity_exceeds_recorded` keeps the form read-only with the red line and a link to the draft, because a `GET` never repeats that warning; a clean 201 goes to 6.5; Module 6's modal is mounted for "new supplier quotation" and its `saved` offer becomes the next block — its `deal_id` is not pre-filled (Module 6's prop surface, not this point's); `NoHardCodedTextTest` list; deal page link behind `quotation.create`)*
- [x] **6.7** The builder, edit — `/quotations/:id/edit` for a Draft the caller may edit: 6.6's
      form loaded from the detail, `PATCH` with `If-Match: <etag>` and `lines`/`additional_items`
      always present (Point 3.6's "an edit replaces every editable field"), `409 stale_version` →
      reload banner with the newer version's totals, `quotation_not_draft` → back to 6.5; unsaved-
      change warning (`Design System §5.2` form/builder). *Verified by* vitest on the header, the
      409 path and the dirty check; Claude Browser as above. *(2026-09-14, #128 — same `QuotationBuilderView.vue`, `editing` by route name; a line names only its `supplier_quotation_item_id`, so existing lines are edited as 6.5's flat list (line no, cost, quantity, margin, remove) and new lines come through a supplier block — naming the product on a line is one backend field for 6.5 and 6.7 alike, debt row; a non-Draft on load or `quotation_not_draft` on save goes to 6.5; the 409 banner re-reads and names the newer total, Reload replaces the form; `onBeforeRouteLeave` + `window.confirm` for the dirty check, `beforeunload` for the tab; 6.5's new version now opens the copy in the builder)*
- [x] **6.8** SmartTermInput (Q5) — backend: migration `user_term_suggestions`
      (`user_id`, `field`, `term`, `last_used_at`, UNIQUE on the three, `DB-01` columns,
      `down()`), upsert inside the quotation save transaction for the three term fields,
      `GET /api/v1/user-term-suggestions?field=payment_terms|warranty|delivery_terms` behind
      `quotation.create`, own rows only; frontend: `<datalist>` on the three textareas of 6.6/6.7
      fed by that route. *Verified by* a feature test (own terms only, unknown `field` → 400,
      upsert on repeat), vitest on the datalist; Claude Browser as above. *(2026-09-14, #129 — `<datalist>` cannot attach to a `<textarea>` (HTML `list` is an `<input>` attribute), so the owner ruled **chips**: the caller's recent terms as buttons under each textarea, a click copies one in; `TermSuggestions` upserts inside both save transactions and reads own rows only, `term` capped at 500 chars (longer is skipped, never a failed save), the read answers `OpenAPI §4.2`'s paginated envelope as one page of 20; unknown `field` is the list's `400 invalid_request`; registered in `AuditEnforcementTest::WRITERS` as a non-business write)*

**What Step 6 leaves for its neighbours:** the approvals queue, `D-11`'s red badge and "days
waiting", the yellow "Self-approved" badge and the `my-quotations` badge count (Module 8); the
PDF button and preview (Module 9); send, the customer's response, Partial/Counter/Returned copies
(Module 10); `q` (Module 15); the Arabic manual test list that closes the module (after 6.8).

---

