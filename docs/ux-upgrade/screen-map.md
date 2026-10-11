# F-42 · Screen map

F-42 · 1.1, 2026-10-12. This map covers the main screens in scope for the UI/UX upgrade, the roles that reach them, and the
main flow through them. It lists the out-of-scope screens in one line each so the map is complete. **It reads the code
as it is on `main` (`102c355`).** It decides nothing and proposes nothing.

## Sources

| Fact | Read from |
|---|---|
| Routes, guards | `crm/resources/js/router/index.ts` (`meta.requiredPermission`) |
| Who holds a permission, and at what scope | the dev database, `role_permissions ⋈ roles ⋈ permissions`; the query is quoted in the PR |
| Personas, main screens | `docs/User_Personas_EN.md` P-01…P-08 |
| States a screen renders | a `grep` of each page for `<LoadingState`, `<EmptyState`, `<ErrorState`, `<PermissionDeniedState` |
| Links between screens | the `name: '<route>'` targets inside each page |

### Seeded grants for the screens' permissions

| Permission | Roles and scope |
|---|---|
| `customer.view` | CEO all · Manager all · Team Leader team · Outdoor Supervisor out · Outdoor Sales own · Indoor Sales own · Procurement asgn |
| `deal.view` | CEO all · Manager all · Team Leader team · Outdoor Supervisor out · Outdoor Sales own · Indoor Sales own · Procurement asgn |
| `quotation.view` | CEO all · Manager all · Team Leader **all** · Outdoor Sales own · Indoor Sales own · Procurement all |
| `quotation.create`, `quotation.edit` | Manager all · Team Leader team · Outdoor Sales own · Indoor Sales own |
| `quotation.approve` | Manager all · Team Leader team |

**The Super Admin holds none of these** (9 grants in all, every one `admin.*`), so these screens refuse it.
The audit reaches them through Login As.

**Scopes that reach no rows.** The router's own comments say three scopes resolve to nothing:
- On deals, `team`, `out` and `asgn` (Deals Point 2.1). Team Leader, Outdoor Supervisor and Procurement are admitted to
  `/deals` and see an empty list.
- On approvals, `team` (`D-a`). The Team Leader sees an empty list.

So for those roles the "empty" state *is* the screen. On `customer.view` the same scopes are decided per row by
`CustomerRowScope`. That is not measured here, and 2.2 measures it.

## Roles

Each role's landing route is from `LANDING_ROUTE` in `router/index.ts`.

| Role (persona) | Landing route as written | What actually opens |
|---|---|---|
| Super Admin (P-01) | `admin` | `home` (`Ping.vue`): the route does not exist, so `FALLBACK_ROUTE` applies |
| CEO (P-02) | `dashboard` | `home`, same reason (Dashboard is Module 14) |
| Manager (P-03) | `dashboard` | `home` |
| Team Leader (P-04) | `dashboard` | `home` |
| Outdoor Supervisor (P-05) | `visits-today` | `home` (Visits is Module 12) |
| Outdoor Sales (P-06) | `visits-today` | `home` |
| Indoor Sales (P-07) | `customers` | `/customers` |
| Procurement (P-08) | `assigned-deals` | `home` (Module 11) |

**Seven of the eight roles land on the `Ping` placeholder.** That is a fact for the audit (2.2/2.3), not a finding yet.

## In-scope screens

Overlap key: **F-41** means F-41 · 1.6/1.7/1.8 are open, so no change is allowed until F-41 closes.
**F-37/F-33** means the owner's open Arabic manual test lists (F-37 · 1.5, F-33 · 1.3) walk this screen, and a change
here makes those lists stale.

