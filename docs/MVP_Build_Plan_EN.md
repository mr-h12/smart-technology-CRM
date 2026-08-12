# MVP Build Plan

> **Single reference:** `CRM_Documentation_EN.md`
> **Status:** Final — ready for sign-off
> **Arabic:** reading-only translation in `arabic/`; not a maintained companion (D-58)

---

## 0. Week Zero — Before Any Code

### 0.1 Two Mandatory Prototypes

| # | Prototype | Why now | Success criterion |
|---|---|---|---|
| P-01 | **Arabic PDF** | Highest technical risk (R-02). If the library can't shape Arabic correctly, you need to know before building 15 modules | A PDF with a full Arabic paragraph, an items table and numbers — correctly shaped, no broken glyphs |
| P-02 | **Deploy to the real server** | External access, permissions and fonts are what surprise you, and the worst time to discover them is after you've finished | A "Hello" page running on the server, opened from a phone over Cloudflare (D-59) |

> **Do not start any module until both succeed.**

### 0.2 One-Time Documents

| # | Document | Status |
|---|---|---|
| 1 | Functional and non-functional requirements | ✅ In `CRM_Documentation_EN.md` |
| 2 | Architecture document | ✅ Section 14 |
| 3 | Permission matrix | ✅ Section 3 |
| 4 | **User personas** — one page per role (8 roles): goal · daily pain points · what they need from the system | ⬜ Before the design system |
| 5 | **Design system** — colours · typography · spacing · components (RTL/LTR) | ⬜ Before the first screen |
| 6 | **OpenAPI contract** — unified response and error shape | ⬜ Before the first endpoint |

---

## 1. Module Template

```
1. User story + acceptance criteria
2. Table and relationship design
3. Migration (up + down)
4. API endpoints
5. Frontend
6. Write and run test cases
7. Definition of done
```

**Acceptance criteria format:**
```
Given  [initial state]
When   [action]
Then   [expected result]
```

**Definition of done:**
- [ ] All acceptance criteria pass
- [ ] Permission checks enforced at the API, not just the UI
- [ ] Audit log records this module's operations
- [ ] Loading / empty / error states on every screen
- [ ] Screen works in both RTL and LTR
- [ ] Migration runs and reverses cleanly (up + down)
- [ ] Deployed to the server and the smoke test passes

---

## 2. Modules

> The order is deliberate — each module depends on the ones before it. Settings, currencies and permissions come **before** quotations, because quotations need all three.

### Module 0 — Foundation
**Infrastructure — no user story**

- Project structure (backend + frontend) · database connection · migration tooling
- **i18n layer from day one** — no hard-coded strings (Arabic + English)
- **Design system** + base RTL/LTR layout
- **Storage abstraction layer** + `files` table
- **Audit log as a cross-cutting layer** — active automatically for every module that follows
- **Queue + jobs infrastructure** — the four queues (critical · pdf · reports · maintenance)
- **Seed data** — roles · permissions · sectors · units · currencies · test users

✅ **Tests:** app runs · frontend talks to backend · database connects · switching language flips direction · a test job executes from the queue

---

### Module 1 — Identity & Permissions (Dynamic RBAC)
**User story:** As a team member, I want to log in with my email and password, so that I can access the system with my role's permissions.

**Tables:** `users` · `roles` · `permissions` · `role_permissions` · `user_sessions`

**Endpoints:**
```
POST /api/v1/auth/login · logout · change-password
GET  /api/v1/auth/me
CRUD /api/v1/roles · /api/v1/permissions · /api/v1/users
```

**Frontend:** login page · role-based redirect · protected routes · role and permission management screen

**Acceptance criteria:**
- Given valid credentials → Then redirect to the role's default screen
- Given 5 failed attempts → Then the account locks and an email is sent
- Given a session idle for 8 hours → Then automatic logout
- Given a permission removed from a role → When the user calls the API directly → Then **403**
- Given a password under 8 characters or digits only → Then rejected with a clear message
- Given a deactivated employee → Then "Account suspended, please contact administration"

🚀 **First deployment point:** deploy to the real server here — not at the end.

---

### Module 2 — Settings, Managed Lists & Currencies
**User story:** As a system administrator, I want to configure company details, currencies and lists, so that the system runs on the company's real data.

**Tables:** `settings` · `currencies` (+ rounding unit) · `fx_rates` + history · `enum_lists` (sectors · units · service types · delivery terms) · `system_limits` (stale threshold · report deadline · approval SLA · max file size)

