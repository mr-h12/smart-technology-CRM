# F-42 Phase 3 — design options

This file holds the output of F-42 Phase 3 (CHECKLIST, F-42 · 3.0–3.8), and the rows refer to [`audit.md`](audit.md).

This phase is design only. Two options are drawn only where there is a real choice. A row with one obvious fix gets no options and goes straight to Phase 4.

## 1. Sorting (F-42 · 3.1)

Every audit row has exactly one group:

- **one obvious fix**: the fix in `audit.md` is the only sensible one, and goes to Phase 4 as written.
- **owner's pick**: two designs were drawn at 3.3–3.8, and the owner took the recommendation (2026-10-12).
- **owner's answer**: the owner answered the 3.2 question with its recommendation (2026-10-12).

**Phase 4 or outside F-42 (F-42 · 4.1).** Every row now goes to Phase 4 except A-25 and A-45, whose whole fix is a route or API change. A-03, A-15 and A-18 are split: their UI part is in Phase 4 (the edit route's message, a line under Save, the gap and "—"), and their API part is a separate proposal outside F-42, listed in `CHECKLIST.md` under 4.1 with A-25 and A-45.

**F-41** marks a row that touches the deals screens. It may be designed now but is built only after F-41 closes, whatever its group.

| Row | Group | To | F-41 | Why this group |
|---|---|---|---|---|
| A-01 | one obvious fix | Phase 4 | | Re-run `load()` when the route changes; nothing to choose |
| A-02 | owner's pick | Phase 4 | | After the first save, either switch the dialog to edit mode or close it and show the warning on the list; both are sound |
| A-03 | owner's answer | Phase 4 | | Per-row abilities on the API; the edit-route message rides along |
| A-04 | owner's pick | Phase 4 | ✔ | Native `<dialog>` + `showModal()` vs a shared helper on the current markup |
| A-05 | one obvious fix | Phase 4 | | `inert` on the closed drawer |
| A-06 | one obvious fix | Phase 4 | ✔ | D-70 fixes the digits; the change is in `displayDate.ts`, plus a timestamp sibling |
| A-07 | one obvious fix | Phase 4 | | `dir="ltr"` / `<bdi>` on phones |
| A-08 | owner's pick | Phase 4 | | The highlight's look (outline vs start-edge bar) is a visual choice; it must reach 3:1 |
| A-09 | owner's pick | Phase 4 | ✔ | A confirm step vs an undo window |
| A-10 | one obvious fix | Phase 4 | | Keep Sign-in enabled and validate on submit |
| A-11 | owner's pick | Phase 4 | ✔ | An error summary at the top vs focusing the first invalid field |
| A-12 | one obvious fix | Phase 4 | | `document.title` from the route title; focus the page h1 after navigation |
| A-13 | one obvious fix | Phase 4 | ✔ | Filters, sort and page into `route.query` with `router.replace`; no visual choice |
| A-14 | owner's pick | Phase 4 | ✔ | A spinner over the kept table vs a dimmed table with `aria-busy` |
| A-15 | owner's answer | Phase 4 | | A server preview vs save-then-see |
| A-16 | one obvious fix | Phase 4 | | Drop the placeholder (the figure is already shown at :897) |
| A-17 | one obvious fix | Phase 4 | | Focus the new row's first field; after remove, focus the next row or Add |
| A-18 | owner's answer | Phase 4 | ✔ | Names and codes need the API; the UI part (the gap, "—") is obvious and is built with it |
| A-19 | owner's pick | Phase 4 | ✔ | The phone deals layout: folded filters plus compact rows vs card rows |
| A-20 | owner's pick | Phase 4 | | A sticky bottom bar vs a sticky header action |
| A-21 | owner's pick | Phase 4 | | A muted section line vs hiding the section |
| A-22 | owner's pick | Phase 4 | ✔ | Follows A-04: stay inline with focus handling, or become dialogs |
| A-23 | one obvious fix | Phase 4 | | `aria-label` on rail links when collapsed |
| A-24 | owner's pick | Phase 4 | ✔ | The translated file chooser's look; the limit text is non-UI if the limit is not exposed |
| A-25 | owner's answer | outside F-42 | | An interim landing per role is a route decision |
| A-26 | owner's answer | Phase 4 | | Whether D-99's display form applies on screen |
| A-27 | one obvious fix | Phase 4 | | Use the in-flow picker mode the dialog's own comment asks for; **a browser check confirms the clipping first** |
| A-28 | owner's pick | Phase 4 | | Make the bar title a non-heading vs drop the page h1 |
| A-29 | one obvious fix | Phase 4 | ✔ | `useId()`, already in `SearchCombobox.vue:55` |
| A-30 | one obvious fix | Phase 4 | | A busy label as in `LoginView.vue:177`; ignore Escape and scrim while busy; a busy flag on assign |
| A-31 | owner's pick | Phase 4 | ✔ | A live region in place vs a toast |
| A-32 | owner's pick | Phase 4 | ✔ | The same choice as A-31, for success messages |
| A-33 | owner's pick | Phase 4 | ✔ | How row-level Danger and the single Primary look per page |
| A-34 | one obvious fix | Phase 4 | ✔ | One global `:focus-visible` rule with the existing `--color-focus-ring` (it passes in all 3 themes, B-23) |
| A-35 | owner's pick | Phase 4 | | Confirm before wiping vs undo after removing |
| A-36 | one obvious fix | Phase 4 | | `aria-label` naming the line or supplier |
| A-37 | one obvious fix | Phase 4 | | Block Enter's implicit submit in line inputs |
| A-38 | one obvious fix | Phase 4 | | `min-h-11`, `inline-flex`, and a 44 px label around checkboxes |
| A-39 | owner's pick | Phase 4 | ✔ | Card rows below 640 px vs hiding secondary columns |
| A-40 | owner's pick | Phase 4 | ✔ | Follows A-39: the sticky header's container depends on whether the table still scrolls sideways |
| A-41 | one obvious fix | Phase 4 | | A "Loading…" line; the owner box only after a refusal |
| A-42 | owner's pick | Phase 4 | | The `--color-overlay` value per theme and `color-scheme`, a token change for the owner |
| A-43 | owner's pick | Phase 4 | ✔ | One wording per case; its copy is the owner's to approve |
| A-44 | one obvious fix | Phase 4 | ✔ | Arabic's six plural forms through vue-i18n |
| A-45 | owner's answer | outside F-42 | | The role's display name; `roles.name_ar` is empty for every role |
| A-46 | one obvious fix | Phase 4 | | Reuse the context bar's language segment on login |
| A-47 | one obvious fix | Phase 4 | | Four approvals fixes, each stated in the audit |
| A-48 | owner's pick | Phase 4 | ✔ | When the filter bar shows: with data, or once a filter is active |
| A-49 | owner's pick | Phase 4 | ✔ | Everything on Apply vs text on debounce |
| A-50 | owner's pick | Phase 4 | | The required marker ("*" vs the word) and where help text sits |
| A-51 | one obvious fix | Phase 4 | | Hover and active rules on the shared button styles (`QuotationPdfPanel` already has one) |
| A-52 | one obvious fix | Phase 4 | ✔ | `break-words min-w-0`; a `title` on truncated titles |
| A-53 | owner's pick | Phase 4 | ✔ | What the deal header carries, and where Back sits |
| A-54 | owner's answer | Phase 4 | | Deals by customer needs a list filter; the contact links are obvious and ride along |
| A-55 | owner's answer | Phase 4 | ✔ | The open question about the dot on the status chip (handoff: `QuotationStatusChip` has one, `DealStatusChip` does not) decides the shared chip |
| A-56 | owner's pick | Phase 4 | | Where the total sits in the quotation header |
| A-57 | one obvious fix | Phase 4 | | Show the bulk panel once a row is selected |
| A-58 | one obvious fix | Phase 4 | ✔ | A short `aria-label`; `cursor: pointer` |
| A-59 | one obvious fix | Phase 4 | | "Close" once a result is shown |
| A-60 | one obvious fix | Phase 4 | | A 4th grid column |
| A-61 | one obvious fix | Phase 4 | | The in-app discard prompt the supplier dialog already uses |
| A-62 | owner's answer | Phase 4 | | Whether margin takes a minus sign decides `inputmode` |
| A-63 | owner's pick | Phase 4 | | The radius scale against §4.2, a token change for the owner |
| A-64 | one obvious fix | Phase 4 | ✔ | `focus-visible:` instead of `focus:`, under A-34's rule |
| A-65 | one obvious fix | Phase 4 | | Use `--shadow-2`: no token changes, so no proposal is needed |
| A-66 | one obvious fix | Phase 4 | ✔ | Fifteen small fixes, each stated in the audit |
| A-67 | one obvious fix | Phase 4 | ✔ | Hide the pager when `total_pages ≤ 1`, the rule Customers already uses |

### Counts

| Group | Rows |
|---|---|
| one obvious fix → Phase 4 | 32 |
| needs options | 26: 3.3 ×5 · 3.4 ×3 · 3.5 ×6 · 3.6 ×6 · 3.7 ×4 · 3.8 ×2 |
| owner decision / non-UI → 3.2 | 9 |

### Changes from the approved point list (#316)

**Moved to Phase 4**, because each has one obvious fix:
- from 3.3: A-30, A-61
- from 3.4: A-34, A-51, A-64
- from 3.6: A-13, A-57, A-67
- from 3.7: A-37, A-41
- from 3.8: A-65 (no token changes)

**Moved into a point:**
- A-02 → 3.3 (a real choice);
- A-55 → 3.2 (the owner's open question about the dot).

**Wording gap:** 3.1's line says "needs options (→ 3.3–3.7)". The token rows go to 3.8, as that point's own line says.

## 2. Owner decisions (F-42 · 3.2)

All nine questions were asked on 2026-10-12, each with a recommendation. The owner said "go green" without answering, so each row is recorded as **deferred**, with the recommendation kept as it was asked. That is the rule stated when the questions were put.

**Answered 2026-10-12 (F-42 · 4.1):** the owner took every recommendation below. The Status column now reads "answered", and §1 places each row.

| Row | Status | Question | Recommendation |
|---|---|---|---|
| A-03 | answered | Request per-row abilities on the quotation payload (SEC-09)? | Yes, as its own fix point; F-42 fixes only the wrong "create" message on the edit route |
| A-15 | answered | A server total preview, or accept "save, then see"? | A server preview as its own fix point (§7.2 asks for the total; D-67 forbids the SPA computing it); until then a line under Save |
| A-18 | answered | Request `deal_code`, `customer_name` and the timeline actor's name? | Yes, as one fix point shaped like F-41 · 1.6b's `owner_name`; F-42 adds the gap and "—" |
| A-25 | answered | Interim landing per role: the first built screen in §8's list, or keep Ping until Module 14? | The first built screen, as its own point outside F-42 (it is a route change); `RoleLandingTest` reads §8 |
| A-26 | answered | Apply D-99's display form (2 dp money, trimmed quantities, grouping) on screen? | Yes: display only, so the screen and the PDF show the same figure |
| A-45 | answered | Request the impersonated role's display name, and fill `roles.name_ar`? | Yes to both: while impersonating, the target user cannot read the roles list, and `name_ar` is empty for every role |
| A-54 | answered | A customer's deals on its page: build now or defer? | Defer until F-41 closes (F-41 · 1.7 adds a filter to the same list); the `tel:`/`mailto:` links are built now |
| A-55 | answered | One shared status chip: without the dot, or with it? | Without (the owner's newer ruling for deals; one line on the quotation chip) |
| A-62 | answered | Margin accepts a minus sign (`D-106`; `SaveQuotationRequest.php:90` `^-?`): keep `type="text"`? | Keep it and add a comment saying why; the row would then become an obvious fix |

## 3. Dialogs and confirmations (F-42 · 3.3)

**Rows covered:** A-02, A-04, A-09, A-22, A-35. The CHECKLIST line also names A-30 and A-61, but 3.1 moved both to Phase 4 (#317).

**F-41.** A-04, A-09 and A-22 carry the F-41 flag in §1. They are designed here, but the parts on the deals screens are built only after F-41 closes.

**The mock-up.** `prototypes/ux-dialogs/index.html` is untracked. It loads the real `crm/resources/css/tokens.css` and is served by `preview_start {name: "ux-prototypes"}` at `http://localhost:8790/prototypes/ux-dialogs/index.html`. 3.4–3.7 reuse the same entry for their own `prototypes/ux-<pattern>/` pages.

**How the walk-throughs were done** (2026-10-12):
- Real key presses in the browser pane: Enter, Tab, Escape. A focus log on the page records every focus change.
- Every option in Arabic at 1280.
- The dialog and the Undo bar opened in Arabic at 375, English at 375 and English at 1280.
- Horizontal overflow is 0 in all four states, and every dialog sits inside the viewport (for example 19–356 of 375).

**A walk passes when:**
- focus lands inside on open;
- Tab never reaches the page behind;
- Escape cancels;
- focus never ends on `<body>`, and returns to the control that opened it, or to the result it produced.

**Option A for A-09 and A-35** uses A-04's Option A mechanism. The two pairs differ only in confirming versus undoing.

**Source:** DS §6.6. Dialogs "trap focus, label the purpose, support Escape where cancellation is safe, and return focus to the invoking control". Approval and return "show their consequence".

### A-04, the shared dialog behaviour

- **A-04 Option A** — the native `<dialog>` with `showModal()`. — walk: pass
  - The log reads `#a04a → #nName → Save → Cancel → #nName → Save → Cancel`. Escape closes the dialog, and focus returns to `#a04a`. The browser makes the page inert and owns Escape.
  - Native `cancel` can be `preventDefault`ed while a save is in flight (the A-30 fix).
- **A-04 Option B** — today's `fixed inset-0` markup, plus a helper of about 8 lines: `inert` on the page root, focus the first field, Escape closes, restore the opener. — walk: pass
  - The log reads `#a04b → #bName → Save → Cancel → #bName …`. Escape closes the dialog, and focus returns to `#a04b`.
  - **But** `inert` on the page root also inerts any dialog inside it. In the app every dialog renders inside `#app` (`createApp(App).mount('#app')`, with 0 `<Teleport>` uses), so Option B also needs each of the 14 dialogs moved out with `<Teleport to="body">`.
- **A-04 Recommendation** — **Option A.** It meets DS §6.6 where the dialogs already sit. It needs no Teleport and no trap code, so it is the smallest change that satisfies the rule. *(Chosen: the owner's pick, 2026-10-12.)*
  - The stacking is the browser's top layer, so the dialog can't be covered by the sticky bar.
  - The backdrop needs a colour: the `--color-overlay` token is still owed (DS §10, row A-42 at 3.8). The mock-up uses `rgb(0 0 0 / 0.5)` as a stand-in.

### A-02, the customer dialog after its first save, when similar customers exist

- **A-02 Option A** — the dialog stays open. It is retitled "Edit customer «…»", and the similar-customer warning is inserted at its top with focus on it. — walk: pass
  - The log reads `#a02a → #cName → Save → #a02warn`. Escape then returns focus to `#a02a`.
- **A-02 Option B** — the dialog closes, and the warning appears above the list with focus on it. — walk: pass
  - The log reads `#a02b → #dName → Save → #a02list-warning`.
- **A-02 Recommendation** — **Option A.** The defect is a second Save creating a duplicate customer. Edit mode makes a second Save an *update* of the saved record, so the duplicate can't happen. It also keeps the warning beside the record that the user may need to correct. *(Chosen: the owner's pick, 2026-10-12.)*
  - Option B also prevents the duplicate. But it moves the user away from the form just as they are told something about it.

### A-09, Approve

- **A-09 Option A** — a confirm dialog that names the record: "Approve QT-2026-0011?", plus one line on the consequence. — walk: pass
  - The log reads `#a09a → Approve → Cancel → Approve → Cancel → #a09a`, and Escape cancels.
- **A-09 Option B** — the approval happens at once, with an "Undo" bar for 8 seconds and focus on Undo. — walk: fail
  - Undo works: focus moves `#a09b → #undoBtn → #a09b` and the row comes back. But when the bar expires untouched, focus ends on `<body>`.
  - Beyond the focus issue, a real undo needs either a server "un-approve" or holding the request for 8 seconds in the browser. That would make the SPA decide when a state transition happens. **D-67** forbids that ("the SPA never owns a business rule … may preview, never decide", `CRM_Documentation_EN.md:197`; CLAUDE.md § Laravel Conventions: "never owns a calculation, a permission decision, or a state transition"). The audit trail would also record an approval that was then withdrawn.
- **A-09 Recommendation** — **Option A.** DS §6.6 asks approval to "show its consequence" before it happens. Undo is ruled out by D-67, not only by the focus failure. *(Chosen: the owner's pick, 2026-10-12.)*

### A-35, removing in the builder

- **A-35 Option A** — confirm first: "Remove line 1?", with the typed quantity and margin named. — walk: pass
  - The log reads `#a35a → Remove → Cancel → Remove → #a35a`, and Escape cancels.
- **A-35 Option B** — the line is removed at once, with an "Undo" bar. — walk: fail
  - When the bar expires untouched, focus ends on `<body>` (`#a35b → #undoBtn`, then `<body>`).
  - This one is fixable: on expiry, move focus to the next line. And here an undo is honest, because the builder's lines are unsaved local state. No request and no transition are involved.
- **A-35 Recommendation** — **Option B, with the expiry fix**: on expiry, focus moves to the next line or the Add button (A-17's rule). *(Chosen: the owner's pick, 2026-10-12.)*
  - Removing lines is frequent, cheap to reverse and local, so a confirm on every remove slows the seller for no protection.
  - Changing a block's supplier offer wipes its typed values (A-35's other half). That one keeps a confirm, because there is no single row to restore.

### A-22, inline confirmation forms (Return, Delete-confirm, record PO, status change)

- **A-22 Option A** — stay inline. — walk: pass
  - The form is named (`aria-labelledby`) and the trigger carries `aria-expanded`/`aria-controls`. On open, focus moves to the first field; Escape or Cancel returns it to the trigger.
  - The log reads `#a22a → #a22note → #a22a`. The form isn't modal, so Tab after Cancel continues down the page, as it should.
- **A-22 Option B** — the forms become dialogs with A-04's behaviour. — walk: pass
  - The log reads `#a22b → textarea → Send → Cancel → textarea → Send → #a22b`, and Escape returns focus.
- **A-22 Recommendation** — **Option B for the Return note and Delete-confirm, Option A for the rest.** *(Chosen: the owner's pick, 2026-10-12.)*
  - The Return note is drawn above the table, off-screen from row 12 (SH-31). Delete is destructive, so its confirmation needs the dialog's announcement (Q-07). Both are exactly what a dialog is for.
  - The status change in a deal row, and recording a PO, are part of the row or page the user is already reading. Moving them into a dialog adds a layer for no gain, so they stay inline with Option A's focus handling.

## 4. Focus, highlight and feedback (F-42 · 3.4)

**Rows covered:** A-08, A-31 and A-32. The CHECKLIST line also names A-34, A-51 and A-64. 3.1 moved all three to Phase 4 (#317), because each has one fix: a global `:focus-visible` rule, and hover rules.

**F-41.** A-31 and A-32 carry the F-41 flag in §1. Their deal-screen parts are built only after F-41 closes.

**The mock-up.** `prototypes/ux-feedback/index.html` is untracked. It loads the real `tokens.css` and is served by `ux-prototypes` at `http://localhost:8790/prototypes/ux-feedback/index.html`.

**How it was measured** (2026-10-12):
- Contrast uses the WCAG 2.x formula in the page. It reads each element's computed colour, and a transparent background falls back to its parent's.
- **The checker was proven able to fail first:** planted `#eee` on white = **1.16**, `#222` on Midnight's canvas = **1.20**, black on white = **21**.
- **"measure: pass"** means:
  - the option's signal is ≥ 3:1 against every colour next to it in all 3 themes (WCAG 1.4.11);
  - its text is ≥ 4.5:1;
  - for feedback, the message obeys DS §6.6.
- **Keyboard:** real ArrowDown, ArrowUp and Enter presses move `aria-activedescendant` and pick the option.
- **Width and direction:** at 375, Arabic and English both have horizontal overflow 0. The picker list sits at 33–342 of 375. The toast sits at 16–241 in RTL and 147–359 in LTR, at the inline end in both.

**Contrast measured:**

| Measured pair | Warm Editorial | Clean Monochrome | Midnight Obsidian |
|---|---|---|---|
| Today: highlighted row vs the list (background only, A-08's defect) | **1.12** | **1.04** | **1.17** |
| A: inset `focus-ring` outline vs the highlighted row | 4.61 | 4.95 | 9.14 |
| B: start-edge `focus-ring` bar vs the highlighted row | 4.61 | 4.95 | 9.14 |
| B: the same bar vs the list beside it | 5.18 | 5.17 | 7.78 |
| In place: `success` text on `surface` | 7.13 | 7.13 | 10.14 |
| In place: `danger` text on `surface` | 6.57 | 6.57 | 8.74 |
| Toast: `canvas` text on `text` | 14.31 | 16.12 | 15.54 |

B-22 measured today's Midnight highlight at 1.03; this mock-up's list background gives 1.17. Both fail the same 3:1 floor, and the difference doesn't change the comparison.

### A-08, the highlighted option in a picker

- **A-08 Option A** — an inset 2 px outline in `focus-ring`, plus today's `surface-muted` fill. — measure: pass
  - The outline vs the row is 4.61 / 4.95 / 9.14.
  - The walk passes: two ArrowDown presses → `listA-1`.
- **A-08 Option B** — a 4 px bar in `focus-ring` on the row's **start** edge (it mirrors in RTL through `border-inline-start`), text at weight 600, plus today's fill. — measure: pass
  - The bar vs the row is 4.61 / 4.95 / 9.14, and vs the list 5.18 / 5.17 / 7.78.
  - The walk passes: Down ×3, Up, Enter → `listB-1`, and the value becomes «مؤسسة الأمل».
- **A-08 Recommendation** — **Option B.** *(Chosen: the owner's pick, 2026-10-12.)*
  - Both clear 3:1 by the same margin, but the input above the list already draws a `focus-ring` outline while you type. Option A puts a second, identical ring in the list, so there would be two focus-looking rings on screen at once.
  - Option B's bar plus weight is a different shape that can't be mistaken for keyboard focus. Two signals that aren't colour also meet DS §4.1's "never colour alone" twice over.

### A-31, results of slow actions (import, upload, assign)

- **A-31 Option A** — the result is shown in place: one `role="status"` region beside the action, **present from load** (empty), with its text set when the result arrives. — measure: pass
  - `success` is 7.13 / 7.13 / 10.14 and `danger` is 6.57 / 6.57 / 8.74.
  - The region exists before its text (`role=status`, text ""), which is A-31's defect fixed. An error stays on screen until the next action.
- **A-31 Option B** — the result is shown in one shared toast region in the page corner, present from load and cleared after 5 s. — measure: fail
  - The text contrast passes (14.31 / 16.12 / 15.54), but the import error «رُفض الملف: السطر 4 بلا اسم عميل…» was **gone after 6 s**.
  - DS §6.6 says "a toast must not be the only place an error is explained", and these results are mostly errors: a rejected CSV, a refused upload.
- **A-31 Recommendation** — **Option A.** The result belongs next to what produced it, and errors must stay readable. It also needs no new component: the region is a plain `<p role="status">`, rendered always rather than behind a `v-if`. The one at `SearchCombobox.vue:240-243` is behind a `v-if`, which is A-31's very defect, so it is the element to reuse, not the pattern. *(Chosen: the owner's pick, 2026-10-12.)*

### A-32, confirming a success (approve, return, deal save)

- **A-32 Option A** — in place: the same always-present `role="status"` region, set at the top of the list, because the approved row itself disappears. — measure: pass
  - `success` is 7.13 / 7.13 / 10.14, and the message stays until the next action.
- **A-32 Option B** — a toast: the shared corner region, 5 s. — measure: pass
  - The text is 14.31 / 16.12 / 15.54, and a short success message is exactly DS §6.6's permitted use of a toast.
- **A-32 Recommendation** — **Option A.** The toast is allowed here, but A-31 already needs the in-place region. One mechanism for every result is less to build and keeps errors and successes in the same place. *(Chosen: the owner's pick, 2026-10-12.)*
  - The app has **no toast component today**, so Option B would add one, plus a timing rule (WCAG 2.2.1), for a case Option A already covers.
  - If the owner wants toasts anyway, they must be for successes only, never errors.

## 5. Page structure (F-42 · 3.5)

**Rows covered:** A-21, A-28, A-33, A-43, A-53, A-56, all six rows §1 sorts to 3.5.

**F-41.** A-33, A-43 and A-53 carry the F-41 flag in §1. Their deal-screen parts are designed here and built only after F-41 closes.

**The mock-up.** `prototypes/ux-structure/index.html` is untracked. It loads the real `tokens.css`, and `ux-prototypes` serves it at `http://localhost:8790/prototypes/ux-structure/index.html`. Its `measure()` returns every number below.

**How it was measured** (2026-10-12):
- Contrast uses the 3.4 checker unchanged. **It was proven able to fail first:** planted `#eee` on white = **1.16**, `#222` on Midnight's canvas = **1.20**, black on white = **21**.
- **A-21's shift check was proven able to fail too:** before the 403 is simulated, `measure()` reports A-21 A as failing.
- The 403 was simulated by a real click.
- At 1280: Arabic and English in all 3 themes. At 375: Arabic and English. Horizontal overflow is 0 in every state, and every option sits at 33–342 of 375.
- **"measure: pass"** means the row's own rule (below) holds, text is ≥ 4.5:1 and a border used as the signal is ≥ 3:1, in all 3 themes.

**Contrast and geometry measured** (Warm Editorial / Clean Monochrome / Midnight Obsidian; 1280 unless stated):

| Measured | Warm | Mono | Midnight |
|---|---|---|---|
| A-21 A: the muted refusal line | 7.63 | 7.73 | 9.12 |
| A-33: row Danger text (A and B) | 6.20 | 5.98 | 9.44 |
| A-33 A: row Danger border | 6.20 | 5.98 | 9.44 |
| A-33: filled Danger in the confirm (`text-inverse` on `danger`) | 6.57 | 6.57 | 9.44 |
| A-43 A: the inline link | 7.31 | 6.70 | 8.90 |
| A-56: the final total | 14.31 | 16.12 | 15.54 |

### A-28, one h1

The rule: exactly one `h1`, and it names the page's subject.
- **A-28 Option A** — the bar title becomes plain text (`AppContextBar.vue:136`, `h1` → `p`), and each page keeps its own `h1`. — walk: pass
  - One `h1`, and on the deal page it reads `DL-2026-0006`.
- **A-28 Option B** — the bar keeps its `h1`, and each page's title becomes plain text. — walk: pass
  - One `h1`. **But** the bar's title comes from the route's `titleKey`, and the detail routes use the list's key (`router/index.ts:186`, `:256`: `deals.title`, `quotations.title`). So on a detail page the only heading reads «الصفقات» / "Deals", and the record's code is no longer a heading.
- **A-28 Recommendation** — **Option A.** It is a one-element change in one shared component, and it keeps the record's name as the page heading on every page that has one (19 `<h1>` elements in 18 page files). *(Chosen: the owner's pick, 2026-10-12.)*
  - **Ceiling:** Home (`Ping.vue`) and `/403` (`ForbiddenView.vue`) have no page `h1` (0 each), so under Option A they would have none. Phase 4 gives them one; on `/403`, `PermissionDeniedState`'s `h2` is the candidate.

### A-21, a section the user may not see (the PDF card for Procurement)

The rule: after the 403 arrives, nothing below the section moves, and the refusal adds no heading and no admin prompt.
- **A-21 Option A** — the card keeps its own title, and its body becomes one muted line: «هذا القسم غير متاح لك.» / "This section is not available to you." — measure: pass
  - The card below moves **0 px** at 1280 and at 375, in both languages. The refusal adds no heading; the card's own title stays.
  - The line names nothing beyond the card's title, so `PermissionDeniedState`'s no-enumeration rule (its header comment) still holds.
- **A-21 Option B** — the card is hidden once the 403 arrives. — measure: fail
  - The panel learns the refusal only from the API (`QuotationPdfPanel.vue:78`), so the card is drawn loading first and then removed. The card below jumps up **87 px**, at both widths and in both languages.
- **A-21 Recommendation** — **Option A.** Option B could only avoid the jump if the page knew the answer before asking. The SPA does not decide permissions (D-67), so it can't. *(Chosen: the owner's pick, 2026-10-12.)*

### A-33, one Primary, row-level Danger

The rule: at most one Primary per context; row Danger text ≥ 4.5:1; filled Danger only inside the confirm; every button ≥ 44 px tall.
- **A-33 Option A** — row Danger is an outline: a `danger` border and text on a transparent fill. — measure: pass
  - Primaries per context are 1 / 0 / 0 (action bar / rows / confirm). Text and border are 6.20 / 5.98 / 9.44. Buttons are 44 px at both widths.
- **A-33 Option B** — row Danger is text only: `danger` text, no border, the same size as the ghost Edit beside it. — measure: pass
  - Primaries per context are 1 / 0 / 0. Text is 6.20 / 5.98 / 9.44. Buttons are 44 px.
- **A-33 Recommendation** — **Option B.** Customers draw Archive on every row (178 today). A red outline on each still makes the list a wall of red, while red text reads as one weight with Edit and stays distinct by colour plus its own label. *(Chosen: the owner's pick, 2026-10-12.)*
  - The filled Danger stays where DS §6.2 puts the irreversible step: inside the confirm (6.57 / 6.57 / 9.44).
  - Ghost's prohibited use is "critical workflow confirmation". The row button only opens the confirm, so Option B doesn't break it.
  - Reject (styled primary today) becomes Danger by the same rule.

### A-43, error wording with a next step

The rule: every case says what happened and gives one next step. The case's copy is a **draft, the owner's to approve**.
- Out of scope or missing: «السجل غير متاح. راجع الرابط، أو ارجع إلى قائمة الصفقات.» / "The record is not available. Check the link, or return to the deal list." This is the customer page's existing sentence (`customers.detail.notFoundMessage`) made the one wording for every record.
- No connection: «تعذّر الوصول إلى الخادم. تحقّق من الاتصال ثم أعد المحاولة.» / "The server could not be reached. Check the connection, then try again." It replaces the raw `error.message`.
- `/403`: «ليست لديك صلاحية عرض هذه الصفحة.» / "You do not have permission to view this page." Plus a link Home.

Options:
- **A-43 Option A** — the next step is a link inside the sentence. — measure: pass
  - Each of the 3 cases has 1 next step. The link is 7.31 / 6.70 / 8.90.
- **A-43 Option B** — the next step is a button under the sentence. — measure: pass
  - Each of the 3 cases has 1 next step. The buttons are 44 px tall at both widths.
- **A-43 Recommendation** — **Option B.** `ErrorState` already shows its next step (Retry) as a button, so Option B is that component's existing shape with the action named per case. It also meets DS §8's 44 px target on the phone, which an inline link in a sentence does not. *(Chosen: the owner's pick, 2026-10-12.)*
  - The record header stays above the error, so the page still says which record failed (audit row).

### A-53, the deal header

The rule: a back link, the customer as a link, and an `h1` that names the record in every case in at most 2 lines.
- **A-53 Option A** — `h1` = the code, with the status chip beside it. Under it: the deal's title · the customer as a link. A back link sits above, as on the customer page (`CustomerDetailView.vue:234-240`). — measure: pass
  - The `h1` is 1 line at 1280 and at 375.
- **A-53 Option B** — `h1` = the deal's title. Under it: code · status · customer link. The same back link. — measure: fail
  - The title is optional (`SaveDealRequest.php:49`, `nullable`, max 255). With no title, the heading is «—».
  - A realistic title wraps to 2 lines (Arabic) and 3 (English) at 1280, and to 3 and 5 at 375.
- **A-53 Recommendation** — **Option A.** The code always exists, is short, and is what people quote. The title is shown in full right under it. *(Chosen: the owner's pick, 2026-10-12.)*

### A-56, the total in the quotation header

The rule: the final total is visible at load without scrolling, and its text is ≥ 4.5:1.
- **A-56 Option A** — the final total is repeated at the header's end, beside the code and status. — measure: pass
  - It sits 42 px from the top at load and covers nothing. Text is 14.31 / 16.12 / 15.54.
  - It scrolls away with the header.
- **A-56 Option B** — a sticky bar at the bottom carries the final total. — measure: pass
  - It is visible at load and after scrolling, and its text is 15.17 / 17.72 / 12.59. But it covers **36–38 px** of the lines at every scroll position, at both widths.
- **A-56 Recommendation** — **Option A.** DS §5.2 asks for "summary first", and the header is where Option A puts it. A permanent bar would compete for the same bottom edge as 3.7's sticky Save (A-20), and the full totals block stays at the bottom for the breakdown. *(Chosen: the owner's pick, 2026-10-12.)*

## 6. Lists (F-42 · 3.6)

**Rows covered:** A-14, A-19, A-39, A-40, A-48, A-49. The CHECKLIST line also names A-13, A-57 and A-67, but 3.1 moved all three to Phase 4 (#317). So "URL filters" (A-13) isn't in this point.

**F-41.** All six rows carry the F-41 flag in §1. They are designed here and built only after F-41 closes. `DealsView.vue` is unchanged.

**The mock-up.** `prototypes/ux-lists/index.html` is untracked. It loads the real `tokens.css`, and `ux-prototypes` serves it at `http://localhost:8790/prototypes/ux-lists/index.html`.
- Each row draws **today's behaviour** beside its two options, as the planted failing case. **Every check below was seen failing on today's version** before any option was measured.
- The page carries a sticky bar like `AppContextBar.vue:119` (`sticky top-0`). The page scrolls the window, so a sticky table header has to stick *under* that bar.

**How it was measured** (2026-10-12):
- **A-14:** real clicks on Next in Arabic at 1280.
- **A-49:** real typing into each search box, then the status select changed.
- **Everything else:** Arabic and English at 1280 and at 375.
- **Contrast:** the 3.4 checker, in all 3 themes. It was proven again: planted `#eee` on white = 1.16, black on white = 21.
- **Width:** at 375 every option sits at 33–342. Page overflow is 0 in all four states, after two mock-up fixes (below).
- **"measure: pass"** means the row's own rule (below) holds, and text is ≥ 4.5:1 in all 3 themes.

**Contrast measured** (Warm / Mono / Midnight):

| Measured pair | Warm | Mono | Midnight |
|---|---|---|---|
| A-14 A: the loading text on the veil | 15.17 | 17.72 | 14.40 |
| A-14 A: stale rows under the veil (inactive while busy) | 2.37 | 2.53 | 3.32 |
| A-14 B: stale rows at 50 % opacity (inactive while busy) | 3.14 | 3.47 | 4.38 |
| A-19 A: the compact Status action | 7.31 | 6.70 | 8.90 |
| A-19 B: the card's muted line | 7.63 | 7.73 | 9.12 |
| A-40: the header cell | 13.49 | 16.97 | 14.78 |
| A-49 A: the "not applied yet" note | 7.20 | 7.03 | 9.85 |

Both A-14 options deliberately dim the stale rows below 4.5:1 while the next page loads. That is the busy signal itself, not content to read. WCAG 1.4.3 exempts inactive components, and B marks the region `aria-busy`. The rows have to come back to full contrast when the load ends.

### A-14, reload without blanking

The rule: after Next is pressed, focus stays on Next (never `<body>`), and the table stays mounted while the next page loads.
- **Today**, measured by real click: focus lands on `body`, and the table is gone while loading. **This fails the rule.**
  - The scroll jump the audit saw was **not reproduced**: the mock-up's page is long, and `scrollDelta` = 0 for all three.
- **A-14 Option A** — the table stays, and a veil with "Loading…" covers it until the rows update in place. — measure: pass
  - Focus stays on Next, and the table is present during the load and kept after.
- **A-14 Option B** — the table stays at 50 % opacity with `aria-busy="true"`, and a `role="status"` line above it reads «جارٍ تحديث القائمة…» / "Updating the list…". — measure: pass
  - Focus stays on Next, and the table is present during the load and kept after.
- **A-14 Recommendation** — **Option B.** Its status line is announced, while A's veil text is not in a live region. It is also the in-place `role="status"` region 3.4 recommends for slow-action results (A-31) and successes (A-32), so there is one mechanism, not two. *(Chosen: the owner's pick, 2026-10-12.)*
  - The stale rows must ignore clicks while `aria-busy` is set. The mock-up doesn't draw that; the build adds it.

### A-19, the deals list on a phone

The rule: at 375, the first row starts in the top half of an 812 px screen, and deal codes take one line.
- **Today:** the four filters are drawn open, and every row has a full "Change status" button. The first row is at **292** (ar) / **285** (en). Codes take **3 lines**, and 7 / 6 rows fit in 812. **This fails the rule.**
  - The audit measured 574–694 on the real page, with the real bar and banner. The mock-up's frame differs, so compare the options with each other, not with that number.
- **A-19 Option A** — the filters fold behind one «عوامل التصفية (2)» / "Filters (2)" button; rows stay a table with `nowrap` codes, and status is a compact text action. — measure: pass
  - The first row is at 198 / 195, codes take 1 line, and **10 rows** fit in 812.
- **A-19 Option B** — the same folded filters, with rows as cards: code + status chip, customer, total + Status action. — measure: pass
  - The first row is at 198 / 195, codes take 1 line, and **5 cards** fit in 812.
- **A-19 Recommendation** — **Option A.** It shows twice the deals per screen for the same first-row position. The folded "Filters" button is shared by both options, and that button is what moves the first row up. *(Chosen: the owner's pick, 2026-10-12.)*
  - A-39 (approvals, quotations) does need cards, because those rows carry money and two buttons. A deal row needs only code, customer and one action.

### A-39, tables at 375 (the approvals table)

The rule: at 375, the table doesn't scroll sideways, and Approve is inside the viewport.
- **Today:** the table scrolls sideways by **591 px** (ar) / **659 px** (en), and Approve is off-screen. **This fails the rule.**
- **A-39 Option A** — below 640 px each row becomes a card of labelled lines (code, customer, deal, submitter, date, total, actions). — measure: pass
  - Sideways scroll is 0 in both languages, and Approve is in view.
- **A-39 Option B** — below 640 px the secondary columns (customer, deal, submitter, date) are hidden (`hidden md:table-cell`), leaving code, total and actions. — measure: fail
  - It still scrolls sideways by **128 px** (ar) / **165 px** (en), and Approve is off-screen. Code + a money figure + two 44 px buttons don't fit in 343 px.
  - The hidden columns also take the customer's name away from the approver.
- **A-39 Recommendation** — **Option A.** It is DS §4.3's own wording for below 640 px ("tables become cards"), and it is the only option that passes. *(Chosen: the owner's pick, 2026-10-12.)*
  - At 1280 all three show the full table with 0 sideways scroll, so the cards apply only below 640.

### A-40, the sticky table header

The rule: on a long list, once the table passes under the context bar, the header row sits right below the bar (or at the top of its box).
- **Today** (`overflow-x: auto` on the frame): the header scrolls away to **−286** (1280) / **−182** (375) while the bar ends at 57 / 109. **This fails the rule.**
  - It fails even when nothing scrolls sideways (0 at 1280). `overflow-x: auto` alone makes the frame the sticky container.
- **A-40 Option A** — the frame becomes `overflow-x: clip`, and the page scrolls. The header sticks at `top: <bar height>`. — measure: pass
  - The header sits at exactly the bar's bottom: 57 at 1280, 109 at 375 (the bar is two rows on the phone).
  - **But** `clip` cuts off whatever is wider than the frame: at 375 the plain 3-column test table loses **128 px** that can't be reached.
- **A-40 Option B** — the frame keeps its scroll, with a `max-height: 60vh`, so the header sticks at the frame's own top. — measure: pass
  - The header sits at the box's top (65 / 117). But the list becomes a 480–487 px box inside a page that also scrolls. That is two scrollbars, and on a phone the inner box traps the swipe.
- **A-40 Recommendation** — **Option A, together with A-39 Option A.** The two rows depend on each other, as §1 says. *(Chosen: the owner's pick, 2026-10-12.)*
  - At 640 px and wider the tables fit, so `clip` hides nothing and the header sticks under the bar.
  - Below 640 px the rows are cards, so there is no header to stick and nothing wide to clip.
  - A wide table that must stay a table below 640 px (the 7 cost columns of a supplier offer, S7) keeps today's `overflow-x: auto` and gives up the sticky header there. That table is the exception, and it is named here so Phase 4 doesn't clip it.

### A-48, when the filter bar shows

The rule: a refused user sees no working search box (DS §8), and a user with an active filter can always see and clear it.
- **Today:** the bar shows in all four states, refused included. **This fails the rule.**
- **A-48 Option A** — the bar shows when the list has data or a filter is active. It is hidden when refused, and hidden for an empty list with no filter. — measure: pass
  - Refused: hidden. Empty with no filter: hidden. Empty with a filter: shown. Data: shown.
- **A-48 Option B** — the bar shows always, except when refused. — measure: pass
  - Refused: hidden. Empty with no filter: **shown**. Empty with a filter: shown. Data: shown.
- **A-48 Recommendation** — **Option A.** Both pass the rule, but B keeps the defect the audit actually found (B-27). OSup, TL and Procurement see a full filter bar above a deal list that is always empty for them. *(Chosen: the owner's pick, 2026-10-12.)*
  - Under A, the bar reappears the moment there is something to filter.
  - The rule reads only what the API returned (rows, or a 403), so the SPA decides no permission (D-67).

### A-49, one apply rule per bar

The rule: no request carries text the bar shows as not applied. Measured by typing «شر», then changing the status.
- **Today:** the select change sent `q="شر"` while the last applied search was `""`. That half-typed text was applied silently. **This fails the rule.**
- **A-49 Option A** — everything waits for Apply. The select change sends nothing, and «تغييرات لم تُطبَّق بعد» / "Changes not applied yet" appears. — measure: pass
  - 0 requests until Apply.
- **A-49 Option B** — everything is live: selects at once, text after a 400 ms pause. — measure: pass
  - Typing «شركة النور» (10 keys) sent **1** request. The select then sent 1 more, with the same text, which the list already showed as applied.
- **A-49 Recommendation** — **Option B.** Selects already apply at once today, so B changes only the text box. *(Chosen: the owner's pick, 2026-10-12.)*
  - The pause already exists: `SearchCombobox.vue:53,106-110` waits `PAUSE_MS = 300` with `clearTimeout`/`setTimeout`. The build reuses that pattern and its 300 ms rather than writing a second timer with a different delay. Moving it into a shared helper is Phase 4's call.
  - A would add a click to every select change on every list, plus a "not applied" notice to keep honest.
  - With A-19's folded filters on the phone, B needs no Apply button in the panel.
  - **Ceiling:** the mock-up's 400 ms was a guess, and the combobox's 300 ms is not measured against the list API either.

## 7. Builder (F-42 · 3.7)

**Rows covered:** A-11, A-20, A-24, A-50. The CHECKLIST line also names A-37 ("Enter") and A-41 ("per-block loading"), but 3.1 moved both to Phase 4 (#317).

**F-41.** A-11 and A-24 carry the F-41 flag in §1. Their deal-screen parts (`DealFormModal`, the deal documents panel) are built only after F-41 closes.

**The mock-up.** `prototypes/ux-builder/index.html` is untracked. It loads the real `tokens.css`, and `ux-prototypes` serves it at `http://localhost:8790/prototypes/ux-builder/index.html`.
- Each row draws **today's behaviour** beside its options as the planted failing case. **Every rule below was seen failing on today's version first.**
- A-20 draws one option per load (`?a20=today|A|B`), because two sticky bars on one page interfere with each other.
- The page carries a sticky bar like `AppContextBar.vue:119`.

**How it was measured** (2026-10-12):
- **A-11:** a real Enter press in a field of each form, Arabic, at 1280.
- **A-20:** a real Tab walk through the 28 line fields: forwards for A, backwards (Shift+Tab) for B, at 1280 and 375.
- **A-24:** a real Tab onto the file control, plus a screenshot of the native button.
- **Everything else:** Arabic and English at 1280 and 375, and contrast in all 3 themes with the 3.4 checker.
- **Width:** at 375 every option sits at 33–342, and page overflow is 0 in all four states.

**Contrast measured** (Warm / Mono / Midnight):

| Measured pair | Warm | Mono | Midnight |
|---|---|---|---|
| Field error text (`danger`) | 6.20 | 5.98 | 9.44 |
| A-11 A: summary link | 6.57 | 6.57 | 8.74 |
| A-24 A: the translated button | 15.17 | 17.72 | 14.40 |
| A-24 A: its focus ring vs the canvas | 4.89 | — | — |
| A-50: the «(مطلوب)» / "(required)" marker | 6.20 | 5.98 | 9.44 |
| A-50: help text (`text-muted`) | 7.20 | 7.03 | 9.85 |

### A-11, errors on a failed save

The rule: after a failed save, focus moves to the summary or to the first invalid field. Every invalid field carries `aria-invalid="true"` and an `aria-describedby` that points at its own error text.
- **Today:** the Enter press leaves focus in the currency field. The red line appears at the top (the builder's `formError`, `QuotationBuilderView.vue:698`). The two field errors are tied to nothing. **This fails the rule.**
- **A-11 Option A** — an error summary at the top («يجب تصحيح حقلين» / "Fix 2 fields") takes focus. It has one link per error to its field, and each field is tied to its error. — measure: pass
  - Focus lands on the summary (`al-A`). Both fields are tied.
- **A-11 Option B** — focus moves to the first invalid field. The existing top line gives the count («يجب تصحيح حقلين قبل الحفظ.»), and each field is tied to its error. — measure: pass
  - Focus lands on the customer field (`c-B`). Both fields are tied.
- **A-11 Recommendation** — **Option B.** Focus lands where the fix happens, and the screen reader reads that field's own error through `aria-describedby`. The count goes into the `role="alert"` line the builder already has (`:698`), so nothing new is built beyond the ids and the `focus()`. *(Chosen: the owner's pick, 2026-10-12.)*
  - Option A adds a summary component with links. It is the better shape for a form whose errors are many and far apart, which the builder's lines can be. If the owner expects 3+ errors per save to be common, A is worth its extra size.
  - Either option closes the audit's `aria-invalid` gap on margin and its "one `aria-describedby` in the whole slice". The import modal's missing `role="alert"` (`ImportModal.vue:163`) is part of A-11 too, and either option adds it.

### A-20, where Save sits on a long builder

The rule: halfway down a 14-line builder, Save is on screen, and no field focused by Tab is hidden under a sticky bar.
- **Today:** Save is only at the end of the form. At mid-scroll it is **not on screen**. **This fails the rule.**
- **A-20 Option A** — a sticky bar at the bottom of the viewport carries Save and Cancel. The page gets `scroll-padding-block-end: <bar height>`. — measure: pass
  - Save is on screen at mid-scroll at both widths.
  - A real Tab walk through all 28 fields hid **0**, at 1280 and at 375.
  - The bar is 65 px; on the phone the screen keeps 109 px at the top (context bar) and 65 at the bottom.
- **A-20 Option B** — a sticky bar under the context bar carries Save. The page gets a `scroll-padding-block-start` of both bars. — measure: pass
  - Save is on screen. A real Shift+Tab walk through all 28 fields hid **0**.
  - The top stack becomes **122 px** at 1280 and **174 px of 812** at 375: more than a fifth of the phone is fixed at the top.
- **The hidden-field check was proven able to fail:** Option A with its `scroll-padding` removed hid **4 of 28** focused fields under the bar.
- **A-20 Recommendation** — **Option A.** *(Chosen: the owner's pick, 2026-10-12.)*
  - On the phone, A and B use the same 174 px. A splits it between top and bottom; B stacks all of it at the top, where the lines, and the sticky table headers of §6 (A-40), would sit under it.
  - The bottom is where a form ends, and DS §6.2's single Primary sits at the end of its flow.
  - **The `scroll-padding` is part of the option, not an extra:** without it the bar hides fields (measured above).
  - **Not measured:** the phone's on-screen keyboard. A sticky bottom bar can sit behind the keyboard on iOS. The browser pane has no soft keyboard, so this is for the device check in Phase 6.

### A-24, the file control

The rule: on an Arabic page, the control's visible button reads in Arabic, and the allowed formats and size limit are shown before any upload, with `accept` set.
- **Today:** the native control, with no `accept` and no limits text. In the screenshot it reads "No file chosen / Choose File" in English on the Arabic page. **This fails the rule.**
- **A-24 Option A** — the input is visually hidden but focusable, and its `<label>` is drawn as a button: «اختر ملفاً» / "Choose a file". Under it come «لم يُختر ملف.» and «PDF أو JPG أو PNG، حتى 30 ميغابايت.», tied by `aria-describedby`. — measure: pass
  - A real Tab lands on the hidden input, and its label shows a 2 px focus ring (4.89:1 against the canvas).
- **A-24 Option B** — the native control as it is, plus `accept` and the limits line. — measure: fail
  - The limits line and `accept` are there. But the button and "No file chosen" stay in the **browser's** language (English in the screenshot), whatever the page's locale. A page can't restyle or translate that text.
- **A-24 Recommendation** — **Option A.** *(Chosen: the owner's pick, 2026-10-12.)*
  - The number "30 MB" comes the way `ImportModal.vue:36-46` already gets it: a lang string for `config('files.max_size_bytes')` (D-71), because the SPA has no endpoint for the limit. The drift, if the configuration ever changes, is recorded at `checklist/module-03.md:905` and in that file's `ponytail:` comment.
  - **Non-UI part, as §1 says:** showing the *configured* number live would need an endpoint. That is not F-42's.

### A-50, required marker, help text and disabled reason

The rule: one form uses one kind of required marker on every required field, no rule lives only in a placeholder, and a disabled control states why.
- **Today:** «*» on one field and «(مطلوب)» on another (2 kinds). "Empty = no tax" lives only in a placeholder. The tax-included box is disabled with no reason. **This fails the rule.**
- **A-50 Option A** — the word «(مطلوب)» / "(required)" after the label, in `danger`, with `aria-required`. The tax rule is help text under its field, and the disabled box carries «غير متاح لأن العرض معفى من الضريبة.», both tied by `aria-describedby`. — measure: pass
  - 1 kind, 2 of 2 marked, no placeholder-only rule, the reason present. The marker is 51 px (ar) / 64 px (en) wide.
- **A-50 Option B** — «*» after the label (`aria-hidden`, with `aria-required` on the input), plus one legend line «الحقول المعلَّمة بـ * مطلوبة.» at the top. The same help and reason lines. — measure: pass
  - 1 kind, 2 of 2 marked, no placeholder-only rule, the reason present. The marker is 6 px wide.
- **A-50 Recommendation** — **Option A.** DS §6.3 asks for an "explicit required marker", and the supplier dialog already chose the word for that reason (`SupplierQuotationFormModal.vue:668`: "a word, not only a glyph"). So A makes the other forms match a decision the code has already made. *(Chosen: the owner's pick, 2026-10-12.)*
  - B saves 45–58 px per label, but it needs the legend to be read first and gives a sighted reader a 6 px glyph to find.

## 8. Tokens (F-42 · 3.8)

**Rows covered:** A-42, A-63. The CHECKLIST line also names the dead `--shadow-3` (A-65), but 3.1 moved it to Phase 4 as one obvious fix (use `--shadow-2`, no token change).

**A token change is the owner's decision.** Nothing here touches `tokens.css`. Each recommendation is written as a **proposed** decision in DS §10, and it applies only on the owner's yes.

**The mock-up.** `prototypes/ux-tokens/index.html` is untracked. It loads the real `tokens.css`, and `ux-prototypes` serves it at `http://localhost:8790/prototypes/ux-tokens/index.html`. A select (`today|A|B`) redraws all three themes at once.
- **Today's behaviour is the planted failing case:** every rule below was seen failing on it first.
- The scrim is measured, not eyeballed: a probe element in each theme reads the computed scrim colour and composites it over that theme's `--color-canvas`. The result is the canvas's luminance divided by the luminance under the scrim, so above 1 darkens and below 1 lightens.
- **Width:** at 375 the page overflow is 0.

**Measured today in the code** (2026-10-12):
- **Scrim:** 12 dialogs and drawers use `color-mix(in srgb, var(--color-text) 45%, transparent)`, and `AppSidebar.vue:271` uses `backdrop-filter: brightness(0.45) blur(2px)`.
- **`color-scheme`:** 0 hits under `crm/resources/`.
- **Radius:** `rounded-lg` ×358, `rounded-xl` ×32, `rounded-2xl` ×21, `rounded-full` ×30 and `rounded` ×1. Tailwind 4.3.3's scale is `md` 6 px, `lg` 8, `xl` 12 and `2xl` 16, so §4.2's 6/8/12 are exactly `md`/`lg`/`xl`.
  - Controls are `lg` (8), except `AppContextBar.vue`'s 5 shell controls, which are `xl` (12).
  - Cards are `xl` (12) on the deal and quotation detail pages and table frames, and `2xl` (16) on the customer detail page, the login card and the account-security panels.
  - Dialogs are `2xl` (16).
  - The 358 `lg` uses are counted as controls, as the audit did. They were not classified one by one.

### A-42, the scrim and `color-scheme`

The rule: in every theme, a scrim makes the content behind a dialog darker, and native controls (scrollbars, `<select>` menus, date pickers) paint in the theme's own scheme.
- **Today:** the scrim darkens by 2.93 (Warm) and 3.19 (Mono), but in Midnight it measures **0.03**: the canvas under it is about 33× lighter, the light veil the audit saw. `color-scheme` computes to `normal` in all three themes, and the native select stays light on the dark dialog in the screenshot. **This fails the rule.**
- **A-42 Option A** — a 23rd token, `--color-overlay: rgb(0 0 0 / 0.5)`, in each of the three theme blocks. All 12 scrims and the sidebar use it, plus `color-scheme: light` on `:root` and Clean Monochrome and `color-scheme: dark` on Midnight Obsidian. — measure: pass
  - Darkens by 4.66 / 4.65 / 2.14. `color-scheme` computes to light / light / dark, and the select turns dark in Midnight.
  - It amends `D-73`'s 22-token record: `ThemeTokenTest::EXPECTED` and its "exactly the documented colour tokens" test gain the 23rd name.
- **A-42 Option B** — no new token. All 12 scrims reuse `AppSidebar.vue:271`'s `backdrop-filter: brightness(0.45)`, which darkens what is behind it in any theme, plus the same `color-scheme` lines. — measure: pass
  - Darkens by 5.84 / 5.82 / 2.38. `color-scheme` computes to light / light / dark.
  - Where `backdrop-filter` is unsupported, there is no dimming at all: the dialog separates only by its own shadow and border (`AppSidebar.vue:267-268`). Support on the field staff's phones is not measured here.
- **A-42 Recommendation** — **Option A.** DS §10's "Debt owed to the token set" already calls the token "the better answer", and `AppSidebar.vue:264-266` says the filter stands in only until the token is approved. *(Chosen: the owner's pick, 2026-10-12.)*
  - A colour has no support gap and does not repaint the page behind 12 dialogs. It also keeps §8's "no component hard-codes a color" declarative.
  - Both options need the same `color-scheme` lines, which are an amendment to `D-73` either way.
  - **Seen, not measured:** in Midnight, the dialog's surface (`#111827`) sits close to the darkened canvas in both options. The dialog's border and `--shadow-2` carry the separation.

### A-63, the radius scale

The rule: each role has one radius across the app, and they rise from control to card to dialog, which is §4.2's order.
- **Today:** controls are 8 and 12, cards are 12 and 16, and dialogs are 16. **This fails the rule:** two roles have two values each, and a 12 px control matches a 12 px card.
- **A-63 Option A** — code follows §4.2 as written: controls `rounded-md` (6), cards `rounded-lg` (8), dialogs `rounded-xl` (12). — measure: pass
  - One radius per role: 6 < 8 < 12, and it matches §4.2.
  - It edits about 411 class uses (all `lg`, `xl` and `2xl`), so every screen changes visibly.
- **A-63 Option B** — §4.2 is amended to today's main steps: controls `rounded-lg` (8), cards `rounded-xl` (12), dialogs `rounded-2xl` (16). — measure: pass
  - One radius per role: 8 < 12 < 16. It does not match §4.2 as written; it amends §4.2.
  - It edits 11 uses: `AppContextBar.vue`'s 5 shell controls `xl` → `lg`, and the 6 `2xl` cards (customer detail ×3, login, account security ×2) → `xl`. Everything else already renders this way. The 3 `state-plate` icon tiles (Empty, Error and PermissionDenied states) and the dev page `Ping.vue:110` also use `2xl`; they are not a control, card or dialog, and they stay.
- **A-63 Recommendation** — **Option B.** It keeps §4.2's intent (one radius per role, rising from control to dialog) and changes 11 uses instead of about 411. What renders today stays as it is on every other screen. *(Chosen: the owner's pick, 2026-10-12.)*
  - The edit to §4.2 is one line, written as a proposed decision in DS §10.
  - **Not an option, the same in both:** `max-h-[90vh]` → `max-h-[90dvh]` on the 5 form dialogs (Customer, Supplier, CatalogItem, Deal, SupplierQuotation). This is the row's "Save under the address bar" part.
    - The browser pane has no mobile address bar, so the difference is not measured here; it is for Phase 6's device check.
  - **Ceiling:** the role names (control, card, dialog) are not aliased as tokens (`--radius-control` …). Tailwind's `--radius-*` scale already carries the values. Add aliases if a second scale is ever needed.
