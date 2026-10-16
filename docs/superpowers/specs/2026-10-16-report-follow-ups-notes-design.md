# Module 13 · Slice 3 — follow-ups and several notes on a daily report

*Design spec, 2026-10-16. Status: **approved by the owner, 2026-10-16**. The owner set its direction in chat on
2026-10-16 (the request, then four answers: the row's fields, autosave, the page order, and "present the
data design and update the spec"). Nothing here is built until the owner approves this spec and the
slice's point list is written into `CHECKLIST.md`. It comes **before** the report PDF, which moves to
slice 4 (`2026-10-16-report-pdf-design.md`).*

## 1. Goal

The owner's request, 2026-10-16:
- add a section «التواصل والمتابعة» (follow-ups): rows like a quotation's lines; in each row the employee
  picks one of their customers and writes a note about them; as many rows as needed;
- notes become several separate notes instead of one box;
- the page is laid out in order, not at random.

## 2. Decisions

**Proposed `D-131`** (needs the owner's approval; written at point 3.0). It **amends `D-126` and
`D-130`'s content of a daily report**, and **adds an exception to Design System §2's "explicit
Save/Cancel behavior"**, on the pattern of `D-124`:

1. **Follow-ups.** A draft daily report holds any number of follow-up rows. Each row has a **customer**
   (required, one the author can see under `customer.view`, at that permission's row scope) and a
   **note** (required, ≤ 5000 characters). There is no channel, date or time field (the owner, 2026-10-16).
2. **Notes.** A draft daily report holds any number of notes, each with a body (required, ≤ 5000
   characters). The one `notes` box is replaced. Each existing draft's non-blank `notes` text becomes its
   first note; the old column is kept, unread (the `D-122` precedent).
3. **Autosave.** Follow-ups and notes save themselves: 2 seconds after typing stops, and at once when the
   box is left (the `D-124` pattern). There is no Save button for them. A new row or note is added with
   «+ إضافة» only (no empty row waits on its own), and its first save creates it; leaving the page saves
   what is still waiting (the owner, 2026-10-16). A row or note whose required text is cleared is not
   saved: the box says it is required, and the stored text stays. Removing is an explicit «حذف» with its
   confirm.
4. **Frozen at submit.** Both are part of the frozen copy at submit, read-only afterwards, and visible to
   a recipient. A version submitted before this slice keeps its frozen `notes` text, shown as one note.
5. **Audit.** `REPORT_FOLLOW_UP_ADDED`, `_UPDATED`, `_REMOVED`, and `REPORT_NOTE_ADDED`, `_UPDATED`,
   `_REMOVED`, with old and new values.

The report PDF's decision becomes **`D-132`** (was `D-131` in the PDF spec).

## 3. Data

Both tables live in the Reports module.

**`report_follow_ups`**
- uuid `id`; `report_id` → `reports`; `customer_id` → `customers`; `note` text, not null;
- `created_by`, `created_at`, `updated_by`, `updated_at`, `deleted_at` (`DB-01`);
- an index on `(report_id, created_at)`, the order the list is shown in;
- a CHECK that `note` is not blank.

**`report_notes`**
- uuid `id`; `report_id` → `reports`; `body` text, not null;
- the same audit columns, soft delete, index and not-blank CHECK.

**Migration** `add_follow_ups_and_notes_to_reports`: creates both tables, then copies each **draft or
returned** report's non-blank `reports.notes` into `report_notes` as its first note. `reports.notes` is not
dropped. `down()` drops both tables; the original text is still in `reports.notes`, so nothing is lost.
`DEV-03` is proved inside the test's transaction with `--path`, **never on the dev database**.

**Frozen copy** (`report_versions.snapshot`), written by `SubmitReport`:
- `follow_ups`: `[{customer_id, customer_name, note}]`, the customer's name as it was at submit;
- `notes`: `[{body}]`, in place of today's single `notes` string;
- a reader of an older snapshot treats a `notes` string as one note.

## 4. API

All under the existing report gate: `permission:report.create`, author only (`ReportAccess::reach`;
anyone else gets 404), and only while the report is editable (draft or returned; otherwise **409**
`report_not_editable`).

| Method and path | Body | Answer |
|---|---|---|
| `POST /api/v1/reports/{report}/follow-ups` | `{customer_id, note}` | 201 with the row |
| `PATCH /api/v1/reports/{report}/follow-ups/{row}` | `{customer_id?, note?}` | 200 with the row |
| `DELETE /api/v1/reports/{report}/follow-ups/{row}` | — | 204 |
| `POST /api/v1/reports/{report}/notes` | `{body}` | 201 |
| `PATCH /api/v1/reports/{report}/notes/{note}` | `{body}` | 200 |
| `DELETE /api/v1/reports/{report}/notes/{note}` | — | 204 |

- **Validation** at the boundary (Form Requests): text required and ≤ 5000; `customer_id` a uuid of a
  customer the author can see. A customer outside the author's `customer.view` scope gets **422**, not a
  hint that it exists. The check goes through the Customers contract
  (`CustomerDirectoryInterface::find(customerId, scope)`, already granted to Reports by deptrac).
  *To confirm at 3.1:* how Reports builds the author's `CustomerRowScope`; if no path exists, that is a
  question for the owner before code.
- **Concurrency.** A row write takes a lock on its report row and checks that the report is still
  editable inside the same transaction, so a write racing a submit ends in 409, never in a row that
  misses the frozen copy. Row writes carry no `If-Match`, as customer notes carry none (`D-124`); the last
  write to a row wins. Submit keeps its `If-Match`.
- **The report payload** (`GET /reports/{id}` and the received view) gains `follow_ups` and `notes`
  lists. For a submitted version they come from the frozen copy; for a draft, from the tables.
- **The customer picker** uses the existing customers list endpoint, which is already scoped by
  `customer.view`, through `SearchCombobox`.
- The old `notes` field in `PATCH /reports/{id}` is refused with 422, as `D-122` did for customers.

## 5. The page

Order approved by the owner, 2026-10-16. Three groups divided by a rule; each heading carries a small
word saying who fills it:

1. ① **ملخص اليوم** — the count tiles (unchanged).
2. ② **نشاط اليوم · تلقائي** — the six record groups (unchanged, read-only).
3. ③ **التواصل والمتابعة · تكتبه أنت** — rows of customer (`SearchCombobox`) and note, «حذف» on each,
   «+ إضافة متابعة», and a «تم الحفظ ✓» / «تعذّر الحفظ» status line.
4. ④ **الملاحظات · تكتبها أنت** — one box per note, «حذف» on each, «+ إضافة ملاحظة», the same status
   line.
5. ⑤ **المستلمون** — the recipient checkboxes (unchanged).
6. ⑥ **إرسال التقرير** — always last, on a phone too.

- From 1024 px: ①–④ in the main column (③ and ④ need the width); ⑤⑥ in the side column, as today.
- Below 640 px: one column, ①→⑥; a follow-up row stacks the customer above the note.
- After submit, «إضافة» and «حذف» disappear and ③④ read from the frozen copy; a recipient sees the same.
- Every field has a visible label, not a placeholder only (`ui-ux-pro-max`: Form Labels); groups are made
  by spacing and headings, not colour alone (`field-grouping`, `visual-hierarchy`, `whitespace-balance`).
- The autosave timer is the one in `CustomerNotesSection.vue:33,67,100`. It moves into one shared helper,
  `resources/js/components/useAutosave.ts`, used by both pages, rather than a second copy. On unmount it
  saves what is still waiting, as the customer page does today (the owner, 2026-10-16).
- Editing a non-editable report answers `409 report_not_editable` for the new rows; the older `422
  report_not_draft` on `PATCH /reports/{id}` stays, recorded as debt (the owner, 2026-10-16).
- The existing tests that send the old `notes` field or read notes as one string are rewritten in 3.2.
- Every string in `ar.json` and `en.json`; loading, empty, error and refused states; RTL and LTR.

## 6. Tests, written red first

| File | Proves |
|---|---|
| `tests/Feature/Reports/ReportFollowUpsMigrationTest.php` | both tables, their keys, the not-blank CHECKs; a draft's `notes` copied as its first note, a submitted report's not; rollback by path |
| `tests/Feature/Reports/ReportFollowUpsEndpointTest.php` | add, edit and remove a follow-up and a note; each audited with old/new values; 422 for blank text, text over 5000, or a customer outside the author's scope; 404 for another user's report; 409 on a submitted or acknowledged report; 401 unauthenticated; the old `notes` field refused |
| `tests/Feature/Reports/ReportSubmitEndpointTest.php` (added cases) | submit freezes follow-ups with the customer's name then and the notes list; a customer renamed after submit does not change the frozen copy; a recipient reads both; an old snapshot's `notes` string reads as one note; Return → v2 copies the rows and notes into the editable draft |
| `resources/js/pages/reports/__tests__/ReportDetailView.spec.ts` (added cases) | the section order ①–⑥; add a row, pick a customer, type, the save after 2 s and on blur; an empty row not saved and marked required; «حذف» with its confirm; read-only after submit; ar and en |
| the shared autosave helper's spec | the 2-second pause, the save on blur, the timer cleared on unmount; the customer page's existing specs still pass |

## 7. Points (two, the owner's division, 2026-10-16)

- **3.1** Decision and red: `D-131` written (a shell write that keeps the inode, after the owner's yes);
  both specs committed; 2.3's tick and criteria 2 and 3; then the test files of §6, failing for the right
  reason. It stops at red.
- **3.2** Green and evidence: migration, tables, endpoints, the frozen copy, the shared autosave helper,
  sections ③④ and the page order; then the full suite alone, the gates, `permission-matrix-auditor`,
  `waste-auditor`, and the browser check ar/en × 1280/375 (add, autosave, remove, submit, read-only).

## 8. Out of scope

A channel, date or time on a follow-up; editing after submit; comments; the PDF (slice 4); the other
five report types.

## 9. Risks

- **Last write wins** on a row edited in two tabs at once: the price of autosave without `If-Match`, the
  same as customer notes.
- **Extracting the autosave helper** touches the customer page; its existing specs are the guard.