| # | Screen | Route | File | Roles (scope) | States rendered | Overlap |
|---|---|---|---|---|---|---|
| S1 | Shell: sidebar, context bar, impersonation banner | every signed-in route | `App.vue`, `components/AppSidebar.vue`, `components/AppContextBar.vue`, `components/identity/ImpersonationBanner.vue` | all; sidebar items filtered by `navigation.ts` `permission` | — | — |
| S2 | Shared states | — | `components/states/{Loading,Empty,Error,PermissionDenied}State.vue` | all | itself | — |
| S3 | Login | `/login` (`guestOnly`, `bare`) | `pages/auth/LoginView.vue` | everyone, signed out | none of the four components: an inline `role="alert"` error, field errors, a disabled button with a spinner while pending | — |
| S4 | Refused page | `/403` | `pages/ForbiddenView.vue` | any role sent there by a guard | PermissionDenied | — |
| S5 | Deals list | `/deals` | `pages/deals/DealsView.vue` (+ `DealFormModal`, `DealStatusControl`, `DealApprovalControls`) | `deal.view`: CEO, Mgr all · TL, OSup, Proc empty · OSales, ISales own | Loading, Empty, Error, Denied | **F-41** (1.7 adds the owner filter here) |
| S6 | Deal page | `/deals/:id` | `pages/deals/DealDetailView.vue` (+ `DealDocumentsPanel`, status and approval controls) | `deal.view` as S5; timeline section on `deal.view_timeline` | Loading, Error, Denied (no Empty) | **F-41** (1.6c changed it on 2026-10-12) |
| S7 | Quotations list | `/quotations` | `pages/quotations/QuotationsView.vue` (+ `CustomerPicker`) | `quotation.view`: CEO, Mgr, TL, Proc all · OSales, ISales own | Loading, Empty, Error, Denied | F-37/F-33 |
| S8 | Quotation builder (create · edit · edit-and-approve) | `/quotations/new?deal=`, `/quotations/:id/edit`, `/quotations/:id/edit-and-approve` | `pages/quotations/QuotationBuilderView.vue` (+ `SupplierQuotationFormModal`) | create/edit: Mgr, TL, OSales, ISales · edit-and-approve: Mgr, TL | Loading, Error, Denied | F-37 (terms list) |
| S9 | Quotation page | `/quotations/:id` | `pages/quotations/QuotationDetailView.vue` (+ `QuotationPdfPanel`, `QuotationPurchaseOrder`) | `quotation.view` as S7; actions decided per action by the API (`SEC-09`) | Loading, Error, Denied | F-37/F-33 (PDF, response form) |
| S10 | Customers list | `/customers` | `pages/customers/CustomersView.vue` (+ `CustomerFormModal`, `ImportModal`, `ConfirmDialog`, `DealOwnerPicker`) | `customer.view`: CEO, Mgr all · TL team · OSup out · OSales, ISales own · Proc asgn | Loading, Empty, Error, Denied | — |
| S11 | Customer page | `/customers/:id` | `pages/customers/CustomerDetailView.vue` (+ `CustomerFormModal`, `DealOwnerPicker`) | as S10; a row outside scope answers 404 (`OpenAPI §5.1`) | Loading, Error, Denied | — |
| S12 | Approvals | `/approvals` | `pages/approvals/ApprovalsView.vue` | `quotation.approve`: Mgr all · TL empty (`D-a`) | Loading, Empty, Error, Denied | — |
| S13 | Purchase orders | `/purchase-orders` | `pages/purchase-orders/PurchaseOrdersView.vue` | `quotation.view` as S7 (owner, Q-A) | Loading, Empty, Error, Denied | — |

## Main flows

Each flow is read from the route targets each page links to. Nothing here was walked in the browser yet; 2.2 walks them.

1. **Request → quotation (Indoor/Outdoor Sales, Manager, TL).**
   - S10 Customers → S11 Customer (a record) → S5 Deals → `DealFormModal` (new deal) → S6 Deal page.
   - From S6, «new quotation» opens S8 (`quotation-new?deal=`). S8's save leads to S9.
   - The list → detail links found are S5→S6, S7→S9, S10→S11. **S11 has no link to that customer's deals.** Its header comment
     (`CustomerDetailView.vue:32-35`) says so because Module 5's deals "do not exist". Module 5 closed on 2026-09-09,
     so the comment is stale. 2.2 confirms it on screen.
2. **Approval (Manager; TL lands on an empty list).**
   - S12 Approvals → S9 Quotation (read) or S8 in edit-and-approve mode → back to S9.
   - Approve and reject on a deal are `DealApprovalControls` on S5/S6.
3. **Customer response → PO (the quotation's owner, Manager).**
   - S9 → `QuotationPdfPanel` (generate, download) → the response form on S9 (accept/partial/counter/reject; reason,
     PO reference, PO date) → `QuotationPurchaseOrder` on S9 → S13 lists the POs and links back to S9.
4. **Read-only oversight (CEO).** S7 / S9 / S10 / S11 / S5 / S13, with no write action. Procurement reads S7/S9 at
   `all`, while its deals list (S5) is empty.
5. **Sign-in.** S3 → landing route. For seven roles that is the `Ping` page, so every flow above starts from the sidebar.

## Out of scope (later round)

One line each, so every route in `router/index.ts` is accounted for.

| Route | File | Permission |
|---|---|---|
| `/` (home) | `pages/Ping.vue` | signed in |
| `/suppliers` | `pages/suppliers/SuppliersView.vue` | `catalog.view` |
| `/supplier-quotations` | `pages/supplier-quotations/SupplierQuotationsView.vue` | `supplier_quotation.view` |
| `/catalog` | `pages/catalog/CatalogView.vue` | `catalog.view` |
| `/users` | `pages/users/UsersView.vue` | `admin.create_user` |
| `/roles` | `pages/roles/RolesMatrixView.vue` | `admin.manage_roles` |
| `/account/security` | `pages/profile/AccountSecurityView.vue` | signed in |
| `/settings` | `pages/settings/SystemSettingsView.vue` | `admin.fx_rates` (sections by their own permissions) |
| `/managed-lists` | `pages/lists/ManagedListsView.vue` | signed in |
| `/:pathMatch(.*)*` | redirect to the landing chain (no 404 page) | — |

**On `home`:** the screen is out of scope, but it is where seven roles land. Whether that belongs in this round is the
owner's call at 2.3.

Not routed, therefore not mapped: `CurrenciesView.vue` and `SystemLimitsView.vue` (sections of `/settings`).