**Acceptance criteria:**
- Given an FX rate edit → Then the old rate remains in history + a mandatory audit entry
- Given a new sector added in settings → Then it appears in the customer form **without a deployment**
- Given a currency's rounding unit changes → Then only new quotations are affected

---

### Module 3 — Customers
**User story:** As a sales employee, I want to add and view my assigned customers, so that I can manage my deals.

**Tables:** `customers` · `import_batches`
**Plus `SearchService` (search abstraction)** — first implementation `PostgresSearchDriver` (ILIKE)

**Endpoints:** CRUD + `PATCH /:id/archive` · `PATCH /:id/assign` · `POST /import`

**Frontend:** role-filtered list · add/edit form · detail page · archive (individual + select all) · **Excel import** · **"customers of deactivated employees" filter**

**Acceptance criteria:**
- Given a sales employee → Then they see only their own customers · Team Leader sees their team · Manager sees all
- Given an Excel import with missing fields → Then the record saves flagged **"incomplete"** and appears in a dedicated filter
- Given a name similar to an existing customer → Then a **yellow warning**; the employee decides
- Given a manually archived customer → Then only Manager and Team Leader can see them
- Given a change of sales owner → Then the customer and full history transfer + an audit entry

---

### Module 4 — Catalog & Suppliers
**User story:** As a sales employee, I want to browse the product/service catalog and the supplier list, so that I can build a quotation.

**Tables:** `catalog_items` · `suppliers`

**Acceptance criteria:**
- Given a new product → Then it appears under the Product tab, grouped by company
- Given a red-rated supplier → Then the red chip appears beside their name on **every** screen
- Given a service → Then it appears under the Service tab, separate from products
- Given a deactivated product → Then it is hidden from new selection lists

---

### Module 5 — Requests / Deals ⭐
**User story:** As a Team Leader, I want to enter customer requests and assign them to employees, so that work flows down the right path.

**Tables:** `deals` · `deal_documents` · `deal_status_history`
**Plus the customer-status engine** (`recompute_customer_status`)

**Endpoints:**
```
CRUD  /api/v1/deals
PATCH /api/v1/deals/:id/assign · /approve · /reject · /status
POST  /api/v1/deals/:id/documents
```

**Frontend:** requests screen · entry form · "My Customers" with a current-deal marker · **deal timeline**

**Acceptance criteria:**
- Given a customer with an active deal → When a new request is added → Then **two independent deals** with separate statuses
- Given a deal reaches Won → Then the customer's status becomes **"Customer"** automatically and permanently
- Given all of a customer's deals are Lost → Then their status is **"Deal Not Completed"**
- Given an employee-entered request → Then it appears for the Team Leader as "Pending Approval" and stays inactive until approved
- Given a rejected request → Then a mandatory reason + a badge for the employee
- Given a status change → Then a timeline entry with old status, new status, who and when

---

### Module 6 — Supplier Quotations
**User story:** As a sales employee, I want to record a supplier's price offer and attach their file, so that I can use it to build the customer's quotation.

**Tables:** `supplier_quotations` (with **nullable** `deal_id`) · `supplier_quotation_items`

**Acceptance criteria:**
- Given an offer containing a product not in the catalog → Then the product is added automatically
- Given the offer is saved → Then it appears automatically on the supplier page under "Linked Quotations"
- Given an offer not linked to a deal → Then it saves normally and is available to any deal
- Given a file upload → Then type, size and true MIME are validated + stored under a UUID name

---

### Module 7 — Customer Quotations ⭐ (the hardest module)
**User story:** As a sales employee, I want to build a price quotation for my customer using supplier prices and a profit margin, so that I can send it after my Team Leader's approval.

**Tables:** `quotations` · `quotation_items` · `quotation_additional_items` · `user_term_suggestions`

**Endpoints:**
```
POST  /api/v1/quotations
GET   /api/v1/quotations/:id
PATCH /api/v1/quotations/:id/submit-for-approval
POST  /api/v1/quotations/:id/new-version
GET   /api/v1/quotations?group_by=employee|customer
```

**Frontend:** quotation builder — dynamic suppliers via (+) up to 10 · products per supplier · live calculation · confirmation preview before saving · SmartTermInput for terms

**Acceptance criteria (the most important in the project):**
- Given cost 1000 and margin 20% → Then selling price is 1200 automatically
- Given quotation margin 20% and line margin 30% → Then the line uses 30%
- Given suppliers in different currencies → Then conversion at the current FX rate and one quotation currency
- Given a total of 1234.67 EGP → Then the final total is 1235 and `rounding_diff` = 0.33
- Given a total of 1234.678 USD → Then the final total is 1234.68 (rounding unit 0.01)
- Given a quantity above the supplier's recorded amount → Then an **inline red warning**
- Given a product with no recorded price → Then **save is blocked**
- Given the supplier price changed after the quotation was built (Draft) → Then a warning + a "refresh prices" button
- Given the employee and Team Leader edit at the same moment → Then **409 Conflict**

