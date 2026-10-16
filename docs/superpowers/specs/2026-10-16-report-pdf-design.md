# Module 13 · Slice 4 — the stored PDF of a submitted report

*Design spec, 2026-10-16. Status: **approved by the owner, 2026-10-16, with §8 (a); renumbered to slice 4 the same day**, when the owner put follow-ups and notes first (`2026-10-16-report-follow-ups-notes-design.md`). Divided by the owner on 2026-10-16 into two points (4.1 decision + red, 4.2 green + evidence); `D-132` written at 4.1. Two answers the same day: a 403 test for a caller without `report.export`, and the follow-up's first-save time in the PDF. The owner approved the design in chat,
section by section, on 2026-10-16; this file is its written form. Nothing here is built until the owner
approves this spec and slice 4's point list is written into `CHECKLIST.md`.*

## 1. Goal

Close acceptance criterion 1 of Module 13 (`CHECKLIST.md`): *"Submitted report → fixed snapshot +
stored PDF, unchanged even if the data changes."* The frozen copy has existed since slice 1. This slice
adds its stored PDF and the way to download it.

## 2. Decisions this slice rests on

| Source | What it says | Line |
|---|---|---|
| §11.1 | "A report is a fixed snapshot — its figures at submission time, with a stored PDF." | `docs/CRM_Documentation_EN.md:891` |
| `D-128` | The only export is the PDF: the stored PDF of the frozen copy for a submitted report, and a fresh PDF for a draft. Who may export is §3.10's row, read as "export PDF". | `:193` |
| `D-126` | The frozen copy and its PDF carry the same content. | `:191` |
| `D-130` | The report shows the records grouped by kind, with counts, never the audit log. Money is the selling total only. | `:195` |
| §11.4 | Returned → Draft (v2); "the previous version remains available". | `:912–920` |
| §3.10 | `export PDF / Excel`: Manager ✅ · TL ✅ · Out.Sup ✅ · Sales Own · Procurement Own · CEO ✅. Seeded as `report.export`. | `:345–354`; `PermissionMatrix.php:470–478` |
| `deptrac.modules.yaml` | Pdf "was named for the capability rather than for the quotation because §11 stores a report PDF too". Only Pdf may drive Browsershot. | `:150–154`, `:837` |

