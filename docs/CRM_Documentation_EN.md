# CRM System — Master Documentation

> **Status:** Final — ready for sign-off · August 2026
> **Supersedes:** all previous drafts and appendices
> **Companion file:** `MVP_Build_Plan_EN.md`
> **Arabic:** reading-only translations live in `arabic/`. They are not maintained companions and carry no requirement (D-58).

---

## Table of Contents

| # | Section |
|---|---|
| 1 | Overview |
| 2 | Decision Log (68 decisions) |
| 3 | Roles & Permission Matrix |
| 4 | Data Model |
| 5 | Pricing Rules |
| 6 | Customer Quotations |
| 7 | Suppliers & Catalog |
| 8 | Screens by Role |
| 9 | Workflows |
| 10 | Edge Cases |
| 11 | Reports |
| 12 | Dashboards |
| 13 | Super Admin Screens |
| 14 | Architecture & Technical Requirements |
| 15 | Scheduled Jobs |
| 16 | Operations, Backup & Monitoring |
| 17 | Files & Attachments |
| 18 | Notifications |
| 19 | Open Decisions & Risks |
| 20 | Post-MVP Backlog |

---

## 1. Overview

| Item | Decision |
|---|---|
| System type | CRM for the Sales and Procurement teams |
| Future path | Expansion into a full ERP (inventory · accounting · HR · production) |
| Hosting | Physical server on company premises (on-premise) |
| External access | Cloudflare Tunnel + Access, limited to 5 named users (D-59) |
| Expected users | Tens → hundreds (internal only) |
| Languages | Arabic + English from day one (RTL / LTR) |
| Devices | Desktop for office staff · Mobile (PWA) for the field team |
| Operating mode | Online only — no offline mode |
| Working days | Sunday → Thursday |

---

## 2. Decision Log

Everything else in this document rests on these. Any future change is **recorded as a new decision here**, never as a silent edit to the prose.

### 2.1 Structure & Pricing

| # | Decision |
|---|---|
| D-01 | **A deal is a standalone entity** — not a status field on the customer. One customer can have several concurrent deals |
| D-02 | **A request IS a deal** — one entity, not two. The "Customer Requests" screen is the deals list |
| D-03 | Profit margin applies at **both quotation level and line level** |
| D-04 | **Selling price = supplier cost + margin** (calculated automatically) |
| D-51 | **Supplier quotation is a standalone entity**, optionally linked to a deal (reusable) |
| D-21 | **Prices live on the supplier quotation** — the catalog holds descriptive data only |

### 2.2 Customer Quotations

| # | Decision |
|---|---|
| D-05 | Two statuses added: **Approved** and **Expired** (9 total) |
| D-06 | Rounding applies to the **final total only** — and rounding itself is **optional per currency** (`D-65`) |
| D-52 | **Rounding unit is per currency**, configurable (EGP = 1 · USD/EUR = 0.01) — and rounding can be switched off entirely for a currency (`D-65`) |
| D-07 | Discount is a **percentage of the `subtotal` only** (the sum of line totals, per 5.2) — never applied per line or to additional items. It is subtracted **before** tax, so it reduces the tax base (`D-64`) |
| D-08 | Partial acceptance = **full copy + manual edit** |
| D-09 | Quotation is issued in **one currency**, with automatic conversion at the FX rate captured at creation |
| D-10 | **No approval threshold** — Team Leader and Manager have identical authority |
| D-11 | **No automatic escalation** — the quotation waits; a red badge is shown |
| D-50 | **Self-approval is allowed** with explicit tracking |
| D-26 | Payment terms are **free text** for now; a structured payment schedule comes later |

### 2.3 Deals & Customers

| # | Decision |
|---|---|
| D-49 | **Customer status is derived automatically** — any Won deal makes them a "Customer" permanently |
| D-12 | Customer purchase order = **attachment + number + date** |
| D-53 | The PO carries **two numbers**: internal and the customer's own reference |
| D-13 | Purchase order to the supplier is **deferred to post-MVP** |
| D-14 | **Delivery Complete** can be confirmed by any of: Procurement · Sales owner · Team Leader · Manager (with attribution) |
| D-15 | **Follow-up is deferred** to post-MVP |
| D-16 | Communication history = **free-text notes** |
| D-17 | The "stale deal" threshold is **configurable in settings** |
| D-18 | **One contact person** per customer |
| D-35 | Similar customer names → **warning only**; the employee decides |
| D-34 | Accounts are deactivated, never deleted — deals stay attached, and the Team Leader reassigns them |

### 2.4 Suppliers & Catalog

| # | Decision |
|---|---|
| D-19 | Colour rating is set **manually by any employee** |
| D-20 | **Regions are free text** with auto-suggestions |
| D-22 | New products are **added to the catalog automatically, without review** |
| D-45 | Catalog and suppliers are **open for creation and editing by any employee** |
| D-36 | Supplier price change → the existing quotation keeps its price + a **warning** |
| D-37 | A deactivated product stays usable in open quotations with a warning, but is hidden from new ones |

### 2.5 Permissions & Visibility

| # | Decision |
|---|---|
| D-32 | **Full dynamic RBAC** from the start |
| D-41 | **Procurement sees everything** — cost, margin and profit |
| D-42 | **Sales can see and edit** cost and margin on their own quotations |
| D-43 | **The CEO sees all financial data** — read-only and without drill-down |
| D-24 | **CEO = read-only** with a single exception: commenting on a report |
| D-44 | The Outdoor Supervisor is confined to the **outdoor scope (`Out`)**: visits, visit customers, and their team's deals **up to handover**. Within that scope they may create, edit and change deal status (3.4), but never approve/reject a request, assign an owner, or confirm delivery. **After handover to the Team Leader the deal is no longer visible to them** |
| D-46 | An employee may delete their own quotation only in **Draft** — as a soft delete |
| D-38 | Attachment permission = **permission on the parent entity** |

### 2.6 Reports & Timing

| # | Decision |
|---|---|
| D-23 | The Accounts department sits **outside the system** — manual PDF export, delivered on paper |
| D-25 | **Outstanding Payments and Cheques Due are out of MVP scope** |
| D-27 | Reporting cycles are **system-wide**, counted from the go-live date |
| — | Working days **Sunday → Thursday** · deadlines and SLAs come from settings |

### 2.7 Security & Files

| # | Decision |
|---|---|
| D-28 | Password: **8 characters minimum, letters and numbers** |
| D-29 | Session expires after **8 hours idle** |
| D-30 | Audit log is **retained permanently** — never deleted |
| D-39 | Maximum file size **10 MB** (configurable) |
| D-40 | Allowed types: **PDF · images · Word · Excel** |

### 2.8 Operations & Scope

