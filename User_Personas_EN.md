# CRM System — User Personas

## Purpose

This document defines the eight system roles for product design, workflow design, and acceptance testing. It is based only on `CRM_Documentation_EN.md` and `MVP_Build_Plan_EN.md`.

Use it before creating the design system and any role-specific screen. It does not create permissions: the CRM documentation's permission matrix remains authoritative.

## Shared Context

- The CRM is internal, on-premise, and used by tens to hundreds of employees.
- Arabic and English, including RTL/LTR, are required from the first release.
- Office roles primarily use desktop web. Outdoor Sales uses mobile PWA as well as desktop.
- The system is online-only. External access requires VPN; a clear VPN-disconnected state is required.
- Working days are Sunday through Thursday.

---

## P-01 — Super Admin

| Attribute | Definition |
|---|---|
| Role | Developer and complete system administrator; hidden from all ordinary users. |
| Primary goal | Keep the system secure, available, correctly configured, and recoverable. |
| Core responsibilities | User lifecycle; dynamic roles and permissions; settings; FX rates; limits and SLAs; audit; database; queues; backups; integrations; maintenance; system health. |
| Main screens | 22 administrative screens: System Dashboard, Users, RBAC, Settings, Currencies & FX, Limits & SLAs, Database, Audit, Error Centre, Queue Monitor, Notification Centre, Backup, Storage, Performance, Security, Scheduler, Feature Flags, Integrations, API Manager, Maintenance Mode, Developer Tools, and System Analytics. |
| Key information needed | Service health, backup status, failed jobs, errors, active sessions, storage, FX-rate staleness, audit history, and configuration history. |
| Critical actions | Create/deactivate/reactivate users; reset password; force logout; change role; Login As; configure roles, settings, currencies, limits, feature flags, and maintenance mode. |
| Guardrails | Login As must be logged. All configuration changes are audited. The Super Admin must never appear in user-facing employee lists. |
| Design implications | Dense operational dashboard, explicit status per dependency, irreversible-action confirmation, visible audit references, and clear failure/retry actions. |

## P-02 — CEO

| Attribute | Definition |
|---|---|
| Role | External observer with read-only access across the business. |
| Primary goal | Understand company performance without operating the day-to-day CRM. |
| Core responsibilities | Review dashboards, customers, quotation PDFs, and reports; comment on reports only. |
| Main screens | Dashboard, Customers, read-only Quotations, Reports. |
| Key information needed | Revenue per currency, profit, customer/deal counts, high-level trends, and received reports. |
| Critical actions | View records, download existing quotation PDFs, export reports, and comment on reports. |
| Guardrails | No drill-down in dashboards. No create, edit, approve, acknowledge, return, or status-change action. CEO downloads an existing PDF and never generates a new one. |
| Design implications | Executive summaries first, restrained navigation, unmistakable read-only state, and no controls that imply edit authority. |

## P-03 — Manager

| Attribute | Definition |
|---|---|
| Role | Highest operational authority with organization-wide scope. |
| Primary goal | Control sales and procurement performance, unblock work, and maintain data quality. |
| Core responsibilities | Manage customers, deals, quotations, approvals, employees within permitted roles, visits, reports, archive, FX rates, and team audit visibility. |
| Main screens | Dashboard, Employees, Customers, Requests/Deals, Quotations, Approvals, Team Visits, Catalog, Suppliers, Supplier Quotations, Reports, Archive. |
| Key information needed | Pending and returned quotations, self-approval count, deal pipeline, profit/saving, employee workload, reports, stale work, and archived/deactivated-owner records. |
| Critical actions | Assign customers/deals, approve/reject/edit-and-approve quotations, confirm delivery completion, create eligible employee accounts, deactivate accounts, set FX rates, archive/restore, and acknowledge/return/comment on reports. |
| Guardrails | May not create Manager, CEO, or Super Admin accounts. Quotation edits to tax or margin must be audited. Self-approval must be explicit and tracked. |
| Design implications | Operational dashboard with drill-down, filters by employee/team/period, approval queue with waiting-age indicators, bulk archive/restore, and strong confirmation/audit feedback. |

## P-04 — Team Leader

| Attribute | Definition |
|---|---|
| Role | Owner of a sales team with team-scoped authority. |
| Primary goal | Distribute incoming work, maintain quotation quality, and keep team deals moving. |
| Core responsibilities | Own team customers/deals, assign sales owners, review quotations, approve/reject employee-entered requests, see team reports, and manage team archive. |
| Main screens | Dashboard, Sales Team, Customers, My Customers, Requests/Deals, Quotations including incomplete records, Approvals, Catalog, Suppliers, Supplier Quotations, Reports, Archive. |
| Key information needed | Assigned/current deals, pending requests, team quotation queue, returned and self-approved quotations, employee workload, stale deals, incomplete imported customers, and team reports. |
| Critical actions | Create/assign/edit team customers and deals, approve/reject requests, approve/edit-and-approve/return quotations, assign areas where permitted, archive/restore team customers, and acknowledge/return/comment on received reports. |
| Guardrails | Scope is team-only where the matrix says Team. No automatic escalation replaces their quotation review. Returned quotations require a note; own quotation approval must be labelled and audited. |
| Design implications | Team-centric filters by default, employee grouping, approval-age emphasis, workload visibility, and rapid reassignment without losing customer history. |

