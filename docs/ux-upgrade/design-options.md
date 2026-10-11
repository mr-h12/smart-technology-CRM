# F-42 Phase 3 — design options

This file holds the output of F-42 Phase 3 (CHECKLIST, F-42 · 3.0–3.8), and the rows refer to [`audit.md`](audit.md).

This phase is design only. Two options are drawn only where there is a real choice. A row with one obvious fix gets no options and goes straight to Phase 4.

## 1. Sorting (F-42 · 3.1)

Every audit row has exactly one group:

- **one obvious fix**: the fix in `audit.md` is the only sensible one, and goes to Phase 4 as written.
- **needs options**: two designs will be drawn at the named point.
- **owner decision / non-UI**: the owner rules at 3.2, or the fix needs the API.

**F-41** marks a row that touches the deals screens. It may be designed now but is built only after F-41 closes, whatever its group.

| Row | Group | To | F-41 | Why this group |
|---|---|---|---|---|
| A-01 | one obvious fix | Phase 4 | | Re-run `load()` when the route changes; nothing to choose |
| A-02 | needs options | 3.3 | | After the first save, either switch the dialog to edit mode or close it and show the warning on the list; both are sound |
| A-03 | owner decision / non-UI | 3.2 | | Per-row abilities on the API; the edit-route message rides along |
| A-04 | needs options | 3.3 | ✔ | Native `<dialog>` + `showModal()` vs a shared helper on the current markup |
| A-05 | one obvious fix | Phase 4 | | `inert` on the closed drawer |
| A-06 | one obvious fix | Phase 4 | ✔ | D-70 fixes the digits; the change is in `displayDate.ts`, plus a timestamp sibling |
| A-07 | one obvious fix | Phase 4 | | `dir="ltr"` / `<bdi>` on phones |
| A-08 | needs options | 3.4 | | The highlight's look (outline vs start-edge bar) is a visual choice; it must reach 3:1 |
| A-09 | needs options | 3.3 | ✔ | A confirm step vs an undo window |
| A-10 | one obvious fix | Phase 4 | | Keep Sign-in enabled and validate on submit |
| A-11 | needs options | 3.7 | ✔ | An error summary at the top vs focusing the first invalid field |
| A-12 | one obvious fix | Phase 4 | | `document.title` from the route title; focus the page h1 after navigation |
| A-13 | one obvious fix | Phase 4 | ✔ | Filters, sort and page into `route.query` with `router.replace`; no visual choice |
| A-14 | needs options | 3.6 | ✔ | A spinner over the kept table vs a dimmed table with `aria-busy` |
| A-15 | owner decision / non-UI | 3.2 | | A server preview vs save-then-see |
| A-16 | one obvious fix | Phase 4 | | Drop the placeholder (the figure is already shown at :897) |
| A-17 | one obvious fix | Phase 4 | | Focus the new row's first field; after remove, focus the next row or Add |
| A-18 | owner decision / non-UI | 3.2 | ✔ | Names and codes need the API; the UI part (the gap, "—") is obvious and is built with it |
| A-19 | needs options | 3.6 | ✔ | The phone deals layout: folded filters plus compact rows vs card rows |
| A-20 | needs options | 3.7 | | A sticky bottom bar vs a sticky header action |
| A-21 | needs options | 3.5 | | A muted section line vs hiding the section |
| A-22 | needs options | 3.3 | ✔ | Follows A-04: stay inline with focus handling, or become dialogs |
| A-23 | one obvious fix | Phase 4 | | `aria-label` on rail links when collapsed |
| A-24 | needs options | 3.7 | ✔ | The translated file chooser's look; the limit text is non-UI if the limit is not exposed |
| A-25 | owner decision / non-UI | 3.2 | | An interim landing per role is a route decision |
| A-26 | owner decision / non-UI | 3.2 | | Whether D-99's display form applies on screen |
| A-27 | one obvious fix | Phase 4 | | Use the in-flow picker mode the dialog's own comment asks for; **a browser check confirms the clipping first** |
| A-28 | needs options | 3.5 | | Make the bar title a non-heading vs drop the page h1 |
| A-29 | one obvious fix | Phase 4 | ✔ | `useId()`, already in `SearchCombobox.vue:55` |
| A-30 | one obvious fix | Phase 4 | | A busy label as in `LoginView.vue:177`; ignore Escape and scrim while busy; a busy flag on assign |
| A-31 | needs options | 3.4 | ✔ | A live region in place vs a toast |
| A-32 | needs options | 3.4 | ✔ | The same choice as A-31, for success messages |
| A-33 | needs options | 3.5 | ✔ | How row-level Danger and the single Primary look per page |
| A-34 | one obvious fix | Phase 4 | ✔ | One global `:focus-visible` rule with the existing `--color-focus-ring` (it passes in all 3 themes, B-23) |
| A-35 | needs options | 3.3 | | Confirm before wiping vs undo after removing |
| A-36 | one obvious fix | Phase 4 | | `aria-label` naming the line or supplier |
| A-37 | one obvious fix | Phase 4 | | Block Enter's implicit submit in line inputs |
| A-38 | one obvious fix | Phase 4 | | `min-h-11`, `inline-flex`, and a 44 px label around checkboxes |
| A-39 | needs options | 3.6 | ✔ | Card rows below 640 px vs hiding secondary columns |
| A-40 | needs options | 3.6 | ✔ | Follows A-39: the sticky header's container depends on whether the table still scrolls sideways |
| A-41 | one obvious fix | Phase 4 | | A "Loading…" line; the owner box only after a refusal |
| A-42 | needs options | 3.8 | | The `--color-overlay` value per theme and `color-scheme`, a token change for the owner |
| A-43 | needs options | 3.5 | ✔ | One wording per case; its copy is the owner's to approve |
| A-44 | one obvious fix | Phase 4 | ✔ | Arabic's six plural forms through vue-i18n |
| A-45 | owner decision / non-UI | 3.2 | | The role's display name; `roles.name_ar` is empty for every role |
| A-46 | one obvious fix | Phase 4 | | Reuse the context bar's language segment on login |
| A-47 | one obvious fix | Phase 4 | | Four approvals fixes, each stated in the audit |
| A-48 | needs options | 3.6 | ✔ | When the filter bar shows: with data, or once a filter is active |
| A-49 | needs options | 3.6 | ✔ | Everything on Apply vs text on debounce |
| A-50 | needs options | 3.7 | | The required marker ("*" vs the word) and where help text sits |
| A-51 | one obvious fix | Phase 4 | | Hover and active rules on the shared button styles (`QuotationPdfPanel` already has one) |
| A-52 | one obvious fix | Phase 4 | ✔ | `break-words min-w-0`; a `title` on truncated titles |
| A-53 | needs options | 3.5 | ✔ | What the deal header carries, and where Back sits |
| A-54 | owner decision / non-UI | 3.2 | | Deals by customer needs a list filter; the contact links are obvious and ride along |
| A-55 | owner decision / non-UI | 3.2 | ✔ | The open question about the dot on the status chip (handoff: `QuotationStatusChip` has one, `DealStatusChip` does not) decides the shared chip |
| A-56 | needs options | 3.5 | | Where the total sits in the quotation header |
| A-57 | one obvious fix | Phase 4 | | Show the bulk panel once a row is selected |
| A-58 | one obvious fix | Phase 4 | ✔ | A short `aria-label`; `cursor: pointer` |
| A-59 | one obvious fix | Phase 4 | | "Close" once a result is shown |
| A-60 | one obvious fix | Phase 4 | | A 4th grid column |
| A-61 | one obvious fix | Phase 4 | | The in-app discard prompt the supplier dialog already uses |
| A-62 | owner decision / non-UI | 3.2 | | Whether margin takes a minus sign decides `inputmode` |
| A-63 | needs options | 3.8 | | The radius scale against §4.2, a token change for the owner |
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

