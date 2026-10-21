> Frozen history of Module 14, cut verbatim from `CHECKLIST.md` on 2026-10-22 (commit `da050cb`).
> Open boxes here are stale copies — the live ones are in `CHECKLIST.md`. Do not tick here.

## Module 14 — Dashboard

> As a Manager, I want to see sales and profit for a period, so that I can track performance.

**Tables** summary tables (pre-aggregation) — job J-09

**Acceptance criteria**
- [x] Selected period → figures match the underlying data exactly *(2026-10-21 — each figure against its own source rows: `DashboardOverviewEndpointTest`, `DashboardMoneyEndpointTest` incl. a month equal to RPT-MN's `wonMoneySentBetween`, `DashboardSalesEndpointTest`, `DashboardBreakdownsEndpointTest`; J-09's rows against the records: `RefreshDashboard*Test`)*
- [x] Two currencies → each shown separately, aggregation an optional toggle *(2026-10-21 — per-currency rows and the base-currency `combined`, unconverted counted: `DashboardMoneyEndpointTest`; the toggle: `DashboardView.spec` "combines into the base currency on request"; `D-149`)*
- [x] CEO → **no drill-down** on any figure *(2026-10-21 — the CEO holds no `dashboard.drill_down` (§3.13): `DashboardMetaEndpointTest`; no figure is a link without it: `DashboardView.spec` "draws no link without drill-down"; drill-down opens the existing lists under their own scopes, so it never widens what a role can read)*
- [x] Sales by Area stays behind a feature flag — *answered by `D-145`: left out of the MVP (no flag system; a flag that is off is the same as not built); debt row below*
- [x] ❌ Not in MVP: Outstanding Payments · Cheques Due *(nothing built for them, `D-25`)*

### Point list — approved 2026-10-21 (mohanadmm, in chat; `D-143`)

Each slice runs database → API → screen and ends in something the owner can open; each is a red
point (failing tests, failing for the right reason) then a green point with its evidence.