---

### Module 8 — Approvals
**User story:** As a Team Leader, I want to review quotations and adjust tax and margin before approving, so that I protect the company's margin.

**Endpoints:** `PATCH /:id/approve` · `/return` · `/edit-and-approve`

**Acceptance criteria:**
- Given a tax or margin edit → Then a **mandatory audit entry** with old and new values
- Given a returned quotation → Then a mandatory note + the quotation returns to Draft and appears under "Incomplete"
- Given a Team Leader approving their own quotation → Then `is_self_approved = true` + a **yellow badge** + a `SELF_APPROVAL` audit entry
- Given a quotation waiting beyond the SLA → Then a red badge + a "days waiting" column
- Given Team Leader and Manager → Then **the same screen and the same authority**

---

### Module 9 — PDF Generation
**User story:** As a sales employee, I want to produce a professional PDF quotation, so that I can send it to the customer.

**Acceptance criteria:**
- Given a quotation with 3 suppliers → Then **no supplier name or price appears anywhere in the PDF**
- Given PDF generation → Then async on the `pdf` queue + stored against the quotation + a fixed snapshot
- Given generation failure → Then automatic retry + notification to the employee
- Given `show_delivery_terms = false` → Then that section is omitted
- Given the CEO → Then they can **download** the existing PDF but not generate a new one

---

### Module 10 — Customer Response & Purchase Orders
**User story:** As a sales employee, I want to record the customer's response, so that the deal moves along the correct path.

**Tables:** `purchase_orders` (auto `po_number` + free-text `customer_po_reference` + date + attachment)

**Acceptance criteria:**
- Given Partial or Counter → Then a full copy is saved automatically and the employee edits the new version
- Given Counter or Rejected → Then **a mandatory reason** before the status is accepted
- Given Rejected → Then the quotation is archived · **the customer stays in the list** · the deal becomes Lost
- Given `valid_until` passes with no reply → Then **Expired** automatically (J-01)
- Given a purchase order → Then search works on both the internal number and the customer's reference

---

### Module 11 — Procurement
**User story:** As a procurement employee, I want to renegotiate with the supplier and calculate the final profit, so that the company earns the best possible margin.

**Tables:** `procurement_negotiations`

**Acceptance criteria:**
- Given a successful negotiation from 1000 to 900 → Then a saving of 100 is recorded and added to final profit
- Given a failed negotiation → Then a failed attempt with a reason, and the deal proceeds at the original price
- Given procurement opens a quotation → Then they see **cost, margin and profit**
- Given Delivery Complete → Then any of the four can confirm it **with attribution**

---

### Module 12 — Outdoor Visits 📱
**User story:** As an Outdoor employee, I want to record a visit outcome from my phone with minimal typing, so that I can finish my day quickly.

**Tables:** `visits` · `visit_areas`

**Acceptance criteria:**
- Given the visit form → Then **only 3 mandatory fields**: company name · contact person · outcome
- Given typing while the connection drops → Then the draft is saved locally and not lost
- Given a "successful" outcome with a request → Then the request routes automatically to the Team Leader and is marked "done"
- Given a rejection → Then a mandatory reason, feeding the rejected-companies report
- Given external access is unavailable → Then **a clear, specific message**, not a generic error (D-59)
- Given the Supervisor → Then they see visits only, and **cannot see** any deal after handover

---

### Module 13 — Reports
**User story:** As an employee, I want to submit my reports so they reach my Team Leader, so that management can track the work.

**Tables:** `reports` · `report_recipients` · `report_versions`
**Jobs:** J-04 · J-05 · J-06 · J-07 · J-08

**Acceptance criteria:**
- Given a submitted report → Then a **fixed snapshot + stored PDF**, unchanged even if the underlying data changes
- Given a returned report → Then a mandatory reason + a v2 version, with the original retained
- Given Acknowledged → Then **read-only even for the author**
- Given the same type, period and employee → Then a warning and a choice
- Given the CEO → Then view, export and comment only — **no acknowledge, no return**
- Given the server was down on the weekly report day → Then the job **runs on startup** (catch-up)

---

### Module 14 — Dashboard
**User story:** As a Manager, I want to see sales and profit for a period, so that I can track performance.

**Tables:** summary tables (pre-aggregation) — job J-09

