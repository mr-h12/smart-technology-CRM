# F-42 UI/UX upgrade — build plan (Phase 4)

This is the plan Phase 5 carries out. Every row comes from `audit.md`. Its fix is either the one obvious fix in `design-options.md` §1, or the owner's pick (§3–§8) or answer (§2), all of 2026-10-12. §1 here is the **shared layer**: a fix that lands in a component or stylesheet used by two or more screens. §2 (F-42 · 4.3) takes the rest per screen. §3 (4.3) turns both into Phase 5's points.

**Rules for every row** (CLAUDE.md, `Design_System_EN.md`):
- UI layer only: no business logic, calculation, permission, API or route change.
- Each row's Phase 5 point writes the failing test named under **Test first** before the fix (Delivery Protocol 1).
- Then it runs the **Browser** check through `rtl-ui-verifier`, at Arabic RTL and English LTR × 1280 and 375. The three themes are added where the row involves colour.
- Paths are from the repo root. `js/` stands for `crm/resources/js/`.
- **Source scans** (a rule read from the source files, with no mount) live in **one** spec, `js/__tests__/uiSourceRules.spec.ts`, created by the first row that needs one. Each later row adds a case; none adds a scan file. Scans skip `*.spec.ts`.
- **F-41.** A row flagged F-41 in `design-options.md` §1 touches the deals screens. Its shared part is built in order; **its deals-screen part is built after F-41 · 1.8 closes** (said on the row as "after F-41").

## 1. The shared layer (F-42 · 4.2)

**Order.** The shared layer is built in this order, because each step uses what the one before it leaves:
1. tokens;
2. the focus rule;
3. buttons and the status chip;
4. the dialog shell;
5. feedback and states;
6. list primitives;
7. display, plurals, and the title and focus on navigation;
8. form fields.

### 1.1 Tokens

### A-42 — scrim and `color-scheme` (owner's pick: Option A)
- **Fix:** a 23rd token, `--color-overlay: rgb(0 0 0 / 0.5)`, in each theme block, plus `color-scheme: light` on `:root` and Clean Monochrome and `color-scheme: dark` on Midnight Obsidian.
  - The 12 scrims change from `color-mix(… var(--color-text) 45% …)` to `var(--color-overlay)`. `AppSidebar.vue`'s `backdrop-filter` stand-in goes.
  - The picker list's `shadow-lg` becomes `var(--shadow-2)`.
  - DS §10's "Token changes" is marked applied, and `D-73`'s count reads 23.
- **Files:** `crm/resources/css/tokens.css`, `crm/resources/js/components/AppSidebar.vue`, `crm/resources/js/components/SearchCombobox.vue`, `crm/resources/js/components/users/ConfirmDialog.vue` (and the other 11 scrim files), `docs/Design_System_EN.md`.
- **Test first:** `ThemeTokenTest`: `EXPECTED` gains `overlay`. A new case asserts that each theme declares `color-scheme`, and that the overlay composited on `canvas` is darker than `canvas` in all 3 themes (it fails today in Midnight, measured at 0.03).
- **Browser:** all 3 themes. Open a dialog: the scrim darkens; in Midnight, a native `<select>` and the scrollbar are dark.

### A-63 — one radius per role (owner's pick: Option B)
- **Fix:** §4.2 reads 8 px controls, 12 px cards and 16 px dialogs (`rounded-lg` / `rounded-xl` / `rounded-2xl`).
  - `AppContextBar.vue`'s 5 shell controls change from `xl` to `lg`, and the 6 `2xl` cards (customer detail ×3, login, account security ×2) change to `xl`.
  - The 5 form dialogs change from `max-h-[90vh]` to `max-h-[90dvh]`.
- **Files:** `docs/Design_System_EN.md`, `crm/resources/js/components/AppContextBar.vue`, `crm/resources/js/pages/customers/CustomerDetailView.vue`, `crm/resources/js/pages/auth/LoginView.vue`, `crm/resources/js/pages/profile/AccountSecurityView.vue`, `crm/resources/js/pages/customers/CustomerFormModal.vue` (and the other four `90vh` dialogs).
- **Test first:** a case in `uiSourceRules.spec.ts`: no `rounded-xl` on a control in `AppContextBar.vue`, no `rounded-2xl` outside the dialog panels, the three `state-plate` tiles and the dev page `Ping.vue`, and no `90vh`.
- **Browser:** 1280 and 375. Each role shows one radius, and at 375 Save stays in view in each form dialog.

### A-65 — the dead `--shadow-3`
- **Fix:** the 11 references use `--shadow-2`, which every theme defines.
- **Files:** `crm/resources/js/components/users/ConfirmDialog.vue`, `crm/resources/js/components/imports/ImportModal.vue` (and the other 9 `shadow-3` files).
- **Test first:** `ThemeTokenTest` gains a scan: every `var(--…)` used in `js/` is defined in `tokens.css` (it fails today on `--shadow-3`).
- **Browser:** a dialog in the 3 themes; its elevation is unchanged.

### 1.2 Focus

### A-34 — one global focus rule
- **Fix:** one `:focus-visible` rule in `app.css`: a 2 px outline in `--color-focus-ring` with a 2 px offset. Per-control ring classes that duplicate it go.
- **Files:** `crm/resources/css/app.css`, `crm/resources/js/pages/approvals/ApprovalsView.vue`, `crm/resources/js/components/AppSidebar.vue`.
- **Test first:** a case in `uiSourceRules.spec.ts`: `app.css` holds the `:focus-visible` rule once, using `--color-focus-ring`. jsdom cannot compute a `:focus-visible` style, so the browser walk is the proof that the ring shows.
- **Browser:** a Tab walk on approvals, quotations and the shell: every stop shows the ring, in 3 themes. The deals-screen stops are checked after F-41.

### A-64 — focus rings only for the keyboard
- **Fix:** the `focus:` ring utilities (657 `focus:ring`/`focus:outline` uses in 36 files, measured 2026-10-12; the audit counted 219) become `focus-visible:`, under A-34's rule. Most are deleted outright, because the global rule covers them.
- **Files:** `crm/resources/js/components/SearchCombobox.vue`, `crm/resources/js/pages/customers/CustomersView.vue` (and the other `focus:` files, 36 in all).
- **Test first:** a case in `uiSourceRules.spec.ts`: 0 `focus:ring` / `focus:outline` utilities in `js/` outside `*.spec.ts` (657 today).
- **Browser:** a mouse click shows no ring, and Tab shows it. Deals screens after F-41.