## P-05 — Outdoor Supervisor

| Attribute | Definition |
|---|---|
| Role | Supervisor of outdoor visits; responsibility ends at customer handover. |
| Primary goal | Monitor outdoor activity and ensure visits are assigned and recorded correctly. |
| Core responsibilities | View outdoor visits, create/edit visit customers and outdoor deals within outdoor scope, assign areas, submit/receive reports, and view handover state. |
| Main screens | Today's Visits, Visit History, Customers (visit customers), Requests (outdoor scope), Catalog, Reports. |
| Key information needed | Today’s visits, visit outcomes, rejected-company reasons, open visits/follow-up dates, successful handovers, and outdoor team reports. |
| Critical actions | Create/edit visit records, customers and deals in outdoor scope, change outdoor deal status, assign visit areas, create reports, and acknowledge/return/comment on received reports. |
| Guardrails | Cannot approve/reject requests, assign sales employees, confirm delivery completion, or access a deal after it is handed over. Must see only outdoor team records. |
| Design implications | Visit-first overview, clear outcome and handover markers, outdoor-scope deal controls without approval or assignment actions, and a deal that disappears cleanly at handover. |

## P-06 — Outdoor Sales

| Attribute | Definition |
|---|---|
| Role | Field salesperson who records visits and continues as a sales owner. |
| Primary goal | Complete visits with minimal typing, convert successful visits into qualified work, and manage own sales activity. |
| Core responsibilities | View/record own visits, create/edit own customers and deals, build and send own quotations, record customer responses, and use catalog/supplier data. |
| Main screens | Today's Visits, Visit History, Customers, Quotations, Catalog, Suppliers, Supplier Quotations; mobile and desktop. |
| Key information needed | Assigned visits, visit form status, own customers/deals/quotations, supplier offers, product availability and warnings, and current quotation state. |
| Critical actions | Complete visit forms; create/edit own records; submit own quotations for approval; send approved quotations; record customer responses; delete own Draft quotation only. |
| Guardrails | Visit form has exactly three mandatory fields: company name, contact person, outcome. A successful request routes to the Team Leader and is marked done for the outdoor employee. No quotation access outside Own scope. |
| Design implications | Mobile-first task flow, concise form controls, clear connection/VPN status, local draft protection during a brief drop, and prominent price/product validation. |

## P-07 — Indoor Sales

| Attribute | Definition |
|---|---|
| Role | Sales employee who manages own customers through the deal and quotation cycle. |
| Primary goal | Move assigned customer requests from contact through quotation and customer response accurately. |
| Core responsibilities | Manage own customers and deals, record supplier offers, prepare quotations, submit them for approval, send approved PDFs, and record customer responses. |
| Main screens | Customers, Deals, Quotations, Catalog, Suppliers, Supplier Quotations, Reports. |
| Key information needed | Own pipeline, deal timeline, supplier offer prices/validity, margin and tax, approval status, quotation versions, and reporting obligations. |
| Critical actions | Create/edit own customers/deals and Draft quotations, adjust cost/margin/tax on own quotations, submit for approval, send approved quotations, record customer response, create supplier quotations, and create reports. |
| Guardrails | Cannot approve quotations. Own quotation editing is Draft-only. Cost and margin are visible on own quotations but must never be included in the customer PDF. |
| Design implications | Deal-centric work queue, clear status progression, quotation builder with backend-confirmed calculations, visible supplier/product warnings, and version history. |

## P-08 — Procurement

| Attribute | Definition |
|---|---|
| Role | Negotiator for deals assigned after they are won. |
| Primary goal | Reduce supplier cost, record savings accurately, and carry the assigned deal through delivery. |
| Core responsibilities | Review assigned deals and quotations, record supplier quotations, negotiate supplier prices, record success/failure and saving, progress purchasing/delivery, and submit reports. |
| Main screens | Assigned Deals, Negotiation Log, Quotations, Catalog, Suppliers, Supplier Quotations, Reports. |
| Key information needed | Assigned deal scope, supplier offer terms and validity, original cost, negotiated cost, saving, final profit, delivery status, and procurement report data. |
| Critical actions | Edit assigned deals, create negotiations, record savings or failed reasons, confirm Delivery Complete for assigned deals, generate/download permissible quotation PDFs, create supplier quotations, and create reports. |
| Guardrails | Sees everything financial for assigned work, but does not create customer quotations or approve them. Failed negotiation requires a reason; the deal proceeds at original price. |
| Design implications | Financial comparison and negotiation log, clearly separated original versus negotiated cost, saving/profit calculation visibility, and delivery handoff tracking. |

## Cross-Persona Design Rules

1. Enforce all permissions at the API; UI visibility is not authorization.
2. Show only the records allowed by the role scope: Own, Team, All, Out, or Asgn.
3. Use badges and inline validation in MVP; the full notification centre is post-MVP.
4. Preserve auditability and record history without exposing confidential supplier or financial data in customer-facing PDFs.
5. Never use persona summaries to broaden a role's documented permissions.