| # | Decision |
|---|---|
| D-31 | Excel import accepts **incomplete data** (records flagged "incomplete") |
| D-33 | MVP includes: Customer Requests · Outdoor Visits · Reports — the notification centre is deferred |
| D-47 | MVP success criterion: **80% of quotations** produced in the system within one month |
| D-48 | **Meilisearch comes last** — behind an abstraction layer built on day one |
| D-54 | **Restart recovery** is the system's responsibility; hardware and UPS are out of scope |
| D-55 | **Missed jobs run on startup** (catch-up) |
| D-56 | External integrations (WhatsApp · AI · Mapbox · Outlook) are **formally deferred** behind feature flags |
| D-82 | **Display precision is fixed per kind of value, separately from storage** (recorded 2026-09-21, proposed — awaiting the owner's approval at merge; the owner's F-06 ruling). `D-68` fixes what is stored and `OpenAPI` publishes it as stored; neither says what a person sees, and today every screen prints the stored string — `1000.000000` for a price, `66.0000` for a quantity — which reads as noise and misaligns columns. So the SPA shows money and quantities at three decimal places and leaves percentages (`NUMERIC(6,3)`) and FX rates (`NUMERIC(18,8)`) exactly as stored. How: display-only, by cutting the server's decimal string after its third fractional digit — a string operation, never `Number()`, never rounding, never arithmetic (`DB-07`, `D-06`); a string with fewer than three decimals is shown unchanged (no digit is invented — and no such value exists today, since `D-68` guarantees four or six). One formatter in `crm/resources/js`, applied to every rendered money or quantity figure, including placeholders and interpolated `t()` parameters — the builder's quantity placeholder and muted line (F-04, F-05 · 1.6) and the offer editor's recorded · consumed · available line are quantity figures like any other. What it never touches: an input's value — `v-model` fields hold and send exactly what was typed or loaded (Design System §6.3 "format only for display"); the API payloads; the database. Where a screen said "digit for digit" (`SupplierQuotationsView`, F-04's placeholder note), that invariant moves one step: the input stays digit-for-digit, the display is cut at three. The customer PDF is not covered: Module 9 has no template yet (`app/Modules/Pdf` is empty on `main`; #118's view DTOs carry plain strings and only its test fixture uses two-decimal literals). The PDF's audience is the customer, and its places are Module 9's own decision, to be recorded when its template exists; this row neither requires nor forbids that it match the SPA. |
| D-83 | **A quotation resolves its customer's name through Customers' contract, not a second list request** (recorded 2026-09-21, proposed — awaiting the owner's approval at merge; the owner's F-07 report). **Reverses Module 7 Step 5 Q7** (`checklist/module-07.md:300-304`), which chose the frontend lookup through `GET /api/v1/customers` and declined `namesOf(list<string>)` as "one more crossing for a label"; the screen has since proved the lookup unreliable, so the declined alternative becomes the rule. The stated cause — a customer past the hundredth of `listCustomers({ perPage: 100 })` — is **not** the cause: measured 2026-09-21, the SPA sends no `sort`, the server falls back to `orderBy('customers.id')` (UUIDv7, id-ascending), and all four customers owning quotations sit at positions 9, 13, 19 and 95 of 234. The cap is a real but latent ceiling. Four mechanisms break the name and one port closes all four: the cap; `loadCustomers`' `catch` that empties the map so any failure degrades every row at once, deliberately, so a 403 does not error a list that loaded; the customer directory's **unconditional** `is_archived` filter, which makes an archived customer's name permanently unresolvable while the system archives rather than deletes (`DB-01`); and the scope split of `§3.3`, where `customer.view` is `own`/`asgn`/`out` while `quotation.view` is scoped separately, so a caller may legitimately read a quotation whose customer is outside their customer scope. Customers therefore exposes `namesOf(array<string> $ids): array<string,string>`, Quotations' list use case calls it once per page — one query for N ids, never N queries — and the row and the customer group's label carry the name. **Name only**, never another customer field, so a caller permitted a quotation is not thereby granted customer data. `CLAUDE.md`'s ban on cross-module table reads is unchanged: this is an interface, not a join. The pattern already exists in this repository — the roles matrix needs every permission and uses `allPages('/permissions')` rather than a capped page. `DealsView` makes the identical capped call and carries the identical defect; F-07 · 1.5 either extends the port to it or registers it as debt with its reason. |
| D-84 | **A customer dropdown searches the server instead of listing a capped page** (recorded 2026-09-21, proposed — awaiting the owner's approval at merge; the owner's F-08 report). The quotations screen's customer filter and the deal form's customer picker each filled a `<select>` from one `listCustomers({ perPage: 100 })`; `MAX_PER_PAGE` is 100 and a larger page is a `400`. Measured 2026-09-21: 234 working customers, the page sorted by name (`CustomerListCriteria::DEFAULT_SORT`), so every Latin name sorts before every Arabic one, and 10 of the 18 distinct names never appear — among them `المركز القومي للمرأة`, which owns 8 quotations; neither dropdown can choose it. Design System §6.3 says "Search only when the option volume needs it", and this volume needs it. So one shared component, `CustomerPicker`, replaces both selects. On open it shows the first 20 working customers by name; as the user types, after a 300 ms pause, it asks `GET /customers?q=`, which "always passes through `SearchService`" (`OpenAPI_Contract` §6.2), for 20 results, with a line saying more exist when the total is larger. Each result shows the name and a muted line from whichever of region, contact person and phone the row has; no customer code. Archived customers are never offered (the server's default). Refused (`403`), empty by scope, no match, and a failure with a retry each get their own line, and the rest of the screen keeps working. The picker holds the chosen customer's id and shows the name of the row the user picked; an id set from outside with no picked row is shown as the id (D-83's fallback) — no screen does this today. The filter shows «كل العملاء» first and a clear button, either of which empties the choice; the form shows «اختر العميل» as its placeholder and has no clear button, because the field is required. It follows the WAI-ARIA combobox pattern. `loadCustomers` leaves both screens: the F-07 · 1.4 and 1.5 rulings kept it only because the filter and the picker still read it, and once they load their own results nothing reads it. The picker reads `/customers` under the caller's own `customer.view` scope, so no new permission is involved and no customer field is exposed beyond what that list already sends; `D-83` is unchanged — rows keep the name the server sends. **Measured correction to D-83's evidence (its conclusion is unaffected):** D-83 says the list falls back to `orderBy('customers.id')` and puts the quotation owners at positions 9, 13, 19 and 95; the measured order is by name, and `المركز القومي للمرأة` first appears at position 183. **Not covered:** an archived customer cannot be chosen in the quotations filter, so its quotations cannot be filtered by customer (its rows still carry the name, D-83); on open the first 20 by name are all Latin in today's data, so an Arabic customer appears only after typing; Arabic search folds أ إ آ ٱ → ا, ة → ه and ى → ي (`ArabicNormalisation`) and matches by substring, so a standalone ء is not folded and a word written with an attached `لل` needs its stem typed (`مرأة`, not `المرأة`); the supplier-quotations screen's one capped `listSuppliers({ perPage: 100 })` (`SupplierQuotationsView.vue:146`) is registered as debt — it feeds the rows' supplier names, the supplier filter and the form's supplier picker; the filter and picker take this component made generic when a second case is ordered, and the names need a supplier names port as D-83 gave customers. |
| D-81 | **A supplier line's quantity is an available balance, not only an offered amount** (recorded 2026-09-16, **proposed — awaiting the owner's approval at merge**; the owner's F-05 ruling, questions Q0–Q7 answered 2026-09-16). §7.2 lists a line's `quantity` and says nothing more; §5.6 and Design System §7.2 read it as a ceiling that warns without blocking. The owner reads it as a balance. So `supplier_quotation_items` gains `consumed_quantity NUMERIC(14,4) NOT NULL DEFAULT 0` (`D-68`); `quantity` stays the supplier's original offer and is never edited (§7.2). **Available = `quantity − consumed_quantity`**, computed in the backend and published beside both on the supplier-quotation payload. **When:** once per line of the accepted customer quotation, at its `sent → accepted` transition (`QuotationStatusTransition`) — the transition that names the winning lines itself; the deal's `Won` does not, since a deal keeps every version. That transition belongs to Module 10, so the consuming call is built there; F-05 builds the column, the contract, the warning and the screens. **How:** an atomic `UPDATE … SET consumed_quantity = consumed_quantity + :q` inside the transition's transaction, idempotent per (customer-quotation line), so a replayed transition consumes nothing twice. **Never restored:** `won → purchasing` is the deal's only exit from `Won` and `accepted` is terminal, so no reversal exists to restore from; any correction is a manual, audited edit. **No ceiling:** no CHECK caps `consumed_quantity` at `quantity` — exceeding is allowed with a warning (§5.6). §5.6's warning and the builder's quantity hint (F-04) compare against **available**, not recorded. No new permission (the supplier-quotation view roles see all three numbers), no separate audit row (the old/new `consumed_quantity` rides the transition's audit entry), no backfill (existing rows start at 0). Closes F-05 |
| D-80 | **A `currency.view` permission joins §3.11, granted to §3.6's create/edit set (Manager · Team Leader · Outdoor Sales · Indoor Sales · Procurement); `GET /currencies` carries it and publishes each currency's `id`** (recorded 2026-09-16, approved by merging #145; the owner chose this over letting Module 6 accept a currency code). §7.2 lists a supplier offer's total and currency, and `DB` rule `(total_price IS NULL) = (currency_id IS NULL)` makes them one pair — but `currency_id` is a uuid, and until now the only way to read one was `GET /currencies` under `system settings`, which §3.11 gives the Super Admin alone, and whose payload carried no `id`. So every role §3.6 allows to write an offer could record a price and not its currency, and Module 7's §5.6 block (`supplier_price_missing`) then refused the quotation built on it — a correct block on a dead end. This row is the smallest reading that removes the dead end: a read-only row, scope All, no PATCH (rounding stays `system settings`), no new roles. The CEO and the Outdoor Supervisor never enter a price and are not granted. It is configuration under §3.12 rule 5 — `RolePermissionSeeder` upserts it; no migration |
| D-79 | **`OD-02` is closed: the `P-01` prototype's template is the approved customer quotation PDF template** (recorded 2026-09-13, confirmed by the project owner). `P-01` passed on 2026-08-19 but its own README closed with *"OD-02 (final PDF template) is still open; this is a working baseline, not the approved design"*, so Module 9 had a passed prototype and no approved template. This approves the baseline as the design. **What is approved:** the layout in `prototypes/p01-arabic-pdf/template.js` — the house style taken from the company's Purchase Order #226, its real logo and footer-band assets, the colours sampled from it (`#5B9BD5` headers, `#DEEAF6` row tint, `#4472C4` title rules, `#112131` footer band), Arabic and English rendered from **one** template with no duplicated markup and no hard-coded user-facing strings, and four font faces embedded as base64 with zero OS fallback — Inter among them for Latin and digits (`Design_System_EN.md` §4.1), without which `sans-serif` resolves to Helvetica on macOS and DejaVu on Ubuntu and the same template takes different metrics in development and production. Rendering is headless Chrome through Browsershot (`D-57`), because Chrome shapes Arabic and applies bidi natively, which is where naive PDF libraries fail; the prototype's result therefore transfers to Module 9 with only the wrapper changing. **The two departures from PO #226 are approved with it:** the cost column reads "UNIT PRICE" and not "UNIT COST", because the reference is a purchase order to a supplier while this is a customer-facing quotation and §3.12 rule 2 forbids exposing cost or margin to a customer; and the discount is applied **before** VAT per §5.2 and `D-64`, which is why PO #226's `10.32` difference is not a reconciliation target. ⚠️ **What this decision does not settle** — three items the prototype deliberately carried forward, which are Module 9 build work and not open decisions: page numbering is hard-coded `صفحة 1 من 1` and must become Chrome's `headerTemplate`/`footerTemplate` because item counts vary per quotation; the one-page guard must be re-checked on **every** template change, since content once ran 3mm over A4 and silently produced a blank second page; and the template must be fed by a customer-view model that **structurally cannot** contain supplier, cost or margin fields, rather than by a model that merely happens not to pass them. ⚠️ **Risk accepted knowingly:** the visual sign-off behind this approval was made against a macOS render, and `P-02` — deferred under `D-66` — is still the gate that proves the template on the on-premise Linux server. Because all four faces are embedded rather than referenced, the exposure is layout metrics and not missing glyphs; it stays on the deployment-debt register until `P-02` runs |
| D-78 | **The six user-administration endpoints are mapped onto §3.11's two existing permission rows; no `user.*` resource is created** (recorded 2026-08-25, approved by the owner with Point 3.2). §3.11 has exactly two rows about user accounts — *create user* and *deactivate user*, both held by Super Admin and Manager, with `—` in the *Others* column for every row in the table. It has **no row** for listing a user, viewing one, editing one, or reactivating one, yet §8 gives the Manager an *Employees* screen and §9 Flow 9 describes both the creation and the *"later changes: role change · deactivation"*. So the endpoints exist and the permission names for half of them do not. **Inventing `user.view.*`, `user.update.*` and `user.reactivate.*` was rejected**, and not on taste: `SEC-07` and §3.12 rule 5 put the live matrix in `role_permissions`, which is seeded from §3.3–§3.11, so a permission absent from the document is absent from the database — every **Manager** would be refused while the Super Admin passed on §3.1's unconditional access alone, silently deleting the grant §3.11 makes to the Manager. The mapping is therefore: `admin.create_user` guards the index, the detail, the create and the update; `admin.deactivate_user` guards deactivate **and** reactivate, because they are the same `is_active` switch and §3.11 has one row for it. **The mapping cannot widen access** — both rows are held by exactly the same two roles, so the set of permitted callers is §3.11's whichever row a route names, which is what makes this a labelling decision rather than an authorisation one. ⚠️ **Two costs, stated rather than hidden.** A caller refused while *reading* the list is told the action `admin.create_user` is not permitted, which is accurate about the check and confusing about the request. And a later decision that genuinely separates reading from writing — a role that may see the employee list without administering it — needs new rows in §3.11 first, and then this mapping is re-cut rather than extended. **Also confirmed with this decision:** §3.11's create-user allowlist (*Out.Sup · Out.Sales · Sales · Procurement* **only**) is the operative rule where it disagrees with §3.12 rule 7, which names only Manager, CEO and Super Admin and would leave Team Leader assignable. **A Manager may not create a Team Leader**; only the Super Admin may. `RoleAssignmentPolicy` implements the allowlist and a test pins the gap as exactly Team Leader |
| D-77 | **Eloquent models are confined to `app/Modules/<Module>/Infrastructure/Eloquent/` and carry no business rule** (recorded 2026-08-24; adopted with Point 2.1 and formalised here). `deptrac.layers.yaml` gives the Domain layer an **empty ruleset** — it may depend on nothing at all, `Illuminate` included — because Coding Standards §3.1 excludes "HTTP, UI, framework, storage, queue, or vendor SDK calls" from the domain, and `ERP-01` expects each module to be extractable later. An Eloquent model is a framework object by definition, so `Infrastructure/Eloquent/` is the only layer it can legally occupy; this is not a preference that a future module may re-litigate. **Three consequences follow, and each has already cost work.** (1) A module whose Infrastructure holds models needs the framework, which `AP-02`'s bare `~` ruleset denies — so it is **split into `<Module>Contract` (Domain, Application) and `<Module>Driver` (Infrastructure, Presentation)**, the arrangement Audit and Storage already use and Identity adopted on 2026-08-23. Another module may one day be allowed to depend on a `Contract`; nothing may ever depend on a `Driver`. (2) **Application may reach neither Eloquent nor Presentation**, so a use case that needs a row talks to an interface in `Domain/Contracts/` bound to an adapter in `AppServiceProvider` — Point 2.2's login runs against `AccountDirectoryInterface`, `SessionStoreInterface` and `ProfileReaderInterface` for exactly this reason. (3) `App\Models\` is **not** where a model lives; `User` was moved out of it, which means `newFactory()` and `$model` must be named explicitly because Eloquent's factory convention resolves against `App\Models` and no longer lands. ⚠️ **The cost, stated rather than hidden:** every persistence read from a use case is one interface and one adapter rather than one `::query()` call, and a rule shaped like this invites somebody in a hurry to "temporarily" widen a `deptrac` ruleset instead. The gate is the guard — `Violations 0 · Uncovered 0` on both configs is a merge condition, and a widened ruleset must arrive as a **named, commented exception with a reason**, the way `Identity → AuditContract` did |
| D-76 | **The `is_active` suspension check runs *after* password verification, not before it** (recorded 2026-08-24). §10.1 requires a deactivated employee to be told "Account suspended, please contact administration", and the natural reading — reject on `is_active = false` immediately — makes `POST /auth/login` an **account-status oracle**: an unauthenticated caller submits any address with any password and learns from the status code whether that person still works at the company. That is an enumeration surface on the one endpoint that is reachable without a credential, and it leaks staffing information (departures, dismissals) to anyone who can guess an address format. Coding Standards §9 and `OpenAPI §5.1`'s rule for 404 — "do not reveal which case applies" — both point the same way. So the order is **lock (`SEC-03`) → password → `is_active` (`D-34`)**: the lock comes first because a locked account must be told it is locked whatever it presents, and suspension comes last because only a caller who has already proved they are the employee may be told about their own account. **§10.1's documented behaviour is unchanged** for the case §10.1 actually describes — the deactivated employee knows their own password and still gets the documented message. ⚠️ **What this deliberately gives up:** a suspended person who mistypes their password is told "invalid credentials" rather than "suspended", so they may retry and consume `SEC-03` attempts against an account that cannot be used anyway. That is accepted; the alternative tells strangers who was let go |
| D-75 | **A locked account stays locked for 30 minutes, and the duration is configuration** (recorded 2026-08-24). `SEC-03` and §9 Flow 0 both stop at the word "locked" — neither says whether the lock lifts on its own, how long it lasts, or who clears it — while Point 1.2's approved schema carries a `locked_until` column, which presupposes an expiry. A value was therefore required and none existed to cite. **30 minutes** is long enough that an online guessing attack gains nothing (five attempts per half hour against an 8-character alphanumeric minimum) and short enough that a real employee who mistyped five times is not blocked for a working day, at a company with no help desk. It lives in `config/identity.php` as `identity.lockout_minutes`, not as a constant, because §3.12 rule 5 and `AP-08` make limits configuration — **Module 2 moves it into the `settings` table**, where an administrator changes it without a deployment. **What is deliberately *not* configurable:** `LockoutPolicy::MAX_ATTEMPTS = 5` and `IdleTimeout::HOURS = 8`. `SEC-03` and `D-29` state those outright, and an environment variable that can switch a documented security rule off is a defect wearing a config file as a costume. ⚠️ **Two gaps this leaves:** there is **no manual unlock** — a Super Admin cannot clear a lock early, because no user-administration endpoint exists yet — and the lock is **not** an IP block, so `SEC-16`'s blacklist remains unbuilt and a distributed attempt across many accounts is unaffected |
| D-74 | **Authentication uses a server-issued bearer token whose SHA-256 digest is what the database stores** (recorded 2026-08-24). `OpenAPI §3.1` permits either "an authenticated, active user session **or** an equivalent server-issued bearer credential" and picks neither, so this picks. **Four documented requirements decide it.** `AP-07` and `D-67` put one API under both the desktop SPA and the field PWA, and a credential the client attaches explicitly behaves identically for both, where a cookie session does not. `SEC-05` asks for an active-device list and force-logout, which under this scheme is one row per device in `user_sessions` and a revocation that is the soft delete `DB-01` already requires — no second mechanism to keep in step. `D-29`'s eight-hour idle rule then belongs to the application (`IdleTimeout`, measured against `last_activity_at`) rather than to a Redis TTL that an operator can change and no test can see. And `SEC-13`'s CSRF surface **does not exist** for a credential a browser never attaches by itself, which removes a whole class of defect rather than mitigating it. The token is 32 bytes of `random_bytes` rendered as 64 hex characters; `user_sessions.session_id` holds `hash('sha256', token)` and **never the token**, so a database disclosure yields nothing that can be replayed (Coding Standards §9 — "never expose … session tokens"). Unsalted SHA-256 is correct *here* and would be wrong for a password: the input is 256 bits of CSPRNG output, so there is no dictionary to attack and no reason to put a slow hash on a per-request path. ⚠️ **The trade-off, stated rather than hidden:** a token the SPA must hold is reachable by script in a way an `HttpOnly` cookie is not, so an XSS defect would leak it. What bounds the damage is that it is revocable per device (`SEC-05`), dies after eight hours idle (`D-29`), and is stored only as a digest. **Revisit if the SPA ever renders untrusted HTML**; until then the CSRF class removed is judged larger than the XSS class added |
| D-73 | **Three themes ship, and the default one carries no `data-theme` attribute** (recorded 2026-08-22; logged here 2026-08-23 with the owner's explicit authorisation, having been implemented before it was recorded). `Design_System_EN.md` fixed the semantic tokens and `D-70` derived the five that had no values, but neither named how many themes exist or how one is selected, and the work needed both. The three are **Warm Editorial** (default), **Midnight Obsidian** and **Clean Monochrome**, expressed as **22 semantic tokens** redefined per theme — `:root` for the default and `[data-theme='...']` for the other two. **The default is the bare `:root` and not `[data-theme='warm-editorial']`**, so a document that has never been themed still renders a complete palette; a design whose only definitions live inside attribute selectors paints nothing until JavaScript runs. Contrast was **checked mathematically against WCAG AA rather than by eye**, for every text-on-surface pair in all three themes. The selected theme is injected into the DOM **before first paint** in `welcome.blade.php`, because applying it after hydration produces a visible flash of the wrong palette on every load. ⚠️ **Two consequences, stated rather than hidden.** No component may carry a HEX value — a test fails on one in any `.vue` file — because a literal colour is invisible to two of the three themes by construction. And the token set is **one short**: the sidebar scrim needs an overlay that darkens in all three, no existing token can do it, and the interim is `backdrop-filter: brightness(0.45)`. A 23rd token `--color-overlay` and a `color-scheme` declaration are owed, and are recorded in `Design_System_EN.md` §10 |
| D-72 | **`audit_log` is range-partitioned by month on `created_at`, with a composite key and a monitored default partition** (recorded 2026-08-22). Closes `Q-3`. `DB-10` requires partitioning for the audit table and says nothing about granularity, and `OD-05` — expected daily workload — is still open, so **no row-count argument was available and none was invented**. The decision rests instead on two things that are already documented. First, the access shapes: three of the four indexes in `design/DATABASE.md` end in `created_at DESC` — record history, "what did this person do", and the mandatory critical events of `§3.12` — which are recent-window queries. A monthly partition prunes those to one or two partitions; a yearly one always reads a whole year to answer a question about a fortnight. Second, retention: `D-30` keeps every row permanently while `BK-01` backs up daily, and a month that has closed can never change again, so monthly boundaries turn a table that grows forever into a set of frozen partitions plus one hot one. **Rejected alternatives:** yearly, for the pruning reason above; quarterly, which reduces the partition count but matches no boundary this system already has, while `J-07` and the monthly report close on months. **The cost, stated rather than hidden:** twelve partitions a year — 120 after a decade — and the one documented lookup with no time bound, `correlation_id`, probes every one of them. Tracing a single request stays cheap because each probe is a local btree index, but it is a real cost and it grows. **Revisit when `OD-05` produces a figure**; repartitioning later is a data migration, not a configuration change. ⚠️ **Four consequences are measured facts about PostgreSQL 17.5, not preferences.** (1) A unique constraint on a partitioned table must contain every partitioning column — `unique constraint on partitioned table must include all partitioning columns` — so the primary key is **`(id, created_at)`** and cannot be `id` alone; anything reading the audit by identity must carry the timestamp with it. (2) A **`DEFAULT` partition is kept**, even though a row that lands in it *blocks* creating the matching range partition — `updated partition constraint for default partition would be violated by some row`. Without one, an insert outside every range fails outright, and because `AUD-01` and `DB-11` put the audit write inside the business transaction, a partition nobody created would take down the business operation itself. Losing the guarantee is worse than keeping a partition that must be watched, so the default is paired with a health check asserting it is empty. (3) `pg_partman` and `pg_cron` are **not present** in the `postgres:17.5-bookworm` image, so future partitions are created by an application job rather than by the database — recorded as `J-15`. (4) A row trigger declared on the partitioned parent propagates to its partitions, but a statement-level `BEFORE TRUNCATE` trigger **does not** — a `TRUNCATE` aimed at a partition directly went through and emptied it — so the append-only guard has to be installed on every partition as it is created, which is `J-15`'s second duty. **`audit_log` is not a business table.** It carries no `updated_at`, no `deleted_at` and no `created_by`/`updated_by`: `DB-01` and `DB-02` describe rows that change and are retired, and `AUD-03` forbids exactly that. The actor is `user_id`, and it carries **no foreign key yet** — Module 1 replaces the users table rather than extending it, so the constraint arrives with that module, the same arrangement `standardActorForeignKeys()` already uses. That is a temporary `DB-04` gap, recorded rather than overlooked |
| D-71 | **Attachments link through pivot tables, not a polymorphic column, and the size limit is 30 MB** (recorded 2026-08-22). Closes `Q-2`. `§17` fixed everything about attachments except the shape of the parent link, and the draft in `design/DATABASE.md` carried `entity_type` + `entity_id` with a note that the specification does not say how. **A polymorphic column cannot have a foreign key.** PostgreSQL has no way to constrain one column against five tables, so `entity_id` would be free to point at a deal that never existed or one that was deleted, and nothing in the database would object. That collides directly with `DB-04` and the rule in `CLAUDE.md` that every new table carries foreign keys and database constraints — and the evidence that the specification already expects the failure is `J-11` itself, a **weekly job whose entire purpose is deleting files linked to nothing**. A cleanup job for orphans is an admission that orphans will occur. **The chosen shape:** one `files` table holding the bytes' metadata — original name, true MIME type, size, storage path, scan status — and one pivot per attachable parent (`deal_files`, `supplier_quotation_files`, `purchase_order_files`, `report_files`), each with real foreign keys in both directions. One storage path, one upload endpoint, one download endpoint, one permission rule (`D-38`), and referential integrity the database enforces rather than a job repairs. `J-11` remains, demoted from mechanism to backstop: it now catches only a file uploaded and never attached, which is a genuine orphan rather than a broken pointer. **The cost, stated rather than hidden:** a join on every attachment read, and one new pivot table for every future attachable entity — Module 12's visits will need `visit_files`. That is the price of a constraint the database can check, and it is deliberately paid. **The limit moves from 10 MB to 30 MB**, superseding the value in `D-39` while leaving everything else in it standing: the limit is still configurable and still enforced in the database rather than as a code constant. 30 MB accommodates a scanned multi-page supplier quotation, which is the document this system actually receives. ⚠️ **Consequence:** the application limit is not the only ceiling. PHP's `upload_max_filesize` and `post_max_size` and nginx's `client_max_body_size` each cut before it, and raising the configured limit without raising those produces a failure with no message that explains it. All three are set above 30 MB, and a test asserts it rather than trusting a comment |
| D-70 | **The five unvalued semantic tokens are derived, and numerals are Western in every locale** (recorded 2026-08-21). `Design_System_EN.md §3.2` obliges every component to consume 21 semantic tokens and `§8` forbids any component from bypassing them, but `§3.3` gave values for only 16. Five were mandatory and undefined: `primary-active` (required by `§6.2`, which gives every button an active state), `text-inverse`, `status-neutral` (required by `§6.4` for Draft and neutral workflow states), `shadow-1` and `shadow-2` (assigned distinct roles by `§4.2` and `§6.6`). A component could not be written without them and no value existed to write, so this records the derivation rather than leaving it to whoever hit the gap first.
**`primary-active` — one rule, applied to all three themes:** continue the documented `primary → primary-hover` delta a second time. Odoo `#472F41`, Clean White `#1F3286`, Dark Blue `#D0E4FF`. Nothing is invented: each value is reproducible from two numbers `§3.3` already publishes. Corroboration rather than a second method — Clean White's `primary` and `primary-hover` are exactly Tailwind blue-700 and blue-800, and the derived active lands within one step of blue-900. `primary-text` on each derived active measures 11.99, 11.30 and 12.63 to 1.
**`text-inverse`** is the theme's `text` inverted onto a solid dark chip or tooltip: `#FFFFFF`, `#FFFFFF`, `#08203D` — measuring 13.87, 16.27 and 15.48 against that theme's `text`. **It equals `primary-text` in all three themes today, and that is stated rather than hidden:** the tokens are separate because `§3.2` separates them, and a future theme whose primary is light while its dark chips stay dark will pull them apart.
**`status-neutral`** takes the theme's `text-muted` value — `#625C61`, `#5E6B82`, `#B9C8DC`. `§6.4` forbids colour alone, so a neutral status carries no hue of its own; it reads with the weight of muted text. The token exists so a component asks for a status colour rather than reaching for a typography token.
**`shadow-1` and `shadow-2` are not colours** and therefore sit outside the `§3.3` hex table and outside the contrast gate. `§4.2` assigns `shadow-1` to resting cards and menus and `shadow-2` to dialogs and popovers only. The dark theme carries markedly higher alpha because a 6%-black shadow on `#081A33` is invisible.
**Numerals are Western `0-9` in every locale, rendered by Inter.** `§4.1` routes "English / numbers" to Inter and requires tabular numerals; `§8` lists numerals among the RTL checks. Neither names a digit shape, and the silence was load-bearing: `Intl.NumberFormat('ar')` emits Arabic-Indic digits by default, Inter carries none of them, so the string falls through to Noto Sans Arabic and the documented rule quietly stops holding — with no error anywhere. Every locale therefore uses the Latin numbering system (`ar-u-nu-latn`), which is also what makes `§4.1` testable at all. ⚠️ **Consequence accepted:** Arabic readers who expect ٠١٢٣ see 0123. This matches the company's own PO #226 and every figure in the source documents, and it keeps one digit shape across the UI, the PDFs and the exports.
**One measured gap this surfaced and does not close:** Odoo's documented `success` `#15803D` on its documented `surface-muted` `#F1EEF0` measures **4.35:1**, below the 4.5:1 `§8` requires. Both values are pre-existing and neither is changed here. The pair is pinned at its measured value by the contrast test so it cannot drift further, and it needs an owner decision — darken Odoo's `success`, lighten its `surface-muted`, or rule that a status chip never sits on a muted surface |
| D-69 | **`X-Correlation-Id` is validated against a fixed shape, and an unusable value is replaced rather than refused** (recorded 2026-08-21). The header is accepted only when it matches `^[A-Za-z0-9._-]{1,128}$`; a missing, empty, malformed or overlong value is ignored and a server-generated ID is used instead, with the request proceeding to its normal status and never `400`. `OpenAPI §3.3` already said *validate and propagate it*, and that instruction had no content: neither the allowed characters nor a maximum length existed anywhere in the documentation, so “validate” was satisfied by any implementation at all — including the one that was live, which echoed the header verbatim. Measured before this decision, an **8,000-character attacker-controlled value reached the application**; the only ceiling was nginx's header buffer, not a rule of ours. That matters because `AUD-05` writes the correlation ID into structured logs and Coding Standards §10 writes it into audit rows, and audit rows are **immutable and retained permanently** (§14.8) — a value carrying a line break, a quote or a field separator forges a record that can never be corrected. The character class is therefore an allowlist of what is safe to write verbatim into a log line, not a formatting preference. **128** admits every identifier the contract itself names — a UUID (36), the prefixed ULID of the §4.1 envelope (30), a W3C `traceparent` (55) — while cutting the attacker-controlled surface sixty-fold against what nginx passes today. **Replace rather than refuse**, because §3.3 marks the header `Optional`: something a client may omit entirely must never be able to fail that client's request, which would turn a diagnostic aid into a denial-of-service surface. Two consequences are deliberate. The value `0` is **valid** and propagates unchanged — the implementation this replaces used PHP's `?:`, which discarded it as falsy and produced a correlation ID that does not correlate, a defect visible only in production logs. And an overlong value is **discarded, not truncated**, because truncation manufactures a plausible-looking ID matching nothing upstream, which is worse than an honest new one. ⚠️ **Trade-off accepted rather than hidden:** a caller whose tracing system emits an ID outside this class silently loses correlation for that request, with no error to tell them. That is accepted because the only alternative — telling them — means failing a request over an optional header. Non-ASCII trace IDs are excluded on the same ground: identifiers are machine keys, not user-facing text, so the Arabic requirement in §14.2 does not reach them |
| D-68 | **Numeric precision is fixed per kind of value** (recorded 2026-08-20). Money columns are `NUMERIC(18,6)`, FX rates `NUMERIC(18,8)`, percentages `NUMERIC(6,3)` and quantities `NUMERIC(14,4)`. `DB-07` required Decimal and said nothing about digits, and that silence was a live defect rather than a detail: `D-06` forbids rounding an intermediate value and `D-65` allows rounding to be switched off entirely, so a stored value has to survive at full working precision. On PO #226 the tax computes to `1021.263012` — six decimal places — and a `NUMERIC(12,2)` column truncates that **silently**, with no database error, which is exactly the intermediate rounding `D-06` exists to prevent. Twelve integer digits cover any realistic total; six decimals sit four orders below the smallest configured rounding unit. **The trade-off, stated rather than hidden:** persistence at scale 6 is itself a quantization, because `unit_cost_base = unit_cost × fx_rate_at_time` can produce more decimals than that. It is acceptable only because the discarded magnitude can never move a final total — but it is a documented quantization, not an accident. Arithmetic runs in BCMath at higher precision; only the persisted snapshot is at scale 6. Closes `Q-8` in `design/DATABASE.md` |
| D-67 | **Frontend is a Vue 3 + TypeScript SPA on Vite, consuming `/api/v1`** (recorded 2026-08-19). `D-57` named the backend and deliberately left the frontend open; §14.2 asked only for an SPA with full RTL/LTR support plus a PWA. This names it. **Inertia and Livewire are rejected, and that rejection is the load-bearing half of this decision** — they are Laravel's most natural defaults, so without this line they get reintroduced by someone acting in good faith. Both bind the UI to Laravel controllers rather than to `/api/v1`. `AP-07` requires web and mobile to share one API, and §14.7 (`API-01`…`API-12`) with `OpenAPI_Contract_EN.md` define the envelope, pagination, idempotency keys and `If-Match`/`409` that must exist *before the first endpoint*. Serving Inertia responses alongside that REST surface means two backends for one product, and the second one always drifts. **Vue over React** is a maintainability and hiring call, not a capability one — React satisfies every documented requirement too. Vite is Laravel's first-party bundler so the toolchain is already aligned; the Laravel hiring pool pairs with Vue far more often, which matters because a team joins later; and Vue's reactivity fits the Module 7 quotation builder's live-preview form with less ceremony. It is recorded so it is not relitigated per screen. **Constraints this does not relax:** the SPA never owns a business rule (`AP-04`, Coding Standards §11) — it displays backend-calculated values and may preview, never decide; UI visibility is not authorization (`SEC-09`, §3.12 rule 1); all text comes from lang files and direction follows locale rather than duplicated screens; components consume design-system semantic tokens only. **PWA** uses `vite-plugin-pwa`, online-only per §14.2 — the service worker caches the application shell and **never business data**. The only permitted local persistence is the Module 12 visit-form draft across a brief connection drop, which must not present the system as offline-capable |
| D-66 | **Development starts locally on a production-matched stack; the real-server gate is deferred, not removed** (recorded 2026-08-19). Access to the on-premise server depends on the manager and the team, and on `OD-03`, so work begins without waiting for it. **The local environment must be Docker with Linux containers matching §14.2** — PostgreSQL, Redis, Meilisearch, Nginx, PHP — and never the developer's host OS directly. That condition is the whole point: `P-01` proved the on-premise Linux server carries no Arabic system fonts, so a template that relies on host fonts passes on macOS and fails in production. Developing on the host would hide exactly the class of defect `P-02` exists to catch. **`P-02` is deferred, not cancelled** — it runs the moment the server exists, and before the pilot rollout in the build plan §4.3. While it is open, the seventh definition-of-done item reads *"passes on the production-matched local environment, and its deployment debt is recorded"*; the original wording returns when the server does. A **deployment-debt register** in `CHECKLIST.md` lists every requirement that cannot be verified locally, and nothing on it counts as verified until it has run on the server: Cloudflare Tunnel and Access (`D-59`), boot order (`ST-01`…`ST-09`), missed-job catch-up (`D-55`), restart recovery (`D-54`), backup and restore (`BK-01`…`BK-08`), the external heartbeat (`OBS-07`), and queue/storage sizing (`OD-03`, `OD-05`). ⚠️ **Risk accepted knowingly:** the build plan put `P-02` first because "external access, permissions and fonts are what surprise you." Container parity answers the fonts, locale and database half of that. Cloudflare and the hardware stay unverified until the server is real, and that gap is carried openly on the register rather than discovered at rollout |
| D-65 | **Rounding is optional** (recorded 2026-08-19). Rounding the final total is a configurable behaviour per currency, not a mandatory step. It may be switched off entirely, in which case `final_total = total_before_round` and `rounding_diff = 0`. When it is on, `D-06` and `D-52` apply unchanged — the final total only, by that currency's configured unit. The on/off setting lives in `System Settings → Currencies` beside the unit (`AP-08`), and changing either one affects new quotations only, never an issued one |
| D-64 | **Tax is calculated after the discount is applied** (recorded 2026-08-19). The discount is subtracted from the `subtotal` first and the tax is computed on what remains: `tax_base = subtotal − discount_amount`. Additional items stay outside the tax base (`OD-01`, `D-62`). This **supersedes `D-60`** and restores the ordering §5.2 carried before it. ⚠️ Consequence accepted by the owner: the company's PO #226 applies its 14% to the pre-discount amount and prints `8,326.32`; under this decision the same figures give `8,316.00` — a difference of `10.32`. **PO #226 is therefore no longer a reconciliation target for tax ordering.** The related question — whether the PO's **إشعار خصم** line is a sale discount or a separate credit note — remains open with the accountant and may change *what* the discount is, not *where* it is applied |
| D-63 | **Tax is optional per quotation, defaulting from the customer** (recorded 2026-08-12; confirmed by the owner 2026-08-19 — a preparer may enter a different tax percentage on any quotation). Some customers are not taxed at all, so `tax_percent` is nullable rather than always present. The customer record carries the default (`is_tax_exempt`); a new quotation inherits it and the preparer may override per quotation. A quotation with no tax shows no tax line at all — not a zero line |
| D-62 | **Supplier cost is taken as recorded; the company's own tax is added on the selling price** (recorded 2026-08-12; confirmed by the owner 2026-08-19 — delivery and installation carry no tax). Whatever tax the supplier charges is part of their price and is not decomposed. The system never extracts VAT out of a supplier cost. Tax in this system means only the tax the company adds when selling |
| D-61 | **Primary keys are UUID** (recorded 2026-08-12). Every business table uses a UUID primary key, generated application-side as a time-ordered UUID so inserts stay sequential and indexes do not fragment. This satisfies `OpenAPI §2` ("opaque UUID identifiers") with one key rather than a numeric key plus a public UUID, keeps IDs non-enumerable — which matters in a system whose permissions are row-scoped — and lets a module be extracted later without ID collisions (`ERP-01`). Human-readable business codes (`DL-…`, `QT-…`) stay separate fields as the contract requires. Cost accepted: 16 bytes against 8, and slightly slower joins; at this system's scale neither is the bottleneck |
| ~~D-60~~ | ~~**Tax is calculated before the discount is applied**~~ (recorded 2026-08-12) — **superseded by `D-64` on 2026-08-19**, which restores the opposite ordering. Retained for history: `D-60` set the tax base to the `subtotal` and subtracted the discount afterwards, reconciling the company's PO #226 to the piastre. The owner confirmed on 2026-08-19 that the intended rule is tax **after** the discount; `D-64` records the resulting difference against PO #226 as accepted. The question `D-60` raised is still open: the source line is labelled **إشعار خصم** (credit note), which in accounting is a separate instrument adjusting an already-issued invoice rather than a discount on the sale. If that is what it is, the discount does not belong on the quotation at all — confirm with the accountant |
| D-59 | **External access uses Cloudflare Tunnel + Access, not a VPN** (recorded 2026-08-12; confirmed by the owner 2026-08-19). The company LAN remains the primary access path for every role. External access is limited to five named users — CEO, Manager, and Outdoor Sales. The server opens no inbound port; the tunnel connection is outbound from the server. Cloudflare Access is an identity gate **in front of** the application and **never replaces** the system's own authentication or its permission matrix (`SEC-07`, `SEC-09`); its session lifetime is at least 8 hours so field staff are not forced through two logins a day (`D-29`). **Supersedes `OD-04`**, revises §1 and §14.4, changes the `P-02` criterion from "through VPN" to "through Cloudflare", and reframes the Module 12 message from "VPN disconnected" to "connection unavailable" |
| D-58 | **Arabic documentation is reading-only** (recorded 2026-08-12). Translations live in `arabic/` for the project owner's reading. They are not maintained companions, carry no synchronization requirement, and are never loaded as a source. This supersedes the companion declarations previously carried in the headers of this document, the build plan, the documentation map, the design system, and the OpenAPI contract. **The product requirement for Arabic in the running system is unchanged**: the application itself ships Arabic and English from Module 0 (§1, §14.2), and this decision governs the specification documents only |
| D-57 | **Backend framework is Laravel** (recorded 2026-08-11). The documented stack in 14.2 — PostgreSQL, Redis, Meilisearch — is unchanged; this decision only names the application framework, which the documentation had deliberately left open. Chosen for its queue, scheduler and migration tooling, which map directly to the four queues (15.1), J-01…J-14, and the Queue Monitor and Scheduler screens (OBS-02, OBS-03). PDF generation uses headless Chrome via Browsershot, proven by prototype P-01 |

---

## 3. Roles & Permission Matrix

### 3.1 The Eight Roles

| Role | Description | Scope |
|---|---|---|
| Super Admin | The developer — completely hidden from all users | All |
| CEO | External observer — read-only | All |
| Manager | Highest operational authority | All |
| Team Leader | Team owner | Team |
| Outdoor Supervisor | Monitors visits only, up to customer handover | Out |
| Outdoor Sales | Field visits + continues as Indoor Sales | Own |
| Indoor Sales | Full deal cycle with the customer | Own |
| Procurement | Negotiation and cost reduction | Asgn |

### 3.2 Permission Model

```
Permission = Resource + Action + Scope
e.g. customer.view.own · quotation.approve.team · supplier.edit.all
```

| Code | Meaning |
|---|---|
| **Own** | Their own records only |
| **Team** | Their team's records |
| **All** | Every record in the system |
| **Out** | Outdoor team records only |
| **Asgn** | Deals handed over to them |
| **—** | Not permitted |

### 3.3 Customers

| Permission | Manager | TL | Out.Sup | Out.Sales | Indoor | Procure | CEO |
|---|---|---|---|---|---|---|---|
| view | All | Team | Out | Own | Own | Asgn | All |
| create | All | All | Out | Own | Own | — | — |
| edit | All | Team | Out | Own | Own | — | — |
| assign | All | Team | — | — | — | — | — |
| archive / restore | All | Team | — | — | — | — | — |
| import (Excel) | All | — | — | — | — | — | — |
| delete | ❌ Forbidden for every role — no hard deletes | | | | | | |

### 3.4 Deals / Requests

| Permission | Manager | TL | Out.Sup | Out.Sales | Indoor | Procure | CEO |
|---|---|---|---|---|---|---|---|
| view | All | Team | Out | Own | Own | Asgn | All |
| create | All | All | Out | Own | Own | — | — |
| edit | All | Team | Out | Own | Own | Asgn | — |
| approve / reject | All | Team | — | — | — | — | — |
| assign owner | All | Team | — | — | — | — | — |
| change status | All | Team | Out | Own | Own | Asgn | — |
| mark delivery complete | ✅ | ✅ | — | — | ✅ Own | ✅ Asgn | — |
| view timeline | All | Team | Out | Own | Own | Asgn | All |

### 3.5 Customer Quotations

| Permission | Manager | TL | Out.Sup | Out.Sales | Indoor | Procure | CEO |
|---|---|---|---|---|---|---|---|
| view | All | Team | — | Own | Own | Asgn | All |
| create | All | Team | — | Own | Own | — | — |
| edit | All | Team | — | Own (Draft) | Own (Draft) | — | — |
| **view cost & margin** | ✅ | ✅ | — | ✅ | ✅ | ✅ | ✅ |
| **edit margin** | ✅ | ✅ | — | ✅ | ✅ | — | — |
| **edit tax** | ✅ | ✅ | — | ✅ | ✅ | — | — |
| submit for approval | All | Team | — | Own | Own | — | — |
| **approve / edit & approve** | All | Team | — | — | — | — | — |
| **return with note** | All | Team | — | — | — | — | — |
| send to customer | All | Team | — | Own | Own | — | — |
| generate PDF | All | Team | — | Own | Own | Asgn | — |
| **export/download PDF** | ✅ | ✅ | — | Own | Own | Asgn | **✅** |
| record customer response | All | Team | — | Own | Own | — | — |
| delete (Draft only) | All | Team | — | Own | Own | — | — |

> The CEO **downloads** an existing PDF rather than generating a new one, consistent with read-only status.

### 3.6 Supplier Quotations

| Permission | Manager | TL | Out.Sup | Out.Sales | Indoor | Procure | CEO |
|---|---|---|---|---|---|---|---|
| view | All | All | — | All | All | All | All |
| create / edit | ✅ | ✅ | — | ✅ | ✅ | ✅ | — |
| upload attachment | ✅ | ✅ | — | ✅ | ✅ | ✅ | — |

> A shared screen — not restricted by ownership.

### 3.7 Catalog & Suppliers

| Permission | All operational roles | CEO |
|---|---|---|
| view | ✅ | ✅ read-only |
| create · edit · deactivate · set colour | ✅ | — |
| delete | ❌ Forbidden — deactivate only | — |

> ⚠️ **On D-45:** opening editing to everyone speeds up daily work but exposes catalog data to drift. **Mitigation:** every edit is written to the audit log, plus a monthly review by the Team Leader. If problems appear, tightening this is a settings change — not a code change.

### 3.8 Visits

| Permission | Manager | TL | Out.Sup | Out.Sales | Other roles |
|---|---|---|---|---|---|
| view | All | Team | **Out** | Own | — |
| create / edit | — | — | Out | Own | — |
| assign area | ✅ | ✅ | ✅ | — | — |

### 3.9 Procurement

| Permission | Manager | TL | Procure | CEO | Others |
|---|---|---|---|---|---|
| view negotiation log | All | Team | All | All | — |
| create negotiation | ✅ | — | ✅ | — | — |
| record saving | — | — | ✅ | — | — |
| view profit calculation | ✅ | ✅ | ✅ | ✅ | Own |

### 3.10 Reports

| Permission | Manager | TL | Out.Sup | Sales | Procure | CEO |
|---|---|---|---|---|---|---|
| create | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| view received | All | Team | Out | ❌ | ❌ | All |
| acknowledge | ✅ | ✅ | ✅ | — | — | ❌ |
| return with reason | ✅ | ✅ | ✅ | — | — | ❌ |
| **comment** | ✅ | ✅ | ✅ | — | — | **✅ sole exception** |
| export PDF / Excel | ✅ | ✅ | ✅ | Own | Own | ✅ |

### 3.11 Administration

| Permission | Super Admin | Manager | Others |
|---|---|---|---|
| create user | ✅ any role | ✅ (Out.Sup · Out.Sales · Sales · Procurement only) | — |
| deactivate user | ✅ | ✅ | — |
| create / edit role · permissions | ✅ | — | — |
| system settings | ✅ | — | — |
| FX rates | ✅ | ✅ | — |
| system limits (SLAs, thresholds) | ✅ | — | — |
| view audit log | ✅ | ✅ Team | — |
| Login As User | ✅ (mandatory logging) | — | — |
| database · backup · queue | ✅ | — | — |

### 3.12 Rules That Override the Matrix

1. **Enforcement happens at the API** — hiding a button is not the same as blocking an action.
2. **Field-level:** supplier cost, supplier names and margin are **never present in the customer PDF**, regardless of who generates it.
3. **No hard deletes** for customers, deals, reports or suppliers — deactivate or archive only.
4. **Mandatory audit entries** for: tax edits · margin edits · customer reassignment · role change · Login As · restore from archive · FX rate change · account deactivation · self-approval.
5. **Permissions live in the database** — changing this matrix is a configuration change, not a deployment.
6. **The Super Admin is hidden** — never listed in any user list, for any role.
7. **The Manager may not create** Manager, CEO or Super Admin accounts.

---

## 4. Data Model

### 4.1 Entity Map

```
Customer
   └── Deal (request)                      ← D-01, D-02
         ├── Deal Documents
         ├── Quotations (v1 → v2 → v3)     ← customer quotations and their versions
         │     ├── Quotation Items
         │     └── Additional Items
         ├── Purchase Order (customer attachment)
         └── Procurement Negotiation

Supplier ──► Supplier Quotation            ← standalone entity (D-51)
                    │
                    └── deal_id (optional — nullable)
                    └── its prices feed Quotation Items
```

### 4.2 Customers

| Field | Notes |
|---|---|
| name | Required |
| customer_status | **Derived automatically** — see 4.5 |
| sector | Reference list: Government · Medical · Commercial · Industrial · Hotels · Banks (extendable) |
| region | Free text with suggestions (D-20) |
| contact_person | Single contact (D-18) |
| phone · phone2 · whatsapp · email | |
| sales_owner_id | Team Leader / Manager can change it |
| start_date | First engagement — drives "years of dealing" |
| notes | Includes communication history (D-16) |
| added_by · created_at | Automatic |
| is_archived | Manual archive (Manager + TL only) |
| is_incomplete | Imported record with missing fields (D-31) |

### 4.3 Deals

| Field | Notes |
|---|---|
| code | Automatic — `DL-2026-0001` |
| customer_id | |
| title | Short description of the request |
| source | Outlook/WhatsApp · Outdoor visit · Employee entry |
| service_type | Product · Service |
| status | See 4.4 |
| owner_id | Assigned sales employee |
| approval_status | Pending · Approved · Rejected — for employee-entered requests |
| rejection_reason | Mandatory on rejection |
| last_activity_at | Drives the stale-deal calculation (D-17) |
| created_by · created_at | |

### 4.4 Deal Statuses

```
Lead → Contacted → Waiting Customer Request → Supplier RFQ
     → Supplier Quotation → Quotation Sent → Negotiations
     → Won → Purchasing → Delivery → Delivery Complete
                        ↘ Lost (terminal)
```

| Status | Who changes it | Next |
|---|---|---|
| Lead | TL / Outdoor | Contacted |
| Contacted | Sales | Waiting Customer Request |
| Waiting Customer Request | Sales | Supplier RFQ |
| Supplier RFQ | Sales | Supplier Quotation |
| Supplier Quotation | Sales | Quotation Sent |
| Quotation Sent | Sales (after approval) | Negotiations / Won / Lost |
| Negotiations | Sales | Won / Lost |
| Won | TL / Manager | Purchasing |
| Lost | Sales (mandatory reason) | Terminal |
| Purchasing | Procurement | Delivery |
| Delivery | Procurement | Delivery Complete |
| Delivery Complete | Any of the four (D-14) | Terminal |

**Every status change is written to the deal timeline:** old status · new status · who · when.

### 4.5 Customer Status — Derivation Rule (D-49)

Computed automatically; never entered by an employee. **The first matching condition wins:**

| # | Condition | Status |
|---|---|---|
| 1 | **Any** deal has reached `Won` or beyond (now or historically) | **Customer** — permanent, never reverts |
| 2 | An active deal exists (`Lead` → `Negotiations`) with activity newer than the stale threshold | **Prospect** |
| 3 | An active deal exists but activity is older than the threshold · or a quotation went `Expired` with no reply | **No Response** |
| 4 | All deals are `Lost` and none are active | **Deal Not Completed** |
| 5 | Registered with no deals at all | **Prospect** |

**Implementation:** recalculated on every deal status change (domain event), plus a **nightly correction job**. The field is read-only in the UI, with an icon explaining the reason.

### 4.6 Purchase Orders (D-53)

| Field | Source | Example |
|---|---|---|
| po_number | **System-generated** | `PO-2026-0001` |
| customer_po_reference | **Free text — the customer's own number** | `4500123987` |
| po_date | Date of the customer's order | |
| attachment | Image / PDF | |

Search works on both numbers.

### 4.7 Document Numbering

| Code | Entity | Available |
|---|---|---|
| `DL-2026-0001` | Deal | MVP |
| `QT-2026-0001` | Customer quotation | MVP |
| `SQ-2026-0001` | Supplier quotation | MVP |
| `PO-2026-0001` | Customer purchase order | MVP |
| `RPT-DL-2026-0043` | Report | MVP |
| `SPO-…` | Purchase order to supplier | Post-MVP |
| `INV-…` / `DN-…` | Invoice / delivery note | ERP |

> **Disambiguation rule:** any code beginning with `RPT-` is a **report**. All other prefixes are operational entities.

### 4.8 Mandatory Database Rules

| # | Rule |
|---|---|
| DB-01 | Soft delete on every table — no physical DELETE |
| DB-02 | Mandatory audit columns: created_by · created_at · updated_by · updated_at |
| DB-03 | Versioning for quotations and reports via parent_id + version |
| DB-04 | Foreign keys and constraints enforced at the database level |
| DB-05 | **Enum tables**, not hard-coded enums — sectors · units · service types · delivery terms |
| DB-06 | Multi-currency: amount · currency · fx_rate_at_time · base_amount |
| DB-07 | **Decimal for money — Float is forbidden** |
| DB-08 | Timestamps stored in UTC, displayed in user time |
| DB-09 | Indexes on: customer · owner · deal status · dates · entity codes |
| DB-10 | Partitioning for high-volume tables: audit · notifications |
| DB-11 | Transactions mandatory for any operation touching more than one table |
| DB-12 | **Optimistic locking** on quotations |
| DB-13 | Fully managed migrations — no manual schema edits |

---

## 5. Pricing Rules

> The most important section in this document.

### 5.1 Line Level (quotation_items)

```
unit_cost         = supplier unit price (in supplier currency)
unit_cost_base    = unit_cost × fx_rate_at_time      ← convert to quotation currency (D-09)
margin_percent    = line margin; if empty, inherits the quotation margin (D-03)
unit_price        = unit_cost_base × (1 + margin_percent / 100)   ← (D-04)
line_total        = unit_price × quantity
line_cost         = unit_cost_base × quantity
```

### 5.2 Quotation Level

```
subtotal            = Σ line_total
additional_total    = Σ additional items (delivery / installation)

discount_amount     = subtotal × discount_percent / 100          ← (D-07)

tax_base            = subtotal − discount_amount       ← tax AFTER discount (D-64)
                                                          additional items are NOT taxed (OD-01, D-62)
tax_amount          = tax_base × tax_percent / 100     ← no tax line at all when the customer is
                                                          exempt or tax_percent is null (D-63)

net_amount          = subtotal + additional_total − discount_amount   ← revenue excl. tax

total_before_round  = net_amount + tax_amount

final_total         = round(total_before_round, currency unit)   ← rounding ON  (D-06, D-52)
                    = total_before_round                         ← rounding OFF (D-65)
rounding_diff       = final_total − total_before_round           ← stored; 0 when rounding is off
```

> **Worked example — the company's PO #226 figures under this ordering:**
> `subtotal 7,368.42` · `discount 1% → 73.6842` · `tax base 7,294.7358` · `tax 14% → 1,021.2630` ·
> `total_before_round 8,315.9988` → `final_total 8,316` with EGP rounding on (unit 1),
> or `8,315.9988` with rounding off (`D-65`).
>
> The PO itself prints `8,326.32` because it taxes the pre-discount amount. `D-64` records that
> `10.32` difference as accepted, so **PO #226 is no longer a reconciliation target for tax ordering.**

### 5.3 Rounding Unit per Currency (D-52)

| Currency | Default unit |
|---|---|
| EGP | 1 pound |
| USD | 0.01 dollar |
| EUR | 0.01 euro |

Editable under `System Settings → Currencies`, where rounding can also be switched **off** for a currency (`D-65`).
When it is on, it applies to the **final total only**. Changing either the unit or the on/off setting affects
new quotations only, never one that has already been issued.

### 5.4 Profit Calculation

```
total_cost     = Σ line_cost
gross_profit   = net_amount − total_cost          ← net_amount already excludes tax and discount
margin_ratio   = gross_profit ÷ net_amount × 100
```
> Tax is **not profit** — it is collected on behalf of the state and excluded from profit.

### 5.5 After Procurement

```
saving        = old_total_cost − new_total_cost
final_profit  = gross_profit + saving
saving_ratio  = saving ÷ old_total_cost × 100
```

### 5.6 Mandatory Rules

- All calculations run in the **backend** — the UI only displays.
- **Decimal only** — Float is forbidden.
- Every amount stores: `amount · currency · fx_rate_at_time · base_amount`.
- Changing an FX rate **never affects** an existing quotation.
- Product or price missing at the supplier → **block save**.
- Requested quantity exceeds the supplier's recorded quantity → **inline red warning**.

---

## 6. Customer Quotations

### 6.1 Statuses (9)

| Status | Meaning | Who sets it |
|---|---|---|
| Draft | Being prepared | Sales |
| Pending | Submitted for approval | Sales |
| Approved | Approved but not yet sent | TL / Manager |
| Sent | Sent to the customer, PDF generated | Sales |
| Accepted | Customer accepted (purchase order) | Sales |
| Partial | Partial acceptance | Sales |
| Counter | Customer requested changes | Sales |
| Rejected | Final rejection (mandatory reason) | Sales |
| Expired | Passed `valid_until` with no reply | System (job J-01) |

### 6.2 Fields

| Group | Fields |
|---|---|
| Core | Quotation code · deal · customer · quotation date · valid until · status |
| Suppliers | 1 → 10 suppliers via (+) · products and quantities per supplier — **excluded from the PDF** |
| Financial | Currency · default margin · discount % · tax % · additional items |
| Terms | Payment (free text) · warranty · delivery · show delivery terms in PDF (yes/no) |
| Versioning | Version · parent quotation · rejection / counter reason |
| Tracking | Created by · sent date · last edit and by whom · `is_self_approved` |

### 6.3 Versioning

- On **Partial**, **Counter** or **Returned**, the system saves a full copy and the employee edits a new version (D-08).
- Linked via `parent_id` + `version`; all versions remain visible under "Previous Quotations" within the deal.
- **Rejection and counter reasons are mandatory** before the status change is accepted.

### 6.4 Approval Cycle

```
Draft ──submit──► Pending ──┬──approve──► Approved ──send──► Sent
                             ├──edit + approve──► Approved
                             └──return with note──► Draft (v2)
```

- Team Leader and Manager share **the same screen and the same authority** (D-10).
- They may edit tax, margin, or any field — and **every edit is written to the audit log**.
- No automatic escalation (D-11) — a red badge plus a "days waiting" column.
- **Optimistic locking** → a concurrent edit receives **409 Conflict**.

### 6.5 Self-Approval (D-50)

A Team Leader (or Manager) may approve a quotation they built themselves, **on condition of full transparency**:

| Requirement | Implementation |
|---|---|
| Flag on the quotation | `is_self_approved = true` |
| Approvals screen | Yellow badge: **"Self-approved"** |
| Audit log | Entry typed `SELF_APPROVAL` — not a normal approval |
| Performance report | "Self-approvals" column |
| Manager dashboard | Self-approval count for the period |

### 6.6 Quotation Views for Team Leader & Manager

Toggle at the top: **by employee · by company · flat list**
Fixed horizontal split in every mode: **active quotations · history**
Filters: status · period · employee · customer · currency · amount range
The system remembers the user's last choice.

---

## 7. Suppliers & Catalog

### 7.1 Suppliers

| Field | Notes |
|---|---|
| name · type | supplier / distributor |
| color_rating | **Set manually by any employee** (D-19) |
| phone · contact_person | |
| has_open_account | yes / no |
| linked_quotations | Automatic |

**Colour meanings:**

| Colour | Meaning |
|---|---|
| 🟢 Green | Excellent — reliable and well priced |
| 🟡 Yellow | Average — acceptable with reservations |
| 🔴 Red | Problematic — avoid |
| ⚪ White | New / not yet rated |

The colour appears as a chip beside the supplier name on **every** screen.

### 7.2 Supplier Quotations

| Field | Notes |
|---|---|
| code | `SQ-2026-0001` |
| supplier_id | Colour shown alongside |
| deal_id | **Optional** (D-51) |
| total_price · currency | |
| offer_date · valid_until | |
| pdf_file | Scan or PDF of the offer |
| Line items | Product · **price** · quantity (+ to add more) |
| notes · entered_by | |

**Per D-21:** prices live here. Any new product is added to the catalog automatically, without review (D-22).

### 7.3 Catalog

Descriptive data only — **no prices**. Two tabs: Product · Service, grouped by company/team name.

| Product | Service |
|---|---|
| Product code | Service type (installation · repair · maintenance · setup · extendable) |
| Product name | Service description (optional) |
| Category (for search) | Providing team / company |
| Unit (piece · metre · kilo · extendable) | Active service (yes/no) |
| Description · active product | Notes |

---

## 8. Screens by Role

### Manager
Dashboard · Employees · Customers · Requests/Deals · Quotations · Approvals · Team Visits · Catalog · Suppliers · Supplier Quotations · Reports · Archive

### Team Leader
Dashboard · Sales Team · Customers · My Customers · Requests/Deals · Quotations (including incomplete) · Approvals · Catalog · Suppliers · Supplier Quotations · Reports · Archive

### Outdoor Supervisor
Today's Visits · Visit History · Customers (visit customers) · **Requests (outdoor scope)** · Catalog · Reports

> The Requests screen here covers requests generated by their team's visits. Within the outdoor scope they may create, edit and change the status of those deals (3.4), and they can see a request was handed over and marked "done". They **cannot** approve or reject a request, assign a sales employee, confirm delivery completion, or see any deal after handover (D-44).

### Outdoor Sales (mobile + desktop)
Today's Visits · Visit History · Customers · Quotations · Catalog · Suppliers · Supplier Quotations

### Indoor Sales
Customers · Deals · Quotations · Catalog · Suppliers · Supplier Quotations · Reports

### Procurement
Assigned Deals · Negotiation Log · Quotations · Catalog · Suppliers · Supplier Quotations · Reports

### CEO
Dashboard · Customers · **Quotations (read-only)** · Reports — no drill-down

### Super Admin
22 administrative screens (section 13) — completely hidden

---

## 9. Workflows

### Flow 0 — Login
No sign-up. Accounts are created by the Manager or Super Admin only.
- Wrong credentials → error message · after 5 failures → account locked + Super Admin notified.
- Deactivated account → "Account suspended, please contact administration".
- Password change → verification code by email → new password → log in again.
- **8-hour idle session** (D-29) · **8-character password with letters and numbers** (D-28).

### Flow 1 — The Deal (master flow)

| # | Step | Status |
|---|---|---|
| 1 | Customer source: Outlook/WhatsApp · Outdoor visit · Employee entry (needs approval) | — |
| 2 | Team Leader opens Requests, enters the deal, assigns an employee | Lead |
| 3 | Deal appears for the employee flagged as the current deal | Contacted |
| 4 | Employee requests a quote from the supplier | Supplier RFQ |
| 5 | Records the supplier offer; new products enter the catalog | Supplier Quotation |
| 6 | Builds the customer quotation (Draft) → submits for approval | Pending |
| 7 | Team Leader approves / edits and approves / returns with a note | Approved |
| 8 | Sends to the customer, PDF generated and stored | Quotation Sent |
| 9 | Customer response: Accepted / Partial / Counter / Rejected | Depends |
| 10 | Handover to Procurement | Won → Purchasing |
| 11 | Negotiation and saving calculation | Purchasing |
| 12 | Fulfilment and delivery | Delivery Complete |

### Flow 2 — Outdoor Visits
Area assignment → visit booking → appears under "Today's Visits" on mobile → the visit → form completion.

**Only 3 mandatory fields:** company name · contact person · outcome.
Everything else is yes/no, checkboxes or dropdowns.

**Outcomes:**
- **Rejected** → logged as a failure with a mandatory reason → feeds the rejected-companies report.
- **Open** → stays open with a follow-up date.
- **Successful** → the customer becomes a prospect; if they place a request, it is routed automatically to the Team Leader's Requests list and marked "done" for the Outdoor employee. **The Supervisor's role ends here.**

### Flow 3 — Employee-Entered Request (needs approval)
Appears for the Team Leader as "Pending Approval" → approved (activated and assigned) or rejected (reason + notification).

### Flow 4 — Approvals
Described in 6.4 and 6.5.

### Flow 5 — Procurement
Receives the deal (Won) → reviews the current supplier offer → negotiates:
- **Success** → new price recorded → saving calculated → added as extra profit → logged as a successful attempt.
- **Failure** → logged as a failed attempt with a reason → the deal proceeds at the original price.

Then Delivery → Delivery Complete → procurement report + accounts report (manual PDF — D-23).

### Flow 6 — Recording a Supplier Offer
Described in 7.2.

### Flow 7 — Archiving

| Type | Trigger | Effect | Restore |
|---|---|---|---|
| Automatic | Final quotation rejection | Quotation moves to the quotation archive · **the customer stays in the list** | Manager |
| Manual | Manager / TL only | Customer disappears from the customer list | Manager / TL (individually or select-all) |

**No customer is ever permanently deleted.**

### Flow 8 — Reports
See section 11.

### Flow 9 — Employee Management
The Manager adds: name · email · role · phone · WhatsApp · active status → account created automatically and credentials emailed.
Later changes: role change · deactivation · reassigning their customers.

### Flow 10 — Reassigning a Customer
Change the sales owner → the customer and their full history move → both employees notified → audit entry written.

---

## 10. Edge Cases

### 10.1 Deactivated Employee With Open Work (D-34)

| Item | Behaviour |
|---|---|
| Accounts | **Deactivated, never deleted** — `is_active = false` |
| Customers and deals | Remain attached to the deactivated employee |
| Access | Team Leader and Manager retain full access |
| Reassignment | The Team Leader moves them whenever they choose (no automatic transfer) |
| Login | Blocked — "Account suspended, please contact administration" |
| Reports | Their historical reports remain in the record |

**Required:** a **"Customers of deactivated employees"** filter on the customer screen for Team Leader and Manager.

### 10.2 Duplicate Customers (D-35)
- On save, the system compares the name against existing records (**fuzzy match** with normalisation of hamza, taa marbuta and yaa forms).
- Similarity above the threshold → **yellow warning** listing the similar customers.
- The employee may proceed, or open the existing customer instead. **No blocking and no automatic merge.**
- 📌 Duplicate merging → post-MVP.

### 10.3 Supplier Price Change After the Quotation Was Built (D-36)

| State | Behaviour |
|---|---|
| Quotation in **Draft or Pending** | Keeps its original price + **red warning**: "Supplier price has changed — review pricing" |
| Quotation **Sent or beyond** | **Completely unaffected** — fixed snapshot |
| "Refresh prices" button | Draft only — recalculates and writes an audit entry |

**Same logic applies to** an expired supplier offer → warning beside the supplier name at selection time.

### 10.4 Deactivated Product/Supplier Used in an Open Quotation (D-37)

| Situation | Behaviour |
|---|---|
| Open quotations | The product **stays functional**; calculations are unaffected |
| Warning | Yellow warning: "This product is deactivated in the catalog" |
| New quotations | **Hidden** from selection lists |
| Search | Appears flagged "deactivated" if explicitly searched |

### 10.5 Additional Cases

| Case | Decision |
|---|---|
| Quotation passed `valid_until` | **Expired** automatically — the employee issues a new one or records Rejected with reason "no response" |
| Customer with two concurrent deals | Two independent deals with separate statuses and quotations (D-01) |
| Concurrent edits on one quotation | **409 Conflict** + "This quotation has changed, please refresh" |
| Suppliers in different currencies | Converted at the FX rate captured at creation; quotation issued in one currency (D-09) |
| Product with no recorded supplier price | **Save blocked** with a clear message |
| Quantity above the supplier's recorded amount | **Red warning** — not a block |
| Incomplete Excel import | Flagged "incomplete" with a dedicated filter, and **excluded from financial reports** until completed |

---

## 11. Reports

### 11.1 Principles
- A report is a **fixed snapshot** — its figures at submission time, with a stored PDF.
- Most data is **auto-filled**; the employee reviews, adds notes, and submits.
- **Cycles are system-wide**, counted from the go-live date (D-27).

### 11.2 Types

| Type | Code | Generation | Author |
|---|---|---|---|
| Deal report | RPT-DL | Manual | Sales · Procurement · TL · Manager |
| Daily | RPT-DY | Semi-automatic | All employees |
| Weekly | RPT-WK | Automatic | All employees |
| Monthly overview | RPT-MN | Automatic | System → Manager + CEO |
| Accounts | RPT-FN | Manual | TL · Manager · Procurement |
| Employee performance | RPT-PF | Manual | TL · Manager · Outdoor Supervisor |
| Visits | RPT-VS | Manual / weekly | Outdoor Sales · Supervisor |
| Procurement | RPT-PR | Manual / monthly | Procurement |

### 11.3 Common Fields
Report code · title · type · **date range (mandatory)** · author · created date · issue date · recipients (multi-select, filtered by permission) · status · **free-text notes (every type)** · attachments.
Actions: save as draft · submit · export PDF · export Excel.

### 11.4 Lifecycle
```
Draft → Submitted → Viewed → Acknowledged → Archived
                       ↘ Returned (mandatory reason) → Draft (v2)
```
- After Acknowledged the report is **permanently read-only**, even for its author.
- The previous version remains available for comparison.
- **No deletion** — automatic archiving after six months, still viewable and exportable.
- The same type, period and employee cannot be submitted twice — a warning asks: edit the existing one or create a new version?

### 11.5 Timing
- **Daily:** deadline from settings · if missed → **Missed** + badge for the Team Leader.
- **Weekly:** automatic draft · if not approved within 24 hours → submitted flagged **"Not reviewed"**, shown prominently.
- Working days **Sunday → Thursday** — deadlines skip the weekend.

---

## 12. Dashboards

### 12.1 General Rules
- **Role-based** — each role gets its own metrics.
- Shared time filter: weekly · monthly · yearly · custom.
- **Drill-down for Manager and Team Leader only.**
- Each currency reported separately; base-currency aggregation is an optional toggle.
- **Pre-aggregation mandatory** — summary tables refreshed by jobs.

### 12.2 Manager Dashboard

**Overview:** Revenue (per currency) · Gross Profit · Net Profit · customer count · deal count · open · closed · pending approvals · returned quotations · **self-approval count**

**Sales:** Sales funnel · conversion rate · win/loss rate · sales by employee · sales by sector · average deal size · average sales cycle · employee workload · best- and worst-selling products · top 20 customers · rejected-companies report with reasons

**Procurement:** total and percentage saving · average saving per deal and per buyer · original vs negotiated price · successful negotiation count · extra profit · supplier comparison · most-used and lowest-priced suppliers

**Finance:** monthly revenue · monthly profit · profit margin % · revenue by currency · **delivery cost**

**Outdoor:** visit counts (successful/rejected) · prospects generated · conversion rate · per-employee performance · average visit duration

**❌ Deferred (D-25):** Outstanding Payments · Cheques Due
**⚠️ Behind feature flag:** Sales by Area (D-20) · Most-delayed supplier

### 12.3 Team Leader Dashboard
Customer and deal counts · open/closed · pending approvals · returned quotations · **sales by employee (deal count, not value)** · employee workload · conversion/win/loss per employee · sales funnel · average sales cycle · rejected companies · Outdoor summary

### 12.4 CEO Dashboard
Same time filter — **no drill-down**.
**Not shown:** pending approvals · returned quotations · sales by employee/area · employee workload · best/worst products · rejected companies · procurement detail · individual Outdoor detail

---

## 13. Super Admin Screens

Completely hidden from all users.

**1. System Dashboard:** user count · active sessions · today's deals and quotations · database size · storage · RAM · CPU · Redis/Queue/Realtime/Meilisearch/mail status · backup status · version · last deployment/backup/error

**2. Users:** create · deactivate · reactivate · reset password · force logout · change role · **Login As (mandatory logging)** · last login · devices · IP · browser

**3. Roles & Permissions (RBAC):** create new roles · granular permissions with scope

**4. System Settings:** company name · logo · address · phone numbers · default currency · default tax · language · time zone · date format · PDF and email templates

**5. Currencies & FX:** base currency · manual rate per currency · **rounding unit per currency** · rate history · staleness alert

**6. Limits & SLAs:** stale-deal threshold · daily report deadline · quotation approval SLA · weekly review window · maximum file size

**7–22:** Database management · Audit logs · Error centre · Queue monitor · Notification centre · Backup centre · Storage manager · Performance monitor · Security centre · Scheduler · Feature flags · Integrations · API manager · Maintenance mode · Developer tools · System analytics

---

## 14. Architecture & Technical Requirements

### 14.1 Principles

| # | Principle |
|---|---|
| AP-01 | **Modular monolith first** — not microservices |
| AP-02 | Clear module boundaries: Identity · Customers · Deals · Quotations · Suppliers · Catalog · Procurement · Outdoor · Reports · Notifications · Audit · Admin |
| AP-03 | Layer separation: Presentation → Application → Domain → Infrastructure |
| AP-04 | **All calculations in the backend** |
| AP-05 | Event-driven internally |
| AP-06 | Append-only for critical data |
| AP-07 | API-first — web and mobile share the same API |
| AP-08 | **Config over code** |
| AP-09 | Idempotency for every critical operation |
| AP-10 | Fail-safe — an external service outage is not a system outage |

**Rule:** modules communicate through interfaces and events only.

### 14.2 Stack

| Layer | Technology |
|---|---|
| Application | Laravel (D-57) — modular monolith, queues, scheduler, managed migrations |
| Database | PostgreSQL — ACID · JSONB · partitioning |
| Cache/Queue | Redis — cache · sessions · queue · rate limiting · locks |
| Search | Meilisearch (D-48) — Arabic search with hamza, taa marbuta and yaa normalisation |
| Realtime | WebSocket |
| Storage | Local file system behind an abstraction layer |
| Web | Vue 3 + TypeScript SPA on Vite (D-67), consuming `/api/v1` — full RTL/LTR support |
| Mobile | PWA via `vite-plugin-pwa` (D-67) — online only; the service worker caches the app shell, never business data |

### 14.3 Security

| # | Requirement |
|---|---|
| SEC-01 | No sign-up |
| SEC-02 | Argon2 or bcrypt · **8 characters, letters and numbers** |
| SEC-03 | Lockout after 5 failures + Super Admin notification |
| SEC-04 | Mandatory email verification for password changes |
| SEC-05 | **8-hour session timeout** + active device list + force logout |
| SEC-06 | Two-factor authentication ready in the architecture |
| SEC-07 | **Dynamic RBAC** stored in the database |
| SEC-08 | Row-level security |
| SEC-09 | Permission checks at the API, not the UI |
| SEC-10 | Login As restricted to Super Admin, with mandatory logging |
| SEC-11 | Rate limiting on login and the API |
| SEC-12 | Backend input validation on every field |
| SEC-13 | SQL injection / XSS / CSRF protection |
| SEC-14 | HTTPS mandatory even internally + encryption at rest for sensitive data |
| SEC-15 | File upload: type · size · true MIME · stored outside the web root · **virus scanning** |
| SEC-16 | IP blacklist + failed login log |
| SEC-17 | Secrets kept out of code and encrypted |

### 14.4 Network & External Access (D-59)

The **company LAN is the primary access path** for every role · the server opens **no inbound port**; external reach is provided by an **outbound Cloudflare Tunnel** · **Cloudflare Access** gates identity before the application and is limited to five named users (CEO · Manager · Outdoor Sales) · Access is a gate, **not** a substitute for system authentication or the permission matrix (`SEC-07`, `SEC-09`) · Access session lifetime **≥ 8 hours** to match `D-29` and avoid a double login for field staff · **a clear, specific message** when external access is unavailable — not a generic error · session persistence so a brief drop does not force a logout · lightweight payloads for mobile.

> A Cloudflare outage stops **external** access only; the LAN keeps working, satisfying `AP-10`.

### 14.5 Performance

| # | Target |
|---|---|
| PRF-01 | API P95 < 500 ms on the internal network |
| PRF-02 | First screen load < 2 seconds |
| PRF-03 | Search < 300 ms |
| PRF-04 | PDF generation **async** |
| PRF-05 | Dashboards served from pre-aggregated tables |
| PRF-06 | N+1 queries eliminated via mandatory eager loading |
| PRF-07 | Slow-query logging |
| PRF-08 | Caching for catalog · suppliers · permissions · settings |

### 14.6 PDF Generation

Template editable from the Super Admin screen without code · **full Arabic support with embedded fonts** · async generation in a queue · mandatory storage · immutable snapshot · **supplier names and prices excluded unconditionally** · company logo and details from settings · retry on failure with notification.

> ⚠️ **Highest technical risk** — Arabic glyph shaping. Prototype in week one.

### 14.7 API

| # | Requirement |
|---|---|
| API-01 | RESTful with clear resource naming |
| API-02 | Versioning (`/api/v1/`) from day one |
| API-03 | Consistent response envelope for success and errors |
| API-04 | Pagination mandatory on every list endpoint — no "return all" |
| API-05 | Unified filtering / sorting / search query parameters |
| API-06 | Server-side grouping (by employee / company) |
| API-07 | Bulk operations for archive and restore |
| API-08 | Rate limiting per user and per endpoint |
| API-09 | API keys + webhooks |
| API-10 | API logs — who, when, from which IP |
| API-11 | Auto-generated OpenAPI documentation |
| API-12 | Optimistic concurrency — 409 Conflict |

### 14.8 Audit & Logging

| # | Requirement |
|---|---|
| AUD-01 | Comprehensive audit — create/update/delete/approve/transfer |
| AUD-02 | Recorded: user · event · entity · old value · new value · time · IP · device |
| AUD-03 | **Immutable** — no edits or deletions · **retained permanently** (D-30) |
| AUD-04 | Mandatory critical events (section 3.12) |
| AUD-05 | Structured logging — JSON with correlation ID |
| AUD-06 | Error centre — grouped errors with stack traces |

### 14.9 External Integrations (D-56)

**In MVP:**

| Service | Use |
|---|---|
| **SMTP** | Account credentials · verification codes · critical alerts to Super Admin **only** |

**Formally deferred — behind feature flags:**

| Service | Note |
|---|---|
| WhatsApp Business API | Customers contact via WhatsApp, but **entry is manual** |
| OpenAI / AI services | Fully isolated |
| Mapbox | Regions are free text for now |
| Outlook | Customer source is **manual entry** |
| Push (FCM/APNs) | Ships with the notification centre |

**Rules:**
1. The Integrations screen shows deferred services as **"not enabled"** — not as errors or outages.
2. **Adapter pattern** — every external service behind an interface from day one.
3. **Circuit breaker** · **retry with backoff** · **fail-safe (AP-10)**.

### 14.10 Development & Deployment

| # | Requirement |
|---|---|
| DEV-01 | **Three environments:** development · staging · production |
| DEV-02 | Clear branching: `main` (production) · `develop` · `feature/*` |
| DEV-03 | **Automated migrations** — each with a tested `down` path |
| DEV-04 | **Rollback plan** — revert to the previous release in under 15 minutes |
| DEV-05 | Announced maintenance window (zero-downtime not required for MVP) |
| DEV-06 | **Maintenance mode** with an allowlist of users |
| DEV-07 | Testing: unit for calculations (**pricing is top priority**) · integration for workflows · E2E for critical paths |
| DEV-08 | **Seed data:** roles · permissions · sectors · units · currencies · test users |
| DEV-09 | Developer tools: clear cache · reindex · test email/PDF · export logs |
| DEV-10 | Code standards + automated linting |
| DEV-11 | **Written runbooks:** restore · startup · deployment · rollback |

### 14.11 ERP Readiness

| # | Requirement |
|---|---|
| ERP-01 | Clean module boundaries — each module extractable as a service |
| ERP-02 | Room for what's next: inventory · accounting · HR · production · logistics |
| ERP-03 | Accounts as a future role with full screens |
| ERP-04 | Amount and currency structures ready for accounting |
| ERP-05 | Catalog designed to link to inventory later |
| ERP-06 | Unified, extensible document numbering |
| ERP-07 | Feature flags to roll out modules gradually |
| ERP-08 | Custom fields without schema changes |

---

## 15. Scheduled Jobs

The single reference list. All appear in **Queue Monitor** and **Scheduler**, with timings from settings.

| # | Job | Frequency | Purpose | Catch-up |
|---|---|---|---|---|
| J-01 | `expire_quotations` | Daily | Move quotations past `valid_until` to **Expired** | ✅ |
| J-02 | `recompute_customer_status` | Nightly + event | Recalculate customer status (4.5) | ✅ |
| J-03 | `detect_stale_deals` | Daily | Flag stale deals per the configured threshold | ✅ |
| J-04 | `daily_report_deadline_check` | Daily at deadline | Mark daily reports **Missed** | ✅ |
| J-05 | `generate_weekly_reports` | Weekly | Generate a draft per employee | ✅ |
| J-06 | `auto_submit_unreviewed_reports` | Daily | Submit unreviewed reports after 24 hours | ✅ |
| J-07 | `generate_monthly_report` | Monthly | Monthly report for Manager and CEO | ✅ |
| J-08 | `archive_old_reports` | Daily | Archive after six months | ✅ |
| J-09 | `refresh_dashboard_aggregates` | Hourly + full nightly | Refresh summary tables | ❌ recomputes |
| J-10 | `database_backup` | Daily | Full backup | ✅ |
| J-11 | `cleanup_orphan_files` | Weekly | Remove files not linked to any entity | ❌ |
| J-12 | `fx_rate_staleness_alert` | Weekly | Alert on stale FX rates | ❌ |
| J-13 | `reindex_search` | Nightly + on demand | Rebuild the index *(after Meilisearch)* | ❌ |
| J-14 | `storage_threshold_check` | Daily | Alert when storage exceeds the limit | ❌ |
| J-15 | `ensure_audit_partitions` | Daily | Create the coming months' `audit_log` partitions (`D-72`), arm each one's `TRUNCATE` guard, and fail loudly if the default partition holds a row | ❌ idempotent |

### 15.1 Queues by Priority

| Queue | Contents | Priority |
|---|---|---|
| `critical` | Recovery · system health | 1 |
| `pdf` | PDF generation | 2 |
| `reports` | Report generation | 3 |
| `maintenance` | Cleanup · indexing · aggregates | 4 |

**Rules:** every job is **idempotent** · a fixed retry count then `Failed` in Queue Monitor · every run logged with time and outcome.

> **`J-15` runs in the scheduler, not on a queue** — recorded 2026-08-22 with `D-72`, **premise
> corrected 2026-08-23**. It is the one exception to the line above, and deliberately so: Horizon is
> not installed, so a queued `J-15` has no Queue Monitor to be seen failing in, and `audit_log` could
> quietly run out of months — the exact failure it exists to prevent. This note previously gave a
> second reason, that the worker services sat behind a compose profile off by default; **that profile
> was removed, and `docker compose up -d` now starts all four workers.** The conclusion is unchanged
> and the remaining reason still holds, but the retired one is struck rather than left standing.
> Moving `J-15` onto `maintenance` is owed once Queue Monitor exists, and is on the deployment-debt
> register until then.
> `J-15` needs no catch-up entry (`D-55`): it is idempotent and always creates from *today* forward, so a
> run missed for a week is repaired by the next one rather than by replaying the ones that did not happen.

---

## 16. Operations, Backup & Monitoring

### 16.1 Ownership (D-54)

| Responsibility | Owner |
|---|---|
| Hardware · UPS · RAID · standby machine · network · Cloudflare Tunnel setup | **Server administrator** — outside the development scope |
| The system comes back up correctly after any restart | **The system** ✅ |
| Scheduled backups from within the system | **The system** ✅ |
| Storing backups on a separate machine/disk | **Server administrator** |

### 16.2 Automatic Startup Requirements

| # | Requirement |
|---|---|
| ST-01 | All services **enabled on boot** — no manual startup |
| ST-02 | **Startup order:** PostgreSQL → Redis → Meilisearch → application → workers → Nginx |
| ST-03 | Each service waits for its dependency (dependency + retry) |
| ST-04 | **Automatic health check** after startup, with the result logged |
| ST-05 | **Catch-up jobs (D-55)** — jobs missed during downtime run on startup |
| ST-06 | **Queue durability** — pending jobs survive a restart |
| ST-07 | **No half-written data** — mandatory transactions |
| ST-08 | A `/health` endpoint reporting each service explicitly |
| ST-09 | **A written startup checklist**, executed and recorded after each boot |

### 16.3 Backup

| # | Requirement |
|---|---|
| BK-01 | Daily backup: **database + files + Meilisearch** |
| BK-02 | On-demand manual backup |
| BK-03 | Retention: last 7 daily + last 4 weekly (configurable) |
| BK-04 | Each backup carries a **checksum + success/failure record** |
| BK-05 | **A failed backup triggers an immediate alert** (in-app + email) |
| BK-06 | **A real restore test every month** |
| BK-07 | The restore procedure is **written step by step** in a runbook |
| BK-08 | Backups copied to a separate machine/disk — arranged with the server administrator |

### 16.4 Monitoring

| # | Requirement |
|---|---|
| OBS-01 | **System health:** CPU · RAM · disk · PostgreSQL · Redis · queue · Meilisearch · realtime · storage |
| OBS-02 | **Queue monitor:** pending and failed jobs + retry / stop / delete |
| OBS-03 | **Scheduler view:** every job, its status, next run and last run |
| OBS-04 | **Error centre:** stack trace + page + user + frequency |
| OBS-05 | **Real-time metrics:** active users · response time · cache hit rate · slow queries |
| OBS-06 | **Alerting:** critical error · failed backup · service down · storage threshold |
| OBS-07 | ⚠️ **External monitoring (heartbeat)** — the system pings a service outside the server. **Without it, a "server down" alert never sends, because the server is down** |
| OBS-08 | **Usage analytics:** most-used pages and operations · most active users |

---

## 17. Files & Attachments

| Item | Decision |
|---|---|
| **Permission** | Attachment permission = permission on the parent entity (D-38) |
| **Parent link** | A pivot table per parent — `deal_files`, `supplier_quotation_files`, `purchase_order_files`, `report_files` — with foreign keys both ways. **Not a polymorphic column**, which no foreign key can constrain (D-71) |
| **Maximum size** | **30 MB** (configurable) — D-71, superseding the 10 MB in D-39 |
| **Allowed types** | PDF · JPG · PNG · WEBP · DOCX · XLSX — D-40 |
| **Validation** | **True MIME type, not the extension** — a spoofed `.pdf` is rejected |
| **Virus scanning** | Mandatory on every upload |
| **Naming** | UUID — the original name is stored in the database for display |
| **Path** | `/{year}/{month}/{entity_type}/{entity_id}/{uuid}.ext` — **outside the application directory** |
| **Access** | **No direct access** — every file served through a permission-checking API |
| **Compression** | Images compressed automatically on upload |
| **Cleanup** | Orphan cleanup job (J-11) |
| **Backup** | Files are part of the backup set |

---

## 18. Notifications

### 18.1 In MVP: Badges and Inline Validation Only

| Mechanism | Use |
|---|---|
| Badge counters | Sidebar: Requests · Approvals · Reports · My Quotations |
| Status columns | Within screens |
| Inline validation (red) | Quantity above supplier stock · missing price · expired supplier offer · deactivated product |
| Audit log | Record-keeping |

### 18.2 Email — The Only Exception

| Code | Event |
|---|---|
| MAIL-01 | Verification code for password change |
| MAIL-02 | Password changed |
| MAIL-03 | Account status changed |
| MAIL-04 | New account credentials (part of account creation) |
| MAIL-05 | Critical failures to the Super Admin |

> **Code namespace rule:** `MAIL-xx` identifies an outgoing email event in this section only. `SEC-xx` always refers to the security requirements in 14.3 and is never reused here.

### 18.3 Post-MVP: The Full Notification Centre

Channels: in-app (default) · push (Outdoor only) · email (exception).
Priorities: Critical (banner) · High (toast + badge) · Normal (badge) · Low (list only).

**Business rules:**
- **Aggregation by default** — repeated notifications collapse into one with a counter.
- **Daily budget** per user; the overflow collapses into an end-of-day summary.
- **Auto-dismiss on action** · **no confirmation notifications**.
- **Escalate once** — no reminder ladders.
- **Badge before notification** · **validation ≠ notification**.
- Quiet hours apply to push only · dedup · retry · **no deletion**.
- **An employee never receives a notification about a customer that isn't theirs.**

---

## 19. Open Decisions & Risks

### 19.1 Open Decisions

| # | Decision | Owner | Blocks |
|---|---|---|---|
| ~~OD-01~~ ✅ | ~~Are additional items taxable?~~ **Closed 2026-08-12: no.** Delivery and installation are outside the tax base; only the item subtotal is taxed | — | — |
| **OD-03** 🔴 | Server specifications | Server administrator | Resource sizing |
| ~~OD-02~~ ✅ | ~~PDF template~~ — **closed by `D-79`** (2026-09-13): the `P-01` prototype template is the approved design, with its three carried-forward items named there as Module 9 build work | — | — |
| ~~OD-04~~ ✅ | ~~VPN type and concurrent connection capacity~~ — **closed by D-59**: Cloudflare Tunnel + Access, 5 users | — | — |
| OD-05 🟡 | Expected daily workload | Management | Queue and storage sizing |
| OD-06 🟡 | Company holiday calendar | HR | Reports |
| OD-07 ⬜ | Criteria for automatic red supplier rating | Procurement | Post-MVP |
| OD-08 ⬜ | Fuzzy-match threshold for customer names | Empirical | Tuned after the first 100 customers |

> **OD-01 is closed.** `OD-03` no longer blocks the *start* of development — `D-66` moves work onto a production-matched
> local environment — but it still blocks the server itself, and with it `P-02` and every item on the deployment-debt register.

### 19.2 Risks

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R-01 | **Single server, no standby** | Medium | **Very high** | Daily off-server backup + tested restore procedure + automatic startup (16.2) |
| R-02 | Arabic glyph shaping in PDF | **High** | High | Prototype in week one, before any other code |
| R-03 | Free-text regions → unreliable Sales by Area | **High** | Medium | Auto-suggestions + feature flag + convert to a managed list later |
| R-04 | Excel import with missing data | Certain | Medium | "Incomplete" flag + filter + exclusion from financial reports |
| R-05 | Failure alerts sent from the failed server itself | Medium | High | **External heartbeat** (OBS-07) |
| R-06 | Outdoor team on weak connectivity (no VPN client since D-59, which lowers this) | Medium | Medium | Local auto-save · image compression · retry · connection indicator |
| R-07 | No approval escalation → stalled quotations | Medium | Medium | Red badge + "days waiting" column |
| R-08 | Catalog quality with open editing | Medium | Medium | Audit + monthly review + tighten by configuration if needed |
| R-09 | Deferring search to the end | Medium | Medium | **SearchService** from the Customers module (details in the build plan) |

---

## 20. Post-MVP Backlog

| Item | Related decision |
|---|---|
| Full notification centre (in-app + push) | D-33 |
| Follow-ups and a task entity | D-15 |
| Payment schedule and collections → unlocks Outstanding Payments and Cheques Due | D-26 · D-25 |
| Purchase order to supplier | D-13 |
| Accounts as a full role with dedicated screens | D-23 |
| Expected and actual delivery dates → unlocks "most-delayed supplier" | — |
| Automatic supplier colour rating | OD-07 |
| Regions as a managed list | R-03 |
| Duplicate customer merging | 10.2 |
| Manual override of customer status | 4.5 |
| Structured communication log (calls/emails) | D-16 |
| Multiple contacts per customer | D-18 |
| Automatic integration with Outlook · WhatsApp API · AI · Mapbox | D-56 |
| Offline mode for mobile | — |
| ERP modules: inventory · accounting · HR · production | ERP-02 |

---

> **Status: ready for sign-off.** Any future change is recorded as a new decision in section 2, not as a silent edit to the prose.