### A-08 — the picker's active option (owner's pick: Option B)
- **Fix:** a 4 px `border-inline-start` bar in `--color-focus-ring` on the active option, text at weight 600, plus today's fill.
- **Files:** `crm/resources/js/components/SearchCombobox.vue`.
- **Test first:** a case in the existing `components/customers/__tests__/CustomerPicker.spec.ts`, which mounts the combobox: ArrowDown sets `aria-activedescendant`, and the active option carries the bar class.
- **Browser:** an arrow-key walk in the customer picker, ar and en (the bar mirrors), 3 themes. The bar must reach 3:1 against the list.

### 1.3 Buttons and status

### A-51 — hover and pressed states
- **Fix:** shared `:hover` and `:active` rules for the secondary button, picker options and the picker's Retry, taken from `QuotationPdfPanel`'s existing `.row-action:hover`.
- **Files:** `crm/resources/css/app.css`, `crm/resources/js/components/pdf/QuotationPdfPanel.vue`, `crm/resources/js/components/SearchCombobox.vue`.
- **Test first:** a case in `uiSourceRules.spec.ts`: the hover rule exists once in `app.css` and no longer in `QuotationPdfPanel.vue`; a case in `CustomerPicker.spec.ts`: Retry renders as a button.
- **Browser:** a mouse hover on a secondary button, an option and Retry, in 3 themes.

### A-33 — action hierarchy (owner's pick: Option B for row Danger)
- **Fix:** DS §6.2's variants. One primary per area; Reject and Delete are Danger; row Danger is text only (`danger` text, no border, the size of the ghost Edit); Discard is told apart from Keep editing.
- **Files:** `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/quotations/QuotationDetailView.vue`, `crm/resources/js/pages/approvals/ApprovalsView.vue`, `crm/resources/js/pages/deals/DealDetailView.vue`.
- **Test first:** one class assertion in each screen's existing view spec: the Archive row action has the danger-text class and no border, Reject is not primary, and each area has one primary button.
- **Browser:** customers, quotation detail and approvals in 3 themes. Deal detail after F-41.

### A-55 — one status chip, no dot (owner's answer)
- **Fix:** one `StatusChip` recipe for customers, deals and quotations, without the dot. The deal approval emoji (⏳ ⛔ ✓) become text in the chip's tone.
- **Files:** `crm/resources/js/pages/deals/DealStatusChip.vue`, `crm/resources/js/pages/quotations/QuotationStatusChip.vue`, `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/customers/CustomerDetailView.vue`, `crm/resources/js/pages/deals/DealApprovalControls.vue`.
- **Test first:** a vitest mount: the customer status renders the same classes on the list and the page, and no chip contains an emoji or a dot element.
- **Browser:** the customer list and page side by side, in 3 themes. Deal chips after F-41.

### 1.4 The dialog shell

### A-04 — native `<dialog>` (owner's pick: Option A)
- **Fix:** every modal uses `<dialog>` with `showModal()`. That gives the focus move, the trap, Escape and the return to the opener without new code; the drawer gets `inert`.
- **Files:** the 12 dialogs, `crm/resources/js/components/users/ConfirmDialog.vue`, `crm/resources/js/components/imports/ImportModal.vue`, `crm/resources/js/components/roles/RoleFormModal.vue`, `crm/resources/js/components/roles/PermissionDiffModal.vue`, `crm/resources/js/components/profile/EmailChallengeModal.vue`, `crm/resources/js/components/users/UserFormModal.vue`, `crm/resources/js/components/users/UserDetailsDrawer.vue`, `crm/resources/js/pages/customers/CustomerFormModal.vue`, `crm/resources/js/pages/suppliers/SupplierFormModal.vue`, `crm/resources/js/pages/catalog/CatalogItemFormModal.vue`, `crm/resources/js/pages/supplier-quotations/SupplierQuotationFormModal.vue`, `crm/resources/js/pages/deals/DealFormModal.vue`; the drawer's `inert` is in `crm/resources/js/components/AppSidebar.vue`.
- **Test first:** `ConfirmDialog.spec.ts` (new): on open, focus is inside; on close, focus returns to the opener. jsdom lacks `showModal`, so the spec stubs it, and the browser check is the real proof.
- **Browser:** a Tab walk stays inside each dialog, Escape closes it from any focus, and focus returns to the opener. The deal dialog after F-41.

### A-09 — Approve asks first (owner's pick: Option A)
- **Fix:** Approve opens A-04's dialog naming the record ("Approve QT-2026-0011?") plus one line on the consequence. Return keeps its note.
- **Files:** `crm/resources/js/pages/approvals/ApprovalsView.vue`, `crm/resources/js/pages/deals/DealApprovalControls.vue`, `crm/resources/js/components/users/ConfirmDialog.vue`.
- **Test first:** `ApprovalsView.spec.ts`: a click on Approve calls no API until the dialog's confirm is pressed.
- **Browser:** approvals as the Manager, in ar and en. The deal approval controls after F-41.

### A-22 — inline forms (owner's pick: B for Return and Delete-confirm, A for the rest)
- **Fix:**
  - The Return note and the Delete confirmation become A-04 dialogs.
  - The other inline forms stay inline, with a focus move on open (`nextTick`), `aria-labelledby`, and focus back on Cancel.
  - The PO form's disabled Record button says why.
- **Files:** `crm/resources/js/pages/approvals/ApprovalsView.vue`, `crm/resources/js/pages/quotations/QuotationDetailView.vue`, `crm/resources/js/pages/quotations/QuotationPurchaseOrder.vue`, `crm/resources/js/pages/deals/DealStatusControl.vue`.
- **Test first:** `ApprovalsView.spec.ts`: Return opens a dialog with a labelled note field focused. `QuotationPurchaseOrder.spec.ts`: the disabled Record button has a visible reason.
- **Browser:** a keyboard walk through Return, Delete and the status form, ar and en. The deal status form after F-41.