**Acceptance criteria:**
- Given a selected period → Then the figures match the underlying data exactly
- Given two currencies → Then each is shown separately, with aggregation as an optional toggle
- Given the CEO → Then **no drill-down** on any figure
- Given Sales by Area → Then it stays behind a feature flag
- ❌ **Not in MVP:** Outstanding Payments · Cheques Due

---

### Module 15 — Search (Meilisearch)
**User story:** As a user, I want to search in Arabic quickly and still find results even if I misspell something.

**Implementation:** swap `PostgresSearchDriver` for `MeilisearchDriver` inside `SearchService`

**Acceptance criteria:**
- Given a search for "احمد" → Then "أحمد" is found (hamza normalisation)
- Given a minor typo → Then results still appear (typo tolerance)
- Given a sales employee → Then they search only within their own customers (permission filtering)
- Given Meilisearch is down → Then the system falls back to ILIKE automatically; **the screen does not break**
- Given a create or update → Then indexing happens immediately via a job

---

## 3. ⚠️ Mandatory Condition on Deferring Search (D-48)

Deferring Meilisearch to the end is acceptable **on one condition**: every search call in the codebase goes through `SearchService` from Module 3.

```
SearchService.search(index, query, filters)
   ├── first implementation: PostgresSearchDriver (ILIKE)
   └── final implementation: MeilisearchDriver
```

Without that layer, adding Meilisearch at the end means **rewriting every screen with a search bar**.

📏 **Measurement trigger:** if customer search exceeds **500 ms** at any point, pull Module 15 forward immediately rather than waiting.

---

## 4. After All Modules

### 4.1 End-to-End Testing
- Full journey: Lead → Contacted → RFQ → supplier offer → customer quotation → approval → PDF → purchase order → procurement → delivery
- Parallel journey: one customer with two concurrent deals — to confirm the separation holds
- **Restart test:** power the server down and back up → all services come up on their own and missed jobs run

### 4.2 Final Server Setup
> Deployment started at Module 1, so this is production hardening, not the first deploy.

- PostgreSQL + Redis + Meilisearch on the server
- Nginx as reverse proxy + SSL
- Process manager with **enabled on boot** for every service, in the correct order
- **Daily backups: database + files + Meilisearch — on a separate machine/disk**
- **A real restore test** — not just confirming the file exists
- **External heartbeat monitoring**
- Runbooks: restore · startup · deployment · rollback

### 4.3 Pilot Rollout
- **Import legacy data from Excel** + a session to complete the incomplete records manually
- **User training** — one session per role
- **Two weeks running in parallel** with the current process before full cutover

### 4.4 Testing Documentation (from real experience, not guesswork)
Test strategy · test plan

### 4.5 Process Documentation (last)
Strategy roadmap · tech roadmap · release roadmap · metrics (time per module vs. estimate · bug count · test coverage) · standards and guidelines

---

## 5. MVP Success Criteria (D-47)

### 5.1 Primary Criterion
> **80% of quotations are produced in the system within one month of go-live.**

### 5.2 Supporting Criteria

| Criterion | Target | Measured from |
|---|---|---|
| Share of quotations produced in the system | ≥ 80% | System count ÷ actual count |
| Active customers registered | 100% | Review with the Team Leader |
| Daily reports submitted | ≥ 70% of working days | Reports table |
| Outdoor visits logged from mobile | ≥ 80% | Visits table |
| Average quotation approval time | ≤ 1 working day | Quotation timeline |
| Open critical bugs | Zero | Error centre |
| Unplanned downtime | Zero | System health |

### 5.3 If the Criterion Isn't Met
That isn't failure — it's a prompt to find the **cause**: slowness? insufficient training? a form that's too long? The next step follows from the cause, **not from adding new features**.

---

## 6. Sign-Off Checklist

### Required before writing code 🔴

| # | Item | Owner |
|---|---|---|
| OD-01 | **Are additional items taxable?** — assumption: yes | Accountant |
| OD-03 | **Server specifications** | Server administrator |

### Required during the build 🟡

| Item | Needed before |
|---|---|
| User personas | Design system |
| Design system | First screen |
| OpenAPI contract | First endpoint |
| PDF template (OD-02) | Module 9 |
| ~~VPN configuration (OD-04)~~ — closed by D-59 | — |
| Company holiday calendar (OD-06) | Module 13 |

### First three steps after sign-off

1. **Arabic PDF prototype** (P-01) — highest risk, before any other code
2. **Trial deployment to the server** (P-02) — so external-access and font issues surface early
3. **Module 0** — foundation + i18n + audit + queue + seed
