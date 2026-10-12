# F-42 UI/UX upgrade — build plan (Phase 4)

This is the plan Phase 5 carries out. Every row comes from `audit.md`. Its fix is either the one obvious fix in `design-options.md` §1, or the owner's pick (§3–§8) or answer (§2), all of 2026-10-12. §1 here is the **shared layer**: a fix that lands in a component or stylesheet used by two or more screens. §2 (F-42 · 4.3) takes the rest per screen. §3 (4.3) turns both into Phase 5's points.

**Rules for every row** (CLAUDE.md, `Design_System_EN.md`):
- UI layer only: no business logic, calculation, permission, API or route change.
- Each row's Phase 5 point writes the failing test named under **Test first** before the fix (Delivery Protocol 1).
- Then it runs the **Browser** check through `rtl-ui-verifier`, at Arabic RTL and English LTR × 1280 and 375. The three themes are added where the row involves colour.
- Paths are from the repo root. `js/` stands for `crm/resources/js/`.
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
- **Test first:** a vitest source scan, `radiusScale.spec.ts`. It finds no `rounded-xl` on a control in `AppContextBar.vue`, no `rounded-2xl` outside the dialog panels and the three `state-plate` tiles, and no `90vh`.
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
- **Test first:** a vitest mount of a page with a sign-out button and a RouterLink button: the computed outline on `:focus-visible` uses the token. A source scan asserts that `app.css` holds the rule once.
- **Browser:** a Tab walk on approvals, quotations and the shell: every stop shows the ring, in 3 themes. The deals-screen stops are checked after F-41.

### A-64 — focus rings only for the keyboard
- **Fix:** the `focus:` ring utilities (657 `focus:ring`/`focus:outline` uses in 36 files, measured 2026-10-12; the audit counted 219) become `focus-visible:`, under A-34's rule. Most are deleted outright, because the global rule covers them.
- **Files:** `crm/resources/js/components/SearchCombobox.vue`, `crm/resources/js/pages/customers/CustomersView.vue` (and the other `focus:` files, 36 in all).
- **Test first:** a source scan finds 0 `focus:ring` / `focus:outline` utilities in `js/` (657 today).
- **Browser:** a mouse click shows no ring, and Tab shows it. Deals screens after F-41.

### A-08 — the picker's active option (owner's pick: Option B)
- **Fix:** a 4 px `border-inline-start` bar in `--color-focus-ring` on the active option, text at weight 600, plus today's fill.
- **Files:** `crm/resources/js/components/SearchCombobox.vue`.
- **Test first:** `SearchCombobox.spec.ts` (new): ArrowDown sets `aria-activedescendant`, and the active option carries the bar class.
- **Browser:** an arrow-key walk in the customer picker, ar and en (the bar mirrors), 3 themes. The bar must reach 3:1 against the list.

### 1.3 Buttons and status

### A-51 — hover and pressed states
- **Fix:** shared `:hover` and `:active` rules for the secondary button, picker options and the picker's Retry, taken from `QuotationPdfPanel`'s existing `.row-action:hover`.
- **Files:** `crm/resources/css/app.css`, `crm/resources/js/components/pdf/QuotationPdfPanel.vue`, `crm/resources/js/components/SearchCombobox.vue`.
- **Test first:** a source scan: the hover rule exists once in `app.css` and no longer in `QuotationPdfPanel.vue`. Retry renders as a button.
- **Browser:** a mouse hover on a secondary button, an option and Retry, in 3 themes.

### A-33 — action hierarchy (owner's pick: Option B for row Danger)
- **Fix:** DS §6.2's variants. One primary per area; Reject and Delete are Danger; row Danger is text only (`danger` text, no border, the size of the ghost Edit); Discard is told apart from Keep editing.
- **Files:** `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/quotations/QuotationDetailView.vue`, `crm/resources/js/pages/approvals/ApprovalsView.vue`, `crm/resources/js/pages/deals/DealDetailView.vue`.
- **Test first:** a vitest mount per screen. The Archive row action has the danger-text class and no border; Reject is not primary; one primary button per area.
- **Browser:** customers, quotation detail and approvals in 3 themes. Deal detail after F-41.