### A-30 — busy actions
- **Fix:** a busy label as in `LoginView.vue:177` ("Saving…"); Escape and a scrim click are ignored while busy; the customer-assign button gets a busy flag.
- **Files:** `crm/resources/js/components/users/ConfirmDialog.vue`, `crm/resources/js/pages/auth/LoginView.vue`, `crm/resources/js/pages/customers/CustomerDetailView.vue`, `crm/resources/js/components/imports/ImportModal.vue`.
- **Test first:** `ConfirmDialog.spec.ts`: while busy, the button reads the busy label and Escape does not emit `close`. A customer-assign spec: a second click while busy sends nothing.
- **Browser:** a slowed network (DevTools throttling) on confirm, assign and import.

### 1.5 Feedback and states

### A-31 — results announced (owner's pick: Option A)
- **Fix:** one `role="status"` region beside the action, **present from load** and empty, whose text is set when the result arrives. The assign "done" text clears on the next action.
- **Files:** `crm/resources/js/components/imports/ImportModal.vue`, `crm/resources/js/pages/deals/DealDocumentsPanel.vue`, `crm/resources/js/pages/quotations/QuotationPurchaseOrder.vue`, `crm/resources/js/pages/customers/CustomerDetailView.vue`.
- **Test first:** `ImportModal.spec.ts`: the status region exists before the upload and holds the result after it.
- **Browser:** VoiceOver, or the accessibility tree, reads the import result and the assign result. Deal documents after F-41.

### A-32 — success after approve, return and save (owner's pick: Option A)
- **Fix:** the same always-present `role="status"` region, at the top of the list, because the approved row itself leaves the list.
- **Files:** `crm/resources/js/pages/approvals/ApprovalsView.vue`, `crm/resources/js/pages/deals/DealsView.vue`.
- **Test first:** `ApprovalsView.spec.ts`: after approve, the region reads the success line naming the record.
- **Browser:** approve and return as the Manager. The deal save message after F-41.

### A-41 — loading made visible
- **Fix:** a "Loading…" line while the picker queries and while a supplier block loads, with the block's error shown in the block. The owner picker shows its box only after a refusal, never a raw id.
- **Files:** `crm/resources/js/components/SearchCombobox.vue`, `crm/resources/js/pages/deals/DealOwnerPicker.vue`, `crm/resources/js/pages/quotations/QuotationBuilderView.vue`.
- **Test first:** a case in `CustomerPicker.spec.ts`: while the status is `loading`, the popup shows the loading line, not an empty box.
- **Browser:** a throttled network on the customer picker and a builder supplier block. The deal owner picker after F-41.

### A-43 — errors with a cause and a next step (owner's pick: Option B)
- **Fix:** the next step is a button under the sentence, `ErrorState`'s existing shape.
  - Raw `error.message` ("Failed to fetch") is replaced by a translated line.
  - The out-of-scope 404 uses one wording.
  - /403 gets a way onward, and detail errors keep the record header.
- **Files:** `crm/resources/js/components/states/ErrorState.vue`, `crm/resources/js/components/states/PermissionDeniedState.vue`, `crm/resources/js/pages/approvals/ApprovalsView.vue`, `crm/resources/js/pages/customers/CustomerDetailView.vue`, `crm/resources/js/pages/deals/DealDetailView.vue`.
- **Test first:** `StateComponentsTest`, plus an `ErrorState` vitest: a network failure renders the translated sentence and a button, never the raw message.
- **Browser:** offline mode on approvals and a customer, a stale record id, and /403, ar and en. Deal detail after F-41.

### 1.6 List primitives

### A-13 — filters, sort and page in the URL
- **Fix:** list state lives in `route.query` through `router.replace`, so Back and a shared link restore it.
- **Files:** `crm/resources/js/pages/quotations/QuotationsView.vue`, `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/purchase-orders/PurchaseOrdersView.vue`, `crm/resources/js/pages/deals/DealsView.vue`.
- **Test first:** a `QuotationsView` spec: setting a filter calls `router.replace` with the query, and mounting with a query restores the filter.
- **Browser:** filter, open a record, press Back: the filter is kept. Deals after F-41.

### A-14 — reload without losing the table (owner's pick: Option B)
- **Fix:** while reloading, the table stays at 50 % opacity with `aria-busy="true"`, and a `role="status"` line reads «جارٍ تحديث القائمة…» / "Updating the list…". `LoadingState` stays for the first load only.
- **Files:** `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/quotations/QuotationsView.vue`, `crm/resources/js/pages/purchase-orders/PurchaseOrdersView.vue`, `crm/resources/js/pages/deals/DealsView.vue`, `crm/resources/js/pages/deals/DealDetailView.vue`.
- **Test first:** a `CustomersView` spec: on the second load, the table is still in the DOM with `aria-busy="true"`, and focus stays on the pager.
- **Browser:** page with the keyboard: focus and scroll are kept. Deals after F-41.

### A-39 — cards below 640 (owner's pick: Option A)
- **Fix:** below 640 px each row becomes a card of labelled lines with its actions.
- **Files:** `crm/resources/js/pages/approvals/ApprovalsView.vue`, `crm/resources/js/pages/quotations/QuotationsView.vue`, `crm/resources/js/pages/quotations/QuotationDetailView.vue`, `crm/resources/js/pages/deals/DealsView.vue`.
- **Test first:** a source and mount spec: each row renders its label text for the card form, and the actions are inside the row.
- **Browser:** at 375, approvals and quotations have no sideways scroll and Approve is reachable. Deals after F-41.

### A-40 — a sticky header that sticks (owner's pick: Option A, with A-39)
- **Fix:** the frame becomes `overflow-x: clip`, and the header sticks at `top:` the context bar's height (57 px at 1280, 109 px at 375).
- **Files:** `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/quotations/QuotationsView.vue`, `crm/resources/js/pages/deals/DealsView.vue`.
- **Test first:** a case in `uiSourceRules.spec.ts`: no `<thead class="sticky …">` inside an `overflow-x-auto` frame.
- **Browser:** scroll a long customer list at 1280 and 375: the header stays under the bar. Deals after F-41.