**Decision `D-132`** (written at point 4.1, the owner's yes, 2026-10-16):
- Each submitted version of a report keeps its own stored PDF.
- It is made once, at submit, in **Arabic only**.
- v1's PDF is never replaced by v2's.
- The English copy and the draft's fresh PDF (`D-128`) belong to a later slice.

## 3. Scope

**In this slice:**
- one stored Arabic PDF per submitted version;
- its generation status;
- its download;
- the button on the report page, for the author and for a recipient.

**Out of this slice** (each one is a later slice or a debt row):
- the draft's fresh PDF and an English PDF;
- a manual retry after a failure;
- an audit entry on download;
- a shared polling helper;
- the other five report types, comments, and the jobs J-04…J-08.

## 4. Design

### 4.1 Reports module (owns the report)

- **Event.** `SubmitReport` dispatches a domain event `ReportSubmitted(reportId, version)` **after the
  transaction commits**. A refused or rolled-back submit dispatches nothing. Today `SubmitReport` only
  records the audit entry `REPORT_SUBMITTED` (`SubmitReport.php:50`).
- **Read contract** (in Reports `Domain/Contracts`), for the Pdf module. It has two methods:
  - `version(reportId, version)` returns the submitted version's code, author name, period, submit time,
    notes and frozen `activity` (`{counts, sections}`, `DailyActivity.php:98`). It returns `null` for a
    draft or for a version that does not exist.
  - `mayOpen(reportId, actorId)` returns true when the actor is the author or a live recipient. It
    delegates to `ReportAccess::reach` and `reachReceived` (`ReportAccess.php:24–48`), so the rule is not
    copied.
- **Deptrac.** The contract is published as a `ReportsContract` layer. Pdf gains it as one named
  exception, with its reason.

### 4.2 Pdf module (owns rendering)

- **Table `report_pdf_generations`.**
  - **Columns:**
    - uuid `id`;
    - `report_id` → `reports`;
    - `version`;
    - `status`: `queued`, `completed` or `failed`, using the existing `PdfGenerationStatus`;
    - `file_id` → `files`, nullable;
    - `attempts`, `failure_reason`, `finished_at`;
    - `created_by`, `created_at`, `updated_by`, `updated_at`, `deleted_at`.
  - **Keys:** unique on `(report_id, version)`; an index on `file_id`.
  - **CHECK rules,** the same three as `pdf_generations`
    (`2026_09_27_100000_create_pdf_generations.php:43–80`):
    - a `completed` row has a file;
    - a `failed` row has a reason;
    - `finished_at` is set exactly when the status is final.
  - `pdf_generations` itself is not touched.
- **Listener `QueuePdfWhenReportSubmitted`.** It runs **synchronously** after the commit, not on a
  queue. It inserts the queued row and dispatches `RenderReportPdfJob` on the `pdf` queue, so a submitted
  version has its row as soon as submit returns. The unique key makes a repeat a no-op. It has the shape
  of `QueuePdfWhenQuotationSent.php:39`.
- **`RenderReportPdf`**, with the shape of `RenderQuotationPdf.php`:
  1. Return unless the row is still `queued`. On a retry, reuse an already attached `file_id`.
  2. Read the version through the read contract.
  3. Render the Blade template `pdf.report` in Arabic, with the fonts and logo from
     `PdfAssetsInterface`.
  4. Render it through `PdfRendererInterface` (Browsershot, A4, the existing margins).
  5. Store it with `StorageServiceInterface::storeContents(AttachmentParent::Report, reportId, Pdf, …)`.
     Then create the `files` row, attach it in `report_files`, and scan it (`ScanStoredFile`). A file
     that is not clean fails the row with no retry.
  6. Mark the row `completed`.

  After the worker's 3 tries, `failed()` records the reason.
- **The version link** is the generation row's `file_id`. `report_files` stays the generic pivot, with no
  new column.
- **Template content** is the same as the report page's frozen copy (`D-126`, `D-130`):
  - the code and period, the author, and the submit time;
  - the six record groups with their counts;
  - the selling totals with their currency;
  - the follow-ups (customer, note, and the first-save time of `D-133`, shown in the company timezone (`locale.timezone`), as frozen) and the notes list (`D-131`).

  It **never shows cost, margin or profit.** It is RTL, with embedded fonts (`P-01`).

### 4.3 Access, API and download

- **The rule.** A caller reaches a version's PDF only when both of these hold:
  - they have `report.export`;
  - `mayOpen(reportId, caller)` is true.

  Anyone else gets **404**, the same as opening the report. A caller without `report.export` gets **403** from the route middleware.
  - Sales and Procurement reach their own reports (`Own`). The Manager and the CEO reach the reports sent
    to them.
  - **Known ceiling:** the Team Leader's `Team` and the Outdoor Supervisor's `Out` narrow nothing until
    teams exist (`D-129`), as in slice 2.
  - The CEO may download: criterion 5 allows *export*.
- **`GET /api/v1/reports/{report}/versions/{version}/pdf`**, served by a Pdf-module controller under
  `permission:report.export`, returns `{status, file_id, failure_reason, finished_at}`.
  - It returns 404 for a draft, for a version that does not exist, for a version with no generation row,
    and for a caller outside the rule.
  - It returns 401 when the caller is not signed in.
- **Download** stays `GET /api/v1/files/{file}/download`. A new `ReportPdfAttachmentPermission` joins the
  composite (`AppServiceProvider.php:513–521`) and applies the same rule. It replaces the comment at
  `:510` ("`Report` has no entry and is refused").

### 4.4 The button (SPA)

- The button appears on `ReportDetailView.vue` for a **submitted** version only, on the author's page and
  on a recipient's page. It never appears on a draft.
- **Queued:** «جارٍ تجهيز ملف PDF…» / "Preparing the PDF…", disabled. It is read again on a timer, the
  way `QuotationPdfPanel.vue:98–103` does, and the timer is cleared on unmount.
- **Completed:** «تنزيل PDF» / "Download PDF", through the files route.
- **Failed:** a plain error message.
- The label names its version.
- Every string lives in `ar.json` and `en.json`, with loading, error and refused states, in RTL and LTR.

## 5. Migration

One new migration, `create_report_pdf_generations`, with a `down()` that drops only that table.
`DEV-03` is proved inside `RefreshDatabase`'s transaction with `migrate:rollback --path=<this file>`,
**never on the dev database**.

## 6. Tests, written red first

| File | Proves |
|---|---|
| `tests/Feature/Reports/ReportPdfMigrationTest.php` | the columns and foreign keys; the unique key; each CHECK rule refusing a bad row; the rollback by path |
| `tests/Feature/Reports/ReportPdfGenerationTest.php` | **Queueing:** submit queues exactly one row; a refused submit queues nothing; Return → resubmit queues v2 and leaves v1's row and file unchanged. **Rendering:** with the fake renderer, the job stores the file under the report, links `report_files`, and completes; a second run stores no second file; a render failure ends `failed` with a reason. **Content:** the follow-up's first-save time; the rendered HTML holds the frozen codes, counts, notes and selling totals, and no cost, margin or profit; a deal changed after submit does not change the PDF. |
| `tests/Feature/Reports/ReportPdfEndpointTest.php` | **Status:** the author and a recipient read it; anyone else gets 404; a caller who is not signed in gets 401; a caller whose role has no grants (the `auditor_without_grants` pattern of `CustomerListEndpointTest.php:229`) gets 403; a draft or a missing version gets 404. **Download:** through `/files` it works for the author and a recipient, and anyone else gets 404; a Team Leader who is not a recipient gets 404. |
| `resources/js/pages/reports/__tests__/ReportDetailView.spec.ts` | no button on a draft; queued is disabled and read again on a timer that stops on unmount; completed links the file; failed shows the error; ar and en |

**A caller without `report.export`** is tested with a role that has no grants (the owner's answer, 2026-10-16), although every seeded role holds the permission.

**Not a unit test:** a real Arabic PDF. That belongs to point 4.2: one real render through `worker-pdf`
(restarted first), read back with `pdftotext`.

## 7. Points (divided by the owner, 2026-10-16)

- **4.1** Decision and red: `D-132` written (a shell write that keeps the inode, after the owner's yes), the
  failing tests of §6, failing for the right reason.
- **4.2** Green and evidence: everything in §4 (Reports: the event and the read contract; Pdf: the table,
  the listener, the job, the template, the status endpoint and the attachment permission; the deptrac
  grant; the button), then the full suite alone, the gates, `permission-matrix-auditor`,
  `waste-auditor`, the browser check in ar/en × 1280/375, and one real Arabic PDF through `worker-pdf`
  (restarted first), read back with `pdftotext`.

## 8. Versions submitted before this slice — decided (a), the owner, 2026-10-16

**Versions submitted before this slice.** On dev, reports submitted in slices 1–2 (for example
RPT-DY-2026-0003) will never get a generation row, so their status endpoint returns 404 and the page
shows no button. Choose one:
- **(a)** leave them without a PDF: the pilot has not started, and they are dev data only;
- **(b)** add a one-off artisan command, run by hand, that queues a row for every submitted version that
  has none (the render path is the same).

**Decided: (a).** Earlier versions get no PDF and no command is built. The migration never renders.

## 9. Risks

- **A failed render stays failed** until a retry is built. The worker's 3 tries are the only recovery.
- **Arabic shaping on Linux** is the `P-01` risk. The real render through `worker-pdf` at 4.3 is its
  check.
- **`worker-pdf` keeps old PHP classes,** so it is restarted before the real render.