**Step 0 — Decisions and bookkeeping (docs only)**
- [x] **0.1** Order and ownership: `D-143` (Module 14 next, alongside Module 13; amends `D-125`'s order), this section's point list, the ownership row, the four agent guides *(2026-10-21, #406 — D-142 was taken by #405, so D-143)*
- [x] **0.2** The spec as decisions: the metric definitions, the role matrix, `dashboard.view` / `dashboard.drill_down`, freshness and the go-live data window, the empty Procurement and Outdoor tabs, the deferred widgets *(2026-10-21, #407 — D-144 the figures, D-145 who sees what, §3.13 the two permissions)*
- [x] **0.3** Where the code lives: a `Dashboard` module beside AP-02's twelve, its deptrac ruleset reading other modules' contracts only; J-09 on `maintenance` (§15.1: aggregates), no startup catch-up (§15: "❌ recomputes") *(2026-10-21, #408 — D-146; 2.1's catch-up struck, §15 rules it out)*
- [x] **0.4** Lost reasons: `deals.lost_reason` is free text, so "lost deals by reason" cannot be grouped — the owner's decision, before slice 5 *(2026-10-21, #409 — D-147: a managed list on the Rejected response; point 5.0 added)*

**Slice 1 — the dashboard opens per role, no figures yet**
- [x] **1.1** Red: `dashboard.view` All/Team, `dashboard.drill_down`, Sales roles 403; tabs per role; period default (current month) and remembered choice; the empty-state text *(2026-10-21, #410 — 12 PHP + 9 vitest red for the right reason; tabs derived from permissions, never role names)*
- [x] **1.2** Green: permissions migration, module skeleton + deptrac, the meta endpoint, the `dashboard` route and page (tabs, period picker, empty tabs, loading/empty/error, RTL/LTR, 375 px) *(2026-10-21, #410 — no migration: the seeder, as `catalog.import`; the meta carries the company's `today`; full suite 4275 passed; browser check NOT done — the in-app browser refused localhost)*

**Slice 2 — summary tables, J-09, the Overview counts**
*Split in four by mohanadmm, 2026-10-21, at 2.1's restatement: J-09 and its tables first, then the Overview.
Self-approvals are dated by their `SELF_APPROVAL` audit entries (no `approved_at` column, nothing added to
Quotations); J-09 reads Deals, Customers and Quotations through new read-only contract methods, named in the PR.*
- [x] **2.1a** Red: J-09 — deals by creation day, current owner and current status; customers by creation day and current sales owner; self-approvals by `SELF_APPROVAL` day and approver; pending and returned quotations "now" by owner; the go-live window; idempotent; hourly rebuilds touched days only; nightly full rebuild repairs; scheduled hourly and nightly on `maintenance`, no catch-up entry (§15 "❌ recomputes", `D-146`) *(2026-10-21, #412 — 10 red for the right reason, the catch-up guard green by construction)*
- [x] **2.1b** Green: the summary tables and `dashboard_refreshes`, the contract readers, J-09 and its schedule *(2026-10-21, #412 — read-only methods added to Deals, Customers, Quotations and Audit contracts; full suite 4295 passed; migration up/down/up clean)*
- [x] **2.2a** Red: `GET /dashboard/overview` — each count equals a direct query of its definition for the period; Team scope (`D-138`); the "now" figures; `last_updated_at` from the last refresh; the tiles; "Last updated" amber after 2 h *(2026-10-21, #412 — 13 PHP + 6 vitest red for the right reason)*
- [x] **2.2b** Green: the Overview endpoint and tiles *(2026-10-21, #412 — 34 dashboard PHP + 16 page tests green; full suite 4310 passed)*

**Slice 3 — money: revenue, profits, the Finance tab**
- [x] **3.0** Two decisions the data forces, proposed for the owner's approval at merge: `D-148` (delivery cost = the won versions' additional items — they carry no type) and `D-149` (base figures at the rate in force when each version was created — a quotation stores no base amount) *(2026-10-21)*
- [x] **3.1** Red: revenue, gross and net profit, margin, delivery cost against `D-141`; a month equals its RPT-MN; the combine toggle sums `base_amount`; no money keys for the TL or without `view_cost_and_margin`
- [x] **3.2** Green: the money summary, the Overview money tiles, the Finance tab (monthly bars, margin, revenue by currency, delivery cost) *(2026-10-21 — 3.1 red: 13 PHP + 3 vitest; 3.2 green: the version-grain projection, a month equals RPT-MN, full suite 4325 passed)*

**Slice 4 — Sales tab I: pipeline figures**
- [x] **4.0** `D-150`, proposed for the owner's approval at merge: per-employee figures follow `dashboard.drill_down` (the CEO has none); Won and Lost dates from `DEAL_STATUS_CHANGED` audit entries *(2026-10-21)*
- [x] **4.1** Red: funnel, conversion rate, win/loss rate, average sales cycle, average deal size, live workload
- [x] **4.2** Green: the endpoint and the Sales section, the SVG funnel, the rate tiles, the workload table *(2026-10-21 — 4.1 red: 8 PHP + 3 vitest; 4.2 green: outcomes from DEAL_STATUS_CHANGED, full suite 4335 passed)*

**Slice 5 — Sales tab II: rankings and breakdowns**
- [x] **5.0** Lost reasons as a managed list (`D-147`), in Quotations (Module 10) and Deals (Module 5), with their owner told first: `lost_reasons` seeded; `quotations.rejection_reason_code` and `deals.lost_reason_code`, with the CHECK loosened for Rejected only (Counter's text still required, the note required on `other`); existing rows backfilled to `other`; the Rejected response form takes the code; `quotationRejected` carries it to the deal; red first, both migrations reversible *(2026-10-21 — in Quotations and Deals; the DB accepts a code or a text so history stays valid, the API requires the code)*
- [x] **5.1** Red: by employee (Manager money + count, TL count), by sector, best/worst products, top 20 customers, lost by reason; the CEO gets none of §12.4's hidden figures
- [x] **5.2** Green: product and customer summaries, the endpoint, the ranked tables *(2026-10-21 — 5.1 red: 8 PHP + 2 vitest; 5.2 green: line projection via the supplier line's product; full suite 4351 passed)*

**Slice 6 — drill-down**
- [x] **6.1** Red: each figure opens its list with the right filters and the list's own scope; CEO: no links, 403 *(2026-10-22 — 4932154: Deals `filter[created_from|created_to|progress]`, Customers `filter[created_from|created_to]`, 400 on a bad value; DealsView/CustomersView carry them; Overview tiles, funnel stages and workload rows link only with `drill_down`; red for the right reason)*
- [x] **6.2** Green: Deals and Customers date-range filters (only Quotations has `from`/`to` today), the links *(2026-10-22 — creation day read in the company zone (`CompanyToday`, deptrac `AdminContract` for Deals and CustomersDriver); progress closed = `won` and later, or `lost`; one «من لوحة المتابعة» chip with «مسح» on each list; full PHP suite 4355 passed alone (31382); vitest 1553; pint, phpstan, deptrac ×2, vue-tsc green; browser not opened by the agent)*

**Slice 7 — closing**
- [x] **7.1** Every criterion ticked with its test; the manual test list; the debt rows; the browser check ar/en × 1280/375 × light/dark; the module archived to `checklist/module-14.md` *(2026-10-22 — criteria ticked with evidence, debt rows added, the manual test list given to the owner in Arabic; browser check by the owner and by the agent through a local http proxy: Manager and CEO, ar/en × 1280/375 × light/dark, all five tabs, every Overview, funnel and workload link, the Deals and Customers chips and their «مسح», no horizontal scroll at 375; the CEO sees four tabs and no link; one defect found and fixed in `da050cb` (the chip's raw ISO days, reordered in Arabic); vitest 1553; archived to `checklist/module-14.md`)*