### A-48 — filter bar only when useful (owner's pick: Option A)
- **Fix:** the bar shows when the list has data or a filter is active. It is hidden when the user is refused, and hidden for an empty list with no filter.
- **Files:** `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/purchase-orders/PurchaseOrdersView.vue`, `crm/resources/js/pages/deals/DealsView.vue`.
- **Test first:** a `PurchaseOrdersView` spec: a 403 renders no search box.
- **Browser:** through Login As, a refused role sees no bar. The deals roles (OSup, TL, Procurement) after F-41.

### A-49 — every filter live (owner's pick: Option B)
- **Fix:** the text box applies after the 300 ms pause that `SearchCombobox.vue:53,106-110` already uses (the same pattern and value, not a second timer); Apply goes.
- **Files:** `crm/resources/js/components/SearchCombobox.vue`, `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/quotations/QuotationsView.vue`, `crm/resources/js/pages/deals/DealsView.vue`.
- **Test first:** a `CustomersView` spec with fake timers: typing loads once, 300 ms after the last key, and no Apply button exists.
- **Browser:** type and pause on customers and quotations. Deals after F-41.

### A-67 — one pager rule
- **Fix:** the pager is hidden when `total_pages ≤ 1`, the rule Customers already uses, on every list.
- **Files:** `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/quotations/QuotationsView.vue`, `crm/resources/js/pages/purchase-orders/PurchaseOrdersView.vue`, `crm/resources/js/pages/deals/DealsView.vue`.
- **Test first:** a `QuotationsView` spec: one page renders no pager.
- **Browser:** a filter that leaves one page, on each list. Deals after F-41.

### 1.7 Display, plurals, and title and focus

### A-06 — D-70 digits and one date form
- **Fix:** every `toLocale*` call goes through `domain/displayDate.ts`, using `-u-nu-latn`, with a timestamp sibling that drops the seconds. One day-first order in both languages.
- **Files:** `crm/resources/js/domain/displayDate.ts` and the 9 pages with their own date calls: `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/customers/CustomerDetailView.vue`, `crm/resources/js/pages/suppliers/SuppliersView.vue`, `crm/resources/js/pages/catalog/CatalogView.vue`, `crm/resources/js/pages/quotations/QuotationsView.vue`, `crm/resources/js/pages/quotations/QuotationDetailView.vue`, `crm/resources/js/pages/quotations/QuotationBuilderView.vue`, `crm/resources/js/pages/supplier-quotations/SupplierQuotationsView.vue`, `crm/resources/js/pages/deals/DealsView.vue`, `crm/resources/js/pages/deals/DealDetailView.vue`. `crm/resources/js/pages/purchase-orders/PurchaseOrdersView.vue` already uses `displayDate`.
- **Test first:** `displayDate.spec.ts`: Arabic output holds Western digits only; the timestamp has no seconds. A case in `uiSourceRules.spec.ts`: 0 `toLocaleDateString` / `toLocaleTimeString` / `toLocaleString` calls outside `displayDate.ts` and `*.spec.ts` (`toLocaleLowerCase` is a string call and is not counted).
- **Browser:** dates on customers, suppliers, catalog, quotations and supplier quotations in ar and en; POs as the reference. Deals after F-41.

### A-07 — phones keep their order
- **Fix:** phone values render with `dir="ltr"` inside `<bdi>`, and the tel input gets `dir="ltr"`.
- **Files:** `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/customers/CustomerDetailView.vue`, `crm/resources/js/pages/customers/CustomerFormModal.vue`, `crm/resources/js/components/SearchCombobox.vue`.
- **Test first:** a `CustomersView` spec: the phone cell carries `dir="ltr"`.
- **Browser:** "050 123 4567" and "+20 …" on the list, the page, the picker and while typing, in ar.

