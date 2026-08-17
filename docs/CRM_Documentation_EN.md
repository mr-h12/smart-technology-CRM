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
| 2 | Decision Log (61 decisions) |
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
| D-06 | Rounding applies to the **final total only** |
| D-52 | **Rounding unit is per currency**, configurable (EGP = 1 · USD/EUR = 0.01) |
| D-07 | Discount is a **percentage of the `subtotal` only** (the sum of line totals, per 5.2) — never applied per line or to additional items. It is subtracted **after** tax, not before (`D-60`) |
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
| D-61 | **Primary keys are UUID** (recorded 2026-08-12). Every business table uses a UUID primary key, generated application-side as a time-ordered UUID so inserts stay sequential and indexes do not fragment. This satisfies `OpenAPI §2` ("opaque UUID identifiers") with one key rather than a numeric key plus a public UUID, keeps IDs non-enumerable — which matters in a system whose permissions are row-scoped — and lets a module be extracted later without ID collisions (`ERP-01`). Human-readable business codes (`DL-…`, `QT-…`) stay separate fields as the contract requires. Cost accepted: 16 bytes against 8, and slightly slower joins; at this system's scale neither is the bottleneck |
| D-60 | **Tax is calculated before the discount is applied** (recorded 2026-08-12). The tax base is `subtotal + additional_total`, tax is computed on it, and the discount is subtracted afterwards — matching the company's actual purchase orders, verified against PO #226 to the piastre. This **supersedes the ordering in the previous §5.2**, where the discount reduced the base before tax. `D-07` is unchanged in what the discount is a percentage *of* (the subtotal); only where it is subtracted has moved. ⚠️ The source document labels this line **إشعار خصم** (credit note), which in accounting is a separate instrument adjusting an already-issued invoice rather than a discount on the sale. If that is what it is, the discount does not belong on the quotation at all — confirm with the accountant |
| D-59 | **External access uses Cloudflare Tunnel + Access, not a VPN** (recorded 2026-08-12). The company LAN remains the primary access path for every role. External access is limited to five named users — CEO, Manager, and Outdoor Sales. The server opens no inbound port; the tunnel connection is outbound from the server. Cloudflare Access is an identity gate **in front of** the application and **never replaces** the system's own authentication or its permission matrix (`SEC-07`, `SEC-09`); its session lifetime is at least 8 hours so field staff are not forced through two logins a day (`D-29`). **Supersedes `OD-04`**, revises §1 and §14.4, changes the `P-02` criterion from "through VPN" to "through Cloudflare", and reframes the Module 12 message from "VPN disconnected" to "connection unavailable" |
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

tax_base            = subtotal + additional_total      ⚠️ open decision OD-01
tax_amount          = tax_base × tax_percent / 100     ← tax BEFORE discount (D-60)

discount_amount     = subtotal × discount_percent / 100          ← (D-07)
net_amount          = subtotal + additional_total − discount_amount   ← revenue excl. tax

total_before_round  = tax_base + tax_amount − discount_amount    ← (D-60)

final_total         = round(total_before_round, currency unit)   ← (D-06, D-52)
rounding_diff       = final_total − total_before_round           ← stored
```

> **Worked example — the company's PO #226**, which this ordering reproduces exactly:
> `subtotal 7,368.42` · `tax 14% → 1,031.58` · `discount 1% → 73.68` ·
> `total 8,326.32`. Discounting first would give `8,316.00` — a different tax base.

### 5.3 Rounding Unit per Currency (D-52)

| Currency | Default unit |
|---|---|
| EGP | 1 pound |
| USD | 0.01 dollar |
| EUR | 0.01 euro |

Editable under `System Settings → Currencies`. Rounding applies to the **final total only**.

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
| Web | SPA with full RTL/LTR support |
| Mobile | PWA — online only |

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

### 15.1 Queues by Priority

| Queue | Contents | Priority |
|---|---|---|
| `critical` | Recovery · system health | 1 |
| `pdf` | PDF generation | 2 |
| `reports` | Report generation | 3 |
| `maintenance` | Cleanup · indexing · aggregates | 4 |

**Rules:** every job is **idempotent** · a fixed retry count then `Failed` in Queue Monitor · every run logged with time and outcome.

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
| **Maximum size** | 10 MB (configurable) — D-39 |
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
| **OD-01** 🔴 | Are additional items taxable? (assumption: yes) | Accountant | Quotations |
| **OD-03** 🔴 | Server specifications | Server administrator | Resource sizing |
| OD-02 🟡 | PDF template | You | PDF module |
| ~~OD-04~~ ✅ | ~~VPN type and concurrent connection capacity~~ — **closed by D-59**: Cloudflare Tunnel + Access, 5 users | — | — |
| OD-05 🟡 | Expected daily workload | Management | Queue and storage sizing |
| OD-06 🟡 | Company holiday calendar | HR | Reports |
| OD-07 ⬜ | Criteria for automatic red supplier rating | Procurement | Post-MVP |
| OD-08 ⬜ | Fuzzy-match threshold for customer names | Empirical | Tuned after the first 100 customers |

> **Only OD-01 and OD-03 actually block the start.**

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