None of these rows changes group in §1. A row moves only once its question is answered.

| Row | Status | Question | Recommendation |
|---|---|---|---|
| A-03 | deferred | Request per-row abilities on the quotation payload (SEC-09)? | Yes, as its own fix point; F-42 fixes only the wrong "create" message on the edit route |
| A-15 | deferred | A server total preview, or accept "save, then see"? | A server preview as its own fix point (§7.2 asks for the total; D-67 forbids the SPA computing it); until then a line under Save |
| A-18 | deferred | Request `deal_code`, `customer_name` and the timeline actor's name? | Yes, as one fix point shaped like F-41 · 1.6b's `owner_name`; F-42 adds the gap and "—" |
| A-25 | deferred | Interim landing per role: the first built screen in §8's list, or keep Ping until Module 14? | The first built screen, as its own point outside F-42 (it is a route change); `RoleLandingTest` reads §8 |
| A-26 | deferred | Apply D-99's display form (2 dp money, trimmed quantities, grouping) on screen? | Yes: display only, so the screen and the PDF show the same figure |
| A-45 | deferred | Request the impersonated role's display name, and fill `roles.name_ar`? | Yes to both: while impersonating, the target user cannot read the roles list, and `name_ar` is empty for every role |
| A-54 | deferred | A customer's deals on its page: build now or defer? | Defer until F-41 closes (F-41 · 1.7 adds a filter to the same list); the `tel:`/`mailto:` links are built now |
| A-55 | deferred | One shared status chip: without the dot, or with it? | Without (the owner's newer ruling for deals; one line on the quotation chip) |
| A-62 | deferred | Margin accepts a minus sign (`D-106`; `SaveQuotationRequest.php:90` `^-?`): keep `type="text"`? | Keep it and add a comment saying why; the row would then become an obvious fix |

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
- **A-04 Recommendation** — **Option A.** It meets DS §6.6 where the dialogs already sit. It needs no Teleport and no trap code, so it is the smallest change that satisfies the rule.
  - The stacking is the browser's top layer, so the dialog can't be covered by the sticky bar.
  - The backdrop needs a colour: the `--color-overlay` token is still owed (DS §10, row A-42 at 3.8). The mock-up uses `rgb(0 0 0 / 0.5)` as a stand-in.

### A-02, the customer dialog after its first save, when similar customers exist

- **A-02 Option A** — the dialog stays open. It is retitled "Edit customer «…»", and the similar-customer warning is inserted at its top with focus on it. — walk: pass
  - The log reads `#a02a → #cName → Save → #a02warn`. Escape then returns focus to `#a02a`.
- **A-02 Option B** — the dialog closes, and the warning appears above the list with focus on it. — walk: pass
  - The log reads `#a02b → #dName → Save → #a02list-warning`.
- **A-02 Recommendation** — **Option A.** The defect is a second Save creating a duplicate customer. Edit mode makes a second Save an *update* of the saved record, so the duplicate can't happen. It also keeps the warning beside the record that the user may need to correct.
  - Option B also prevents the duplicate. But it moves the user away from the form just as they are told something about it.

### A-09, Approve

- **A-09 Option A** — a confirm dialog that names the record: "Approve QT-2026-0011?", plus one line on the consequence. — walk: pass
  - The log reads `#a09a → Approve → Cancel → Approve → Cancel → #a09a`, and Escape cancels.
- **A-09 Option B** — the approval happens at once, with an "Undo" bar for 8 seconds and focus on Undo. — walk: fail
  - Undo works: focus moves `#a09b → #undoBtn → #a09b` and the row comes back. But when the bar expires untouched, focus ends on `<body>`.
  - Beyond the focus issue, a real undo needs either a server "un-approve" or holding the request for 8 seconds in the browser. That would make the SPA decide when a state transition happens. **D-67** forbids that ("the SPA never owns a business rule … may preview, never decide", `CRM_Documentation_EN.md:197`; CLAUDE.md § Laravel Conventions: "never owns a calculation, a permission decision, or a state transition"). The audit trail would also record an approval that was then withdrawn.
- **A-09 Recommendation** — **Option A.** DS §6.6 asks approval to "show its consequence" before it happens. Undo is ruled out by D-67, not only by the focus failure.

### A-35, removing in the builder

- **A-35 Option A** — confirm first: "Remove line 1?", with the typed quantity and margin named. — walk: pass
  - The log reads `#a35a → Remove → Cancel → Remove → #a35a`, and Escape cancels.
- **A-35 Option B** — the line is removed at once, with an "Undo" bar. — walk: fail
  - When the bar expires untouched, focus ends on `<body>` (`#a35b → #undoBtn`, then `<body>`).
  - This one is fixable: on expiry, move focus to the next line. And here an undo is honest, because the builder's lines are unsaved local state. No request and no transition are involved.
- **A-35 Recommendation** — **Option B, with the expiry fix**: on expiry, focus moves to the next line or the Add button (A-17's rule).
  - Removing lines is frequent, cheap to reverse and local, so a confirm on every remove slows the seller for no protection.
  - Changing a block's supplier offer wipes its typed values (A-35's other half). That one keeps a confirm, because there is no single row to restore.

### A-22, inline confirmation forms (Return, Delete-confirm, record PO, status change)

- **A-22 Option A** — stay inline. — walk: pass
  - The form is named (`aria-labelledby`) and the trigger carries `aria-expanded`/`aria-controls`. On open, focus moves to the first field; Escape or Cancel returns it to the trigger.
  - The log reads `#a22a → #a22note → #a22a`. The form isn't modal, so Tab after Cancel continues down the page, as it should.
- **A-22 Option B** — the forms become dialogs with A-04's behaviour. — walk: pass
  - The log reads `#a22b → textarea → Send → Cancel → textarea → Send → #a22b`, and Escape returns focus.
- **A-22 Recommendation** — **Option B for the Return note and Delete-confirm, Option A for the rest.**
  - The Return note is drawn above the table, off-screen from row 12 (SH-31). Delete is destructive, so its confirmation needs the dialog's announcement (Q-07). Both are exactly what a dialog is for.
  - The status change in a deal row, and recording a PO, are part of the row or page the user is already reading. Moving them into a dialog adds a layer for no gain, so they stay inline with Option A's focus handling.
