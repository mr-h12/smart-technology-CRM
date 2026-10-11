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