### A-26 — D-99's display form on screen (owner's answer)
- **Decision first.** This amends `D-82`. D-82 says the screen cuts after the third decimal and **never** rounds, while `D-99` rounds the PDF's money to 2 places half-up and drops trailing zeros. The owner's answer of 2026-10-12 is to apply D-99 on screen. Before any code, the Phase 5 point records a new proposed `D-xx` amending D-82 for screens; `CRM_Documentation_EN.md` is write-protected, so this is a shell write with the owner's yes.
- **Fix:** `domain/displayDecimals.ts` follows D-99 as **string operations** (no `Number()`, `DB-07`): 2 dp money, rounded half-up on the digit string, with grouping; quantities without trailing zeros; the currency on one side everywhere. Display only; the API strings and every input are unchanged (DS §6.3).
- **Files:** `crm/resources/js/domain/displayDecimals.ts`, `crm/resources/js/pages/quotations/QuotationDetailView.vue`, `crm/resources/js/pages/purchase-orders/PurchaseOrdersView.vue`, `crm/resources/js/pages/quotations/QuotationBuilderView.vue`.
- **Test first:** `displayDecimals.spec.ts`: money `16610.000` → `16,610.00` and `5219.305000` → `5,219.31` (half-up, the PDF's figure); qty `10.000` → `10`. The test also asserts that no `Number(` appears in the file.
- **Browser:** the quotation page, POs and the builder: the figures match the PDF.

### A-52 — long text stays inside
- **Fix:** `break-words min-w-0` on file names, import names and PO references, and a `title` on every `truncate`d title.
- **Files:** `crm/resources/js/components/AppContextBar.vue`, `crm/resources/js/pages/deals/DealDocumentsPanel.vue`, `crm/resources/js/components/imports/ImportModal.vue`, `crm/resources/js/pages/customers/CustomersView.vue`.
- **Test first:** a case in `uiSourceRules.spec.ts`: every `truncate` class sits on an element with a `title` binding.
- **Browser:** a 120-character Arabic title and file name at 375. Deal documents after F-41.

### A-44 — Arabic plurals
- **Fix:** the `*.total` keys use vue-i18n's plural form with Arabic's six categories, chosen by an `ar` `pluralRules` entry in `i18n.ts`.
- **Files:** `crm/resources/js/i18n.ts`, `crm/resources/js/locales/ar.json`, `crm/resources/js/locales/en.json`.
- **Test first:** `i18n.spec.ts`: `deals.total` gives «6 صفقات» for 6 and `customers.total` gives «178 عميلاً» for 178, with all six forms covered (0, 1, 2, 3–10, 11–99, 100+).
- **Browser:** the count lines on customers and quotations in ar. Deals after F-41.

### A-12 — tab title and focus on navigation
- **Fix:** `router.afterEach` sets `document.title` from the route's `titleKey`, and the page's h1 takes focus after navigation. Detail routes get their own title, because today they reuse the list's.
- **Files:** `crm/resources/js/router/index.ts`, `crm/resources/js/components/AppContextBar.vue`.
- **Test first:** `guards.spec.ts`: after navigating to customers, `document.title` holds the translated title.
- **Browser:** the tab title per route, and a screen reader announces the new page.

### 1.8 Form fields

### A-11 — errors on a failed save (owner's pick: Option B)
- **Fix:** focus moves to the first invalid field. The existing top line gives the count («يجب تصحيح حقلين قبل الحفظ.»), each field error gets an id tied by `aria-describedby` with `aria-invalid`, and ImportModal's refusal gets `role="alert"`.
- **Files:** `crm/resources/js/pages/customers/CustomerFormModal.vue`, `crm/resources/js/pages/supplier-quotations/SupplierQuotationFormModal.vue`, `crm/resources/js/components/imports/ImportModal.vue`, `crm/resources/js/pages/quotations/QuotationBuilderView.vue`, `crm/resources/js/pages/deals/DealFormModal.vue`.
- **Test first:** a `CustomerFormModal` spec: a save with 2 invalid fields focuses the first and sets `aria-describedby` on both.
- **Browser:** an empty save in each form, ar and en, keyboard only. The deal form after F-41.

### A-24 — the file control (owner's pick: Option A)
- **Fix:**
  - The input is visually hidden but focusable, and its `<label>` is drawn as a button: «اختر ملفاً» / "Choose a file".
  - «لم يُختر ملف.» and the limits line, a lang string like `import.hint` (the reason is in the comment at `ImportModal.vue:36-46`), are tied by `aria-describedby`.
  - `accept` is set.
- **Files:** `crm/resources/js/pages/deals/DealDocumentsPanel.vue`, `crm/resources/js/pages/quotations/QuotationPurchaseOrder.vue`, `crm/resources/js/components/imports/ImportModal.vue`, `crm/resources/js/pages/supplier-quotations/SupplierQuotationFormModal.vue`.
- **Test first:** a `QuotationPurchaseOrder` spec: the file input has `accept`, and its label renders the translated button text and the limits line.
- **Browser:** Tab onto each file control: the ring shows, and the Arabic text shows on an Arabic page. Deal documents after F-41.

### A-38 — 44 px targets
- **Fix:** `min-h-11` on login's button and fields, `inline-flex` on inline-link buttons, and a 44 px label around each row checkbox.
- **Files:** `crm/resources/js/pages/auth/LoginView.vue`, `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/customers/CustomerDetailView.vue`.
- **Test first:** a case in `uiSourceRules.spec.ts`: every row checkbox sits inside a `label` with `min-h-11`, and the Back link is `inline-flex`.
- **Browser:** at 375, measure the targets with the accessibility tree: none under 44 px on login and customers.

### A-50 — one required marker (owner's pick: Option A)
- **Fix:** the word «(مطلوب)» / "(required)" after the label, in `danger`, with `aria-required`. Rules move out of placeholders into help text, and the disabled tax-included box says why, both tied by `aria-describedby`. No blank first options.
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`, `crm/resources/js/pages/customers/CustomerFormModal.vue`, `crm/resources/js/pages/auth/LoginView.vue`, `crm/resources/js/pages/supplier-quotations/SupplierQuotationFormModal.vue`.
- **Test first:** a `CustomerFormModal` spec: every required field shows the word marker and `aria-required`, and no `*` remains.
- **Browser:** each form in ar and en; the marker reads in both.

## 2. Per screen (F-42 · 4.3)

The 31 Phase 4 rows that are not in §1, grouped by the screen in `audit.md`'s Screen column. Each row's fix lands in one screen's files. The purchase orders screen has no row here: its rows (A-13, A-14, A-22, A-24, A-26, A-31, A-48, A-67) are all shared, in §1. A-66's polish items touch several screens and sit last.

### 2.1 Shell

### A-05 — the closed drawer is not focusable
- **Fix:** `inert` on the drawer while it is closed below 1024 px, removed on open.
- **Files:** `crm/resources/js/components/AppSidebar.vue`.
- **Test first:** `AppSidebar.spec.ts`: the closed drawer carries `inert`, and the open one does not.
- **Browser:** at 375, Tab from the menu button: the first stop is in the page, not the sidebar, in ar and en.

### A-23 — rail links keep their name
- **Fix:** when the rail is collapsed, each link gets an `aria-label` from its label, plus the count when badged («الموافقات، 3»).
- **Files:** `crm/resources/js/components/AppSidebar.vue`.
- **Test first:** `AppSidebar.spec.ts`: collapsed, the approvals link's accessible name holds the label and the count.
- **Browser:** collapse the rail at 1280 as the Manager; the accessibility tree names each link.

### A-28 — one h1 per page (owner's pick: Option A)
- **Fix:** the bar's title becomes a `<p>` (`AppContextBar.vue:136`), so each page's own h1 is the only one. Home and `/403` have none today, so `Ping.vue`'s title and `PermissionDeniedState`'s heading become h1 on those pages.
- **Files:** `crm/resources/js/components/AppContextBar.vue`, `crm/resources/js/pages/Ping.vue`, `crm/resources/js/pages/ForbiddenView.vue`, `crm/resources/js/components/states/PermissionDeniedState.vue`.
- **Test first:** a case in `uiSourceRules.spec.ts`: `AppContextBar.vue` holds no `<h1`, and every page file the router names holds exactly one.
- **Browser:** the heading outline on customers, a quotation, Home and `/403`: one h1 each.

### 2.2 Login

### A-10 — Sign-in stays enabled
- **Fix:** the button is disabled only while the request runs; an empty submit shows the existing field messages and focuses the first empty field.
- **Files:** `crm/resources/js/pages/auth/LoginView.vue`.
- **Test first:** `LoginView.spec.ts`: with both fields empty, the button is enabled, and a submit shows both messages and sends no request.
- **Browser:** the login page in a private window (so the owner's session is not signed out), Enter on empty fields, ar and en.

### A-46 — a language switch on login
- **Fix:** the context bar's locale segment (`AppContextBar.vue:194`) moves into a small component, used by the bar and by the login card. No theme switch: the audit's fix names the language only.
- **Files:** `crm/resources/js/components/AppContextBar.vue`, `crm/resources/js/pages/auth/LoginView.vue`.
- **Test first:** `LoginView.spec.ts`: the login card renders the segment, and a click sets the locale to `en`.
- **Browser:** a private window: switch to English, then reload; the choice is kept as the bar keeps it.

### 2.3 Quotations and the builder

### A-01 — the builder follows the route
- **Fix:** `<RouterView>` in `App.vue` keyed by the route path, or `watch(() => route.fullPath, load)` in the builder. The watch is the one-file change and is taken.
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`.
- **Test first:** `QuotationBuilderView.spec.ts`: a route change from one `?deal=` to another reloads, and the second deal's code is shown.
- **Browser:** edit a draft, press Back to `/quotations/new?deal=…`: the right deal shows without a reload. No save is pressed.

### A-03 — the edit route's error says edit (UI part; owner's answer)
- **Fix:** the builder's load error on `/quotations/:id/edit` reads an edit message, not the create one. The per-row abilities are a separate fix point outside F-42 (`CHECKLIST.md`, under 4.1).
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`, `crm/resources/js/locales/ar.json`, `crm/resources/js/locales/en.json`.
- **Test first:** `QuotationBuilderView.spec.ts`: a 404 on the edit route renders the edit message key, not the create one.
- **Browser:** as the TL through Login As, open Edit on a draft outside the team: the message says the quotation cannot be edited.

### A-15 — a line under Save (UI part; owner's answer)
- **Fix:** one muted line under Save: «يظهر الإجمالي بعد الحفظ.» / "The total appears after saving." The server preview is a fix point outside F-42; the SPA computes nothing (D-67).
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`, `crm/resources/js/locales/ar.json`, `crm/resources/js/locales/en.json`.
- **Test first:** `QuotationBuilderView.spec.ts`: the line renders under Save, and no figure is computed in the view (a source case in `uiSourceRules.spec.ts` keeps `QuotationBuilderView.vue` free of `Number(`).
- **Browser:** the builder in ar and en; the line sits under Save.

### A-16 — no placeholder in quantity
- **Fix:** the quantity input loses its `placeholder`; the recorded figure stays in the "recorded" text beside it.
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`.
- **Test first:** `QuotationBuilderView.spec.ts`: a supplier line with an available quantity renders an empty quantity input with no placeholder.
- **Browser:** pick an offer in the builder: quantity boxes look empty until typed.

### A-17 — focus follows add and remove
- **Fix:** after Add, focus moves to the new row's first field (`nextTick`); after Remove, to the next row's first field, or to Add when none is left.
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`.
- **Test first:** `QuotationBuilderView.spec.ts`: Add line focuses the new line's first input; Remove on the last line focuses Add.
- **Browser:** keyboard only: add and remove a block, a line and a term.

### A-18 — no raw ids (UI part; owner's answer)
- **Fix:** where no name is readable, the quotation page shows «—», not a uuid; the timeline gets a gap between actor and date. Names and codes are a fix point outside F-42, shaped like F-41 · 1.6b's `owner_name`.
- **Files:** `crm/resources/js/pages/quotations/QuotationDetailView.vue`, `crm/resources/js/pages/deals/DealDetailView.vue`.
- **Test first:** `QuotationDetailView.spec.ts`: a payload with no readable customer renders «—» and no uuid pattern. The timeline case goes in `DealDetailView.spec.ts`, **after F-41**.
- **Browser:** a quotation as the TL and Procurement: no uuid on the page. The deal timeline after F-41.

### A-20 — a sticky Save (owner's pick: Option A)
- **Fix:** a bar fixed to the bottom of the viewport carries Save and Cancel; the page gets `scroll-padding-block-end` of the bar's height, so a focused field never hides under it.
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`.
- **Test first:** a case in `uiSourceRules.spec.ts`: the builder's action bar is `sticky` at the bottom and the page sets `scroll-padding-block-end`.
- **Browser:** a long draft at 1280 and 375: Save stays in view, and a Tab walk hides 0 focused fields (the 3.7 measure). The phone keyboard check is Phase 6's.

### A-21 — a section line for a refused PDF (owner's pick: Option A)
- **Fix:** `QuotationPdfPanel` keeps its title; on 403 its body is one muted line «هذا القسم غير متاح لك.» / "This section is not available to you.", with no lock, no admin prompt and no heading of its own.
- **Files:** `crm/resources/js/components/pdf/QuotationPdfPanel.vue`, `crm/resources/js/locales/ar.json`, `crm/resources/js/locales/en.json`.
- **Test first:** `QuotationPdfPanel.spec.ts`: a 403 renders the section line and not `PermissionDeniedState`.
- **Browser:** a quotation as Procurement through Login As, ar and en.

### A-27 — the product list stays in the dialog
- **Fix:** **a browser check first** confirms the clipping. If it is confirmed, `CatalogItemPicker` takes the in-flow mode inside the supplier dialog, as `SupplierQuotationFormModal.vue`'s comment asks. If not, the row closes with the measure as its evidence.
- **Files:** `crm/resources/js/components/catalog/CatalogItemPicker.vue`, `crm/resources/js/pages/supplier-quotations/SupplierQuotationFormModal.vue`.
- **Test first:** `CatalogItemPicker.spec.ts`: inside the dialog, the list renders in flow (no floating class). Written only if the browser check confirms the clipping.
- **Browser:** open the product list on the dialog's last line at 375 and 1280: every option is reachable.

### A-35 — remove now, with Undo (owner's pick: Option B)
- **Fix:** Remove takes effect at once and shows an «تراجع» / "Undo" bar for a few seconds; on expiry, focus moves by A-17's rule. Changing a block's offer is the same: values go, Undo brings them back.
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`.
- **Test first:** `QuotationBuilderView.spec.ts` with fake timers: Remove then Undo restores the line with its typed values; after expiry, focus is on the next line.
- **Browser:** remove a typed line, undo it, then let one expire, keyboard only.

### A-36 — each Remove names its row
- **Fix:** `aria-label` on every Remove: «إزالة السطر 3» / "Remove line 3", and the supplier's name on a block.
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`, `crm/resources/js/pages/supplier-quotations/SupplierQuotationFormModal.vue`.
- **Test first:** `QuotationBuilderView.spec.ts`: two lines give two distinct accessible names.
- **Browser:** the accessibility tree on a 3-line draft and in the supplier dialog.

### A-37 — Enter does not save mid-entry
- **Fix:** Enter in a line input moves to the next field instead of submitting; Save still submits.
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`.
- **Test first:** `QuotationBuilderView.spec.ts`: Enter in a quantity input sends no request.
- **Browser:** type a quantity and press Enter: no draft is created, and focus moves on.

### A-56 — the total in the header (owner's pick: Option A)
- **Fix:** the final total, already in the payload, is repeated at the end of the header beside the code and status. The totals block stays at the bottom.
- **Files:** `crm/resources/js/pages/quotations/QuotationDetailView.vue`.
- **Test first:** `QuotationDetailView.spec.ts`: the header shows the payload's final total string, unchanged.
- **Browser:** a quotation at 1280 and 375, ar and en; the header figure equals the bottom one.

### A-60 — Remove stays on its line
- **Fix:** `.line-grid` gets a fourth column for Remove at ≥ 640 px.
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`.
- **Test first:** a case in `uiSourceRules.spec.ts`: `.line-grid`'s template columns count its four children.
- **Browser:** an existing draft at 1280: each Remove sits on its line's row.

### A-61 — an in-app discard prompt
- **Fix:** leaving with unsaved changes opens the in-app discard prompt the supplier dialog uses (`SupplierQuotationFormModal.vue`'s `discard()`), not `window.confirm`.
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`.
- **Test first:** `QuotationBuilderView.spec.ts`: a route leave while dirty opens the prompt and calls no `window.confirm`.
- **Browser:** edit then press Back, ar and en: the prompt follows the page's language and direction.

### A-62 — margin keeps its minus sign (owner's answer)
- **Fix:** the line-margin inputs stay `type="text"` with no `inputmode`, because a margin may be negative (`D-106`, `SaveQuotationRequest.php`'s `^-?`) and a decimal keypad has no minus on iOS. A comment says so at both inputs. No other change.
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`.
- **Test first:** `QuotationBuilderView.spec.ts`: a line-margin input has no `inputmode` attribute.
- **Browser:** at 375 on a phone, type a negative margin in a line.

### 2.4 Customers

### A-02 — no second create (owner's pick: Option A)
- **Fix:** after a create that finds similar customers, the dialog switches to edit mode on the saved record, is retitled «تعديل العميل «…»», and focus moves to the warning at its top. A second Save is an update.
- **Files:** `crm/resources/js/pages/customers/CustomerFormModal.vue`, `crm/resources/js/pages/customers/CustomersView.vue`.
- **Test first:** `CustomerFormModal.spec.ts`: after a create answered with `similar`, the next Save sends a `PATCH` to the new id, not a `POST`.
- **Browser:** create a customer named like an existing one, then Save again: one record exists. The test record is archived after.

### A-54 — tappable contacts (UI part; owner's answer)
- **Fix:** phone and WhatsApp become `tel:` and `wa.me` links, email a `mailto:` link, with A-07's `dir="ltr"`. The stale comment at `CustomerDetailView.vue:32` is corrected. The customer's deals wait for F-41 · 1.7's list filter, as answered.
- **Files:** `crm/resources/js/pages/customers/CustomerDetailView.vue`.
- **Test first:** `CustomerDetailView.spec.ts`: the phone renders as an `a[href^="tel:"]` and the email as `mailto:`.
- **Browser:** a customer page at 375: each link opens its app.

### A-57 — the bulk panel when needed
- **Fix:** the "Assign to" panel renders once a row is selected.
- **Files:** `crm/resources/js/pages/customers/CustomersView.vue`.
- **Test first:** `CustomersView.spec.ts`: no panel with 0 selected; the panel after one checkbox.
- **Browser:** as the Manager, select and clear a row.

### A-59 — Close after an import
- **Fix:** once a result is shown, the import dialog's exit reads «إغلاق» / "Close". The dialog opens from customers, suppliers and catalog.
- **Files:** `crm/resources/js/components/imports/ImportModal.vue`.
- **Test first:** `ImportModal.spec.ts`: after a result, the exit button reads the close key.
- **Browser:** an import of the dev sample on customers, ar and en.

### 2.5 Approvals

### A-47 — four approvals fixes
- **Fix:** a whitespace-only Return note shows the empty-note error; `load()` clears the 409 banner; each group gets its own `<tbody>` with a `scope="rowgroup"` header; the quotation code takes the PO screen's link style.
- **Files:** `crm/resources/js/pages/approvals/ApprovalsView.vue`.
- **Test first:** `ApprovalsView.spec.ts`: a "   " note shows the error and sends nothing; Reload after a 409 clears the banner.
- **Browser:** as the Manager, the four cases in ar and en; the table read by the accessibility tree.

### 2.6 Deals (after F-41)

Every row here is flagged F-41 and is built after F-41 · 1.8 closes.

### A-19 — the phone deals list (owner's pick: Option A)
- **Fix:** below 640 px the filters fold behind one «عوامل التصفية (2)» / "Filters (2)" button; rows stay a table with `whitespace-nowrap` codes and a compact status action. Built after F-41.
- **Files:** `crm/resources/js/pages/deals/DealsView.vue`, `crm/resources/js/pages/deals/DealStatusControl.vue`.
- **Test first:** `DealsView.spec.ts`: the filter form is collapsed behind a button with the active-filter count. After F-41.
- **Browser:** at 375 as OSales: the first row is above the fold (3.6 measure). After F-41.

### A-29 — unique ids per row
- **Fix:** `useId()` for the status and reason fields, as `SearchCombobox.vue` does. Built after F-41.
- **Files:** `crm/resources/js/pages/deals/DealStatusControl.vue`, `crm/resources/js/pages/deals/DealApprovalControls.vue`.
- **Test first:** `DealsView.spec.ts`: two open row forms give distinct label targets. After F-41.
- **Browser:** open two rows' status forms: each label focuses its own field. After F-41.

### A-53 — the deal header (owner's pick: Option A)
- **Fix:** h1 is the code with the status chip; under it the title and the customer as a link; a back link above, as on the customer page; the not-found state gets the same back link. Built after F-41.
- **Files:** `crm/resources/js/pages/deals/DealDetailView.vue`.
- **Test first:** `DealDetailView.spec.ts`: the header holds the code, title and a customer link; not-found renders a back link. After F-41.
- **Browser:** a deal and a stale deal id, ar and en. After F-41.

### A-58 — the details trigger
- **Fix:** the trigger's `aria-label` is «التفاصيل» / "Details", the text stays in the popover, and `cursor: help` becomes `pointer`. Built after F-41.
- **Files:** `crm/resources/js/pages/deals/DealsView.vue`.
- **Test first:** `DealsView.spec.ts`: the trigger's accessible name is the short label, not the details text. After F-41.
- **Browser:** the accessibility tree on a deal with long details. After F-41.

### 2.7 Polish across screens

### A-66 — fifteen polish items
- **Fix:** each as listed in `audit.md`:
  - `useId()` for `ConfirmDialog`'s fixed ids (SH-11);
  - named text sizes in `SearchCombobox` (SH-15);
  - a show/hide toggle on the login password (SH-22);
  - `aria-controls` on the menu button (SH-24);
  - `--color-link` on PO row links (SH-44);
  - an example in the PO search placeholder (SH-45);
  - a `theme-color` meta (SH-48);
  - the sidebar group label tied to its list (SH-52);
  - one token for danger text on a fill (DC-42);
  - `EmptyState` for the deal page's empty sections (DC-55), **after F-41**;
  - `disabled:opacity-60` on Restore (DC-62);
  - `autocomplete="off"` on the deal title (DC-69), **after F-41**;
  - `overscroll-behavior: contain` in the supplier dialog (Q-39);
  - no `title` on the quotation chip (Q-41).
  - Q-42 (currency names) is **not built**: the currency payload carries no name (`services/admin.ts`'s `Currency`), so it is an API change outside F-42, the same as F-40 · 1.5's "keep the code".
- **Files:** `crm/resources/js/components/users/ConfirmDialog.vue`, `crm/resources/js/components/SearchCombobox.vue`, `crm/resources/js/pages/auth/LoginView.vue`, `crm/resources/js/components/AppContextBar.vue`, `crm/resources/js/pages/purchase-orders/PurchaseOrdersView.vue`, `crm/resources/views/welcome.blade.php`, `crm/resources/js/components/AppSidebar.vue`, `crm/resources/js/pages/customers/CustomerFormModal.vue`, `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/supplier-quotations/SupplierQuotationFormModal.vue`, `crm/resources/js/pages/quotations/QuotationStatusChip.vue`; after F-41, `crm/resources/js/pages/deals/DealDetailView.vue` and `crm/resources/js/pages/deals/DealFormModal.vue`.
- **Test first:** one case per item where a DOM check can fail. The cases go in the existing spec of each file, or in `uiSourceRules.spec.ts` for the token and class items. `LoginView.spec.ts`: the toggle switches the input to `type="text"` and back.
- **Browser:** each item on its screen, ar and en; the `theme-color` in the 3 themes on a phone. The deal items after F-41.

## 3. The Phase 5 order (F-42 · 4.3)

This is the order Phase 5's point list follows. That list is published and approved on its own, before Phase 5's first point. One step here may become several points.

**Shared layer first**, in §1's order, because every screen step uses it:
1. Tokens: A-42, A-63, A-65.
2. The focus rule: A-34, A-64, A-08.
3. Buttons and status: A-51, A-33, A-55.
4. The dialog shell: A-04, A-09, A-22, A-30.
5. Feedback and states: A-31, A-32, A-41, A-43.
6. List primitives: A-13, A-14, A-39, A-40, A-48, A-49, A-67.
7. Display, plurals, and title and focus: A-06, A-07, A-26, A-52, A-44, A-12. **A-26's point opens with the D-xx amending D-82**, a shell write with the owner's yes.
8. Form fields: A-11, A-24, A-38, A-50.

**Then the screens**, from §2:

9. Shell: A-05, A-23, A-28.
10. Login: A-10, A-46.
11. Customers: A-02, A-57, A-59, A-54.
12. Approvals: A-47.
13. Quotations and the builder:
    - the route and focus first: A-01, A-17, then A-35 (it uses A-17's focus rule);
    - then the inputs: A-16, A-36, A-37, A-60, A-61, A-62, A-27;
    - then the page: A-20, A-03, A-15, A-21, A-56, and A-18's quotation part.
14. Polish: A-66, without its deals items.

**After F-41 · 1.8 closes:**

15. Deals: A-19, A-29, A-53, A-58, A-18's timeline part, A-66's DC-55 and DC-69. Then the deals-screen part of every §1 row marked "after F-41", each in its own row's step order.

**Outside F-42:** A-25 and A-45 are outside F-42. Their fix is an API or route change, numbered by the owner (`CHECKLIST.md`, under 4.1), as are the API parts of A-03, A-15 and A-18.

**Overlaps with other open points.** F-37 · 1.5 and F-33 · 1.3 are both Arabic manual test lists of work already merged: F-37's for a quotation's terms and the builder, F-33's for the PDF's D-99 figures. Phase 5 changes the screens their steps walk:
- F-37 · 1.5: the builder and the supplier dialog, so steps 4, 8 and 13 (A-01, A-16, A-17, A-20, A-27, A-35, A-36, A-37, A-60, A-61, A-62).
- F-33 · 1.3: the quotation page beside its PDF, so A-26 (the screen then shows D-99's form, the same as the PDF), A-56, A-21 and A-03.

**Recommended:** run F-37 · 1.5 and F-33 · 1.3 before Phase 5's first point, as the fix pass already orders them (F-41 → F-37 · 1.5 → F-33 · 1.3). Each list is then walked against the screens it was written for. If Phase 5 goes first, the rows named above are the ones whose lines in those lists need rereading.