### A-55 — one status chip, no dot (owner's answer)
- **Fix:** one `StatusChip` recipe for customers, deals and quotations, without the dot. The deal approval emoji (⏳ ⛔ ✓) become text in the chip's tone.
- **Files:** `crm/resources/js/pages/deals/DealStatusChip.vue`, `crm/resources/js/pages/quotations/QuotationStatusChip.vue`, `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/customers/CustomerDetailView.vue`, `crm/resources/js/pages/deals/DealApprovalControls.vue`.
- **Test first:** a vitest mount: the customer status renders the same classes on the list and the page, and no chip contains an emoji or a dot element.
- **Browser:** the customer list and page side by side, in 3 themes. Deal chips after F-41.

### 1.4 The dialog shell

### A-04 — native `<dialog>` (owner's pick: Option A)
- **Fix:** every modal uses `<dialog>` with `showModal()`. That gives the focus move, the trap, Escape and the return to the opener without new code; the drawer gets `inert`.
- **Files:** `crm/resources/js/components/users/ConfirmDialog.vue`, `crm/resources/js/components/imports/ImportModal.vue`, `crm/resources/js/pages/customers/CustomerFormModal.vue`, `crm/resources/js/pages/supplier-quotations/SupplierQuotationFormModal.vue`, `crm/resources/js/pages/deals/DealFormModal.vue`.
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
- **Test first:** `SearchCombobox.spec.ts`: while the status is `loading`, the popup shows the loading line, not an empty box.
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
- **Test first:** a source scan: no `<thead class="sticky …">` inside an `overflow-x-auto` frame.
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
- **Files:** `crm/resources/js/domain/displayDate.ts`, `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/quotations/QuotationsView.vue`, `crm/resources/js/pages/deals/DealsView.vue`.
- **Test first:** `displayDate.spec.ts`: Arabic output holds Western digits only; the timestamp has no seconds. A scan finds 0 `toLocale` calls outside `displayDate.ts`.
- **Browser:** dates on customers, quotations and POs in ar and en. Deals after F-41.

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
- **Test first:** a source scan: every `truncate` class sits on an element with a `title` binding.
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
  - «لم يُختر ملف.» and the limits line, as a lang string like `ImportModal.vue:36-46`, are tied by `aria-describedby`.
  - `accept` is set.
- **Files:** `crm/resources/js/pages/deals/DealDocumentsPanel.vue`, `crm/resources/js/pages/quotations/QuotationPurchaseOrder.vue`, `crm/resources/js/components/imports/ImportModal.vue`, `crm/resources/js/pages/supplier-quotations/SupplierQuotationFormModal.vue`.
- **Test first:** a `QuotationPurchaseOrder` spec: the file input has `accept`, and its label renders the translated button text and the limits line.
- **Browser:** Tab onto each file control: the ring shows, and the Arabic text shows on an Arabic page. Deal documents after F-41.

### A-38 — 44 px targets
- **Fix:** `min-h-11` on login's button and fields, `inline-flex` on inline-link buttons, and a 44 px label around each row checkbox.
- **Files:** `crm/resources/js/pages/auth/LoginView.vue`, `crm/resources/js/pages/customers/CustomersView.vue`, `crm/resources/js/pages/customers/CustomerDetailView.vue`.
- **Test first:** a source scan: every row checkbox sits inside a `label` with `min-h-11`, and the Back link is `inline-flex`.
- **Browser:** at 375, measure the targets with the accessibility tree: none under 44 px on login and customers.

### A-50 — one required marker (owner's pick: Option A)
- **Fix:** the word «(مطلوب)» / "(required)" after the label, in `danger`, with `aria-required`. Rules move out of placeholders into help text, and the disabled tax-included box says why, both tied by `aria-describedby`. No blank first options.
- **Files:** `crm/resources/js/pages/quotations/QuotationBuilderView.vue`, `crm/resources/js/pages/customers/CustomerFormModal.vue`, `crm/resources/js/pages/auth/LoginView.vue`, `crm/resources/js/pages/supplier-quotations/SupplierQuotationFormModal.vue`.
- **Test first:** a `CustomerFormModal` spec: every required field shows the word marker and `aria-required`, and no `*` remains.
- **Browser:** each form in ar and en; the marker reads in both.
