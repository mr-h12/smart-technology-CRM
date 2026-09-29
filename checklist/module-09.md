> Frozen history of Module 9, cut verbatim from `CHECKLIST.md` on 2026-09-29 (commit `18a071d`).
> Open boxes here are stale copies — the live ones are in `CHECKLIST.md`. Do not tick here.

## Module 9 — PDF Generation

> As a sales employee, I want to produce a professional PDF quotation, so that I can send it to the
> customer.

**Acceptance criteria**
- [x] Quotation with 3 suppliers → **no supplier name or price anywhere in the PDF** *(1.1's view has no
      such field; 1.2 `test_that_a_three_supplier_quotation_leaves_no_supplier_or_cost_value_in_the_view`;
      2.4 `test_that_no_supplier_or_cost_value_can_reach_the_page`)*
- [x] Generation is async on the `pdf` queue + stored against the quotation + fixed snapshot *(3.3's
      `pdf_generations`, 3.4's job on `pdf` storing through 1.3's `quotation_files`, 3.5's `202` with the view
      mapped at the press (Q10), 3.6 on a send)*
- [~] Generation failure → automatic retry + notification to the employee *(the retry is the worker's
      `--tries=3` over 3.4's idempotent attempts, the failure recorded on the row and shown by 5.1; the
      notification is the debt row naming §18.2 — Q3)*
- [x] `show_delivery_terms = false` → that section is omitted *(1.2
      `test_that_delivery_terms_follow_the_flag_not_the_text`; 2.4's template draws no absent section)*
- [x] CEO can **download** the existing PDF but never generate a new one *(4.1
      `test_that_the_ceo_downloads_but_holds_no_grant_to_generate`; 3.5 refuses the CEO's POST; 5.1 draws
      no Generate for them)*
- [~] Arabic renders correctly with embedded fonts *(proven by P-01)* *(2.2's faces, 2.6's real render in
      CI — `test_that_the_embedded_faces_are_what_the_pdf_carries`; `[~]` until the owner confirms Q8's prose)*
- [x] Rendering consumes a customer-view model that **structurally cannot** contain supplier,
      cost, or margin fields *(1.1 `test_that_no_customer_view_field_names_a_cost_margin_or_supplier` and
      `test_that_no_customer_view_field_is_one_of_module_7s_cost_fields`)*
- [~] Page numbering is dynamic — item count varies per quotation *(2.5's live `footerTemplate`,
      `test_that_a_long_quotation_runs_onto_more_pages_and_a_short_one_does_not`; `[~]` until an eye has read
      them, 2.7)*

### Ordering — Module 9 starts before Module 8, and that is a deliberate owner decision

`CLAUDE.md` and `docs/MVP_Build_Plan_EN.md` sequence the modules `… 7 Quotations → 8 Approvals →
9 PDF …`, and the ⚠️ above the ownership table says the 4-and-5 parallel "does not license further
reordering." This is the second reordering, so it is recorded rather than assumed: Module 8 moved to
Yousef on 2026-09-13 and the second developer took Module 9. **Why it holds technically:** a PDF is
rendered from a quotation's figures, not from an approval — nothing in §14.6, §17 or the acceptance
criteria below reads `status`, and every field the renderer needs already exists on `main` through
Module 7's `QuotationDetail`. **What it does not license:** generation is still gated on `§3.5`'s
permission rows, and if the owner later rules that only an Approved quotation may be rendered, that
is a status check added at the endpoint — not a re-plan of this list. Owed a `D-xx` if the owner
disagrees with the reading.

### Step 1 — the renderer's boundary *(point list published and **approved** 2026-09-13)*

Step 1 builds the module, the model the template is allowed to see, and the place the output is
stored. It deliberately contains **no renderer and no endpoint** — those are Steps 2 and 3 — because
the one acceptance criterion with real design content in it is the customer-view model, and getting
that wrong is the defect §3.12 rule 2 exists to prevent.

**Why this is the shape of Step 1.** `QuotationDetail` — Module 7's read model, merged and the only
legitimate way into a quotation — carries `defaultMargin`, and each `QuotationLine` carries
`unitCost`, `unitCostCurrency`, `unitCostFxRateAtTime`, `unitCostBase`, `marginPercent`, `lineCost`
and `supplierQuotationItemId`. Yousef even enumerated them as `QuotationLine::COST_FIELDS`. So the
read model that Module 9 must consume holds **every category of field the customer PDF may never
show**. Handing it to a template and trusting the template not to print them is exactly the
arrangement the acceptance criterion refuses: *"a customer-view model that **structurally cannot**
contain supplier, cost, or margin fields."*

**Owner decisions this list needs — each names its default, and the default is what ships if the
owner says only "approved".** ✅ **Approved 2026-09-13 with "approved" alone, so every default
below is now the decision.** `Q2` and `Q3` each leave something deliberately unbuilt — read them
as the reasons two boxes will not close in this module, not as oversights.

- **Q1 · the module's name.** Module 9 has no directory; the sixteen under `crm/app/Modules/` are
  domain nouns. §11 also stores a report PDF and `D-23` wants a manual accounts export, so a
  quotation-only name will be wrong within two modules. **Default: `Pdf`**, with `AttachmentParent`
  naming the parent, so Reports can reuse it without a rename.
- **Q2 · Procurement's `Asgn` on both PDF rows.** §3.5 grants Procurement `Asgn` for *generate* and
  for *export/download*. `SEC-08` gives `asgn` no mechanism, and `CustomerRowScope` and
  `DealRowScope` both **fail closed** on it because no column says which procurement employee a
  deal is assigned to. **Default: fail closed — Procurement gets `403` on both, with the refusal
  written as a named case and a test, never as a silent gap.** This is the **third** module to hit
  the same missing field; it is owed a `D-xx`, and Module 11 (Procurement) cannot fail closed on it
  forever.
- **Q3 · the notification on failure.** The criterion is "automatic retry + notification to the
  employee". `crm/app/Modules/Notifications/` is four `.gitkeep` files, and §18 belongs to no
  module. **Default: Module 9 builds the retry and the failure record; the notification is entered
  on the debt register naming §18.2 and this criterion, and the box stays `[~]`** — not `[x]` with
  half a criterion, and not a private notification table invented inside `Pdf`.
- **Q4 · what a second generation does.** §14.6 requires an "immutable snapshot", while §3.5 grants
  *generate PDF* as a repeatable action. **Default: every generation inserts a new `files` row and a
  new pivot row; nothing is overwritten or deleted (`DB-01`), and download serves the most recent.**
  The snapshot is immutable; the set of snapshots grows.
- **Q5 · `scan_status` for a file the system produced.** `files.scan_status` defaults to `pending`
  and §17 requires true-MIME validation because uploads are hostile. A PDF this module rendered is
  not an upload. **Default: inserted as `clean` with the reason in a comment** — the alternative
  leaves every generated PDF sitting in the quarantine view that `files_scan_status_pending_index`
  exists to serve.
- **Q6 · sending to the customer stays out of this module.** §3.5 lists *send to customer* as its
  own row, §6.4 draws `Approved ──send──► Sent`, and Module 7's Step 4 explicitly excludes send.
  The unmerged Module 8 draft (PR #94) asserted "`sent_at` is Module 9's". **Default: it is
  not.** Module 9 generates and stores; `status` and `sent_at` are columns on `quotations`, so
  whoever owns that table writes them. Module 9 publishes the contract they call and writes nothing into
  `quotations` — the per-module rule, and the reason this module needs no change from Yousef.

- [x] **1.0** Create the `Pdf` module (Q1) — the four layer directories and a
      `crm/deptrac.modules.yaml` entry appended inside our own block. It may depend on
      `QuotationsContract`-shaped reads and `StorageContract`, and nothing may depend on it.
      **No `Contract`/`Driver` split and no Eloquent model**, because `D-77` only forces the split
      on a module whose Infrastructure holds models, and this one holds none: the `files` row and
      the pivot are written through Storage's `FileWriterInterface`, the way
      `AttachDealDocument` (our Point 4.1) and `AttachSupplierQuotationDocument` already do.
      *Verified by* both `deptrac` configs at `Violations 0 · Uncovered 0`, and a deliberate
      temporary `use` of an Eloquent model proving the ruleset actually refuses it.

      *(2026-09-13, #116 — empty ruleset, proven by a probe deptrac refused by name.)*

- [x] **1.1** `CustomerQuotationView` in `Pdf/Domain/View/` — the model the template may see, plus
      `CustomerQuotationLine` and `CustomerAdditionalLine`. Carries `code`, dates, customer and
      company identity, currency, per-line description / quantity / **unit price** / line total,
      the additional items, the money chain the customer is entitled to (`subtotal`,
      `discountAmount`, `taxBase`, `taxAmount`, `netAmount`, `finalTotal`, `roundingDiff`),
      `paymentTerms`, `warranty`, and `deliveryTerms` **only when `showDeliveryTerms` is true** —
      the field is absent, not blank, so `show_delivery_terms = false` cannot be defeated by a
      template that prints an empty section. No `unitCost*`, no `marginPercent`, no `lineCost`, no
      `supplierQuotationItemId`, no `defaultMargin`, no supplier anything. *Verified by* a test
      that reflects over all three constructors and asserts no property name matches
      `QuotationLine::COST_FIELDS`, `margin`, `cost` or `supplier` — so a future field added by
      someone in a hurry fails the suite rather than the customer's inbox — and a second test
      asserting the class is `final readonly` with no setter and no `__set`.

      *(2026-09-13, #118 — three guards, each proven by a probe that broke it.)*

- [x] **1.2** `CustomerQuotationViewMapper` in `Pdf/Application/` — `QuotationDetail` →
      `CustomerQuotationView`, the only place the two vocabularies meet, reading through
      `QuotationReaderInterface` (F-14) and never through an Eloquent model of Yousef's. Company
      identity (name, logo, address, phones) comes from Settings, not hard-coded — `§13` screen 4
      and `§14.6` both require it. *Verified by* a three-supplier quotation whose
      `QuotationDetail` holds three distinct `supplierQuotationItemId` values and three different
      `unitCost`s, mapped, then serialised to JSON and asserted to contain **none** of those twelve
      values anywhere in the string — the acceptance criterion "no supplier name or price anywhere
      in the PDF" tested at the model rather than by reading a rendered page.

      *(2026-09-22, #201 — sixteen supplier/cost values absent from the JSON; read through F-14's
      `QuotationReaderInterface`.)* **Open, not hidden:** `LineDescriptionsInterface` has no binding
      yet — owed before Step 3's endpoint, and now buildable inside `Pdf` from `SupplierItemPrice`'s
      `catalog_item_id` + `CatalogItemLabelsInterface` (#200); `customerContact` is `null` because
      `QuotationDetail` carries no contact and which one the PDF addresses is undecided.

- [x] **1.3** `quotation_files` + `AttachmentParent::Quotation` — one migration creating the pivot
      on the exact shape of `deal_files` (composite primary key, `file_id` index, `file_id`
      cascade). **It creates a new table and alters none**; `quotations` is not touched, which is
      what keeps this module inside its own boundary — the arrangement Module 6 used for its
      nullable `deal_id`. ⚠️ **Module 0 shipped four pivots and `quotation_files` is not among
      them** — `deal_files`, `supplier_quotation_files`, `purchase_order_files`, `report_files` —
      so the one module whose stored PDF is its headline feature is the one with nowhere to put it.
      Recorded as a finding, not worked around. Unlike those four, this pivot **carries its parent
      foreign key in the same migration**, because `quotations` already exists and their parents
      did not: `deals` set that precedent and `supplier_quotations` followed it, closing *"the debt
      Module 0 recorded"* in its own migration. Nothing is owed afterwards.
      **Three Module 0 touchpoints, named so they are not a surprise** — `AttachmentParent` gains a
      `Quotation` case, and `FilesMigrationTest` carries its own `PIVOTS` constant plus a
      still-owed-foreign-keys list that must both be appended to, inside our own block, the way
      `AuditEnforcementTest`'s register already is. Extending Module 0 is allowed and has precedent
      — our Point 4.1 added `FileWriterInterface` — but it is a shared file and this is where it
      will conflict if Yousef is in it the same day.
      *Verified by* `up` and `down` both running clean, `FilesMigrationTest` passing with the owed
      list one entry shorter, the attach path writing a row through `FileWriterInterface`, a
      duplicate attach refused by the primary key rather than by a pre-check, and
      `AttachmentParent::Quotation` resolving in Storage's permission path (`D-38`).

      *(2026-09-22, #203 — fifth pivot with its parent key from day one; the permission composite
      refuses a quotation file until Step 4.)* The owed list stays at two, not "one entry shorter":
      it is computed from missing parent tables, and `quotations` already existed.

> ⚠️ **A fourth carried-forward item, found 2026-09-13 against the source document.** The owner
> supplied the original Purchase Order #226 as the reference for how the PDF should look. Checked
> element by element, `template.js` reproduces it faithfully — logo lockup, the blue rule pair, the
> `Date`/`Company Name`/`TO` header table, `#5B9BD5` headers on `#DEEAF6` rows, the totals stack,
> the General Condition block, the signature pair, the footer band, and the S.T.I.S watermark. The
> arithmetic checks out too: the PO reads `7368.42 → +14% = 8400.00 → −1% = 8326.32`, and `D-64`'s
> documented order gives `8316.00`, so the `10.32` that retired PO #226 as a reconciliation target
> reproduces exactly from the source rather than being carried as an assertion.
>
> **What does not survive the port: the template hard-codes its percentages into its labels.** Both
> dictionaries carry `Discount 5%` / `الخصم 5%` and `14% VAT` / `ضريبة القيمة المضافة 14%` as
> literal strings. Correct for a prototype with fixed sample data; wrong in production, where
> `discountPercent` and `taxPercent` are per-quotation fields already on `QuotationDetail` and the
> tax rate is configurable — and it collides with Module 0's "no hard-coded user-facing strings"
> rule.
> `D-79` named three carried-forward items and did not catch this one. It is **Step 2's**, where the
> template is ported, so it costs nothing now; left unrecorded it would have shipped a PDF
> permanently claiming 5% and 14%.

**What Step 1 does not cover, stated rather than discovered later:** no renderer, no Browsershot
dependency, no template, no endpoint, no queue job, no frontend. Browsershot is **not** in
`crm/composer.json` — adding it is its own point in Step 2, which is the only point in this module
that may touch `composer.lock`. Arabic rendering is proven by `P-01` and approved as the template by
`D-79`, but nothing in Step 1 renders anything, so the Arabic criterion cannot be ticked here. The
three items `D-79` carried forward — live page numbering, the one-page re-check, and the
customer-view model — are Steps 2 and 1.1 respectively, and only the third is closed by this step.

### Step 2 — the renderer and the template *(point list published and **approved** 2026-09-22)*

The layout is `D-89`'s (the company's offer form, full totals block kept), not `P-01`'s. Two facts
shape the step: **only the `pdf` image renders** — it alone has Chromium and Puppeteer, and CI
asserts the `app` image has neither — so template tests read HTML in the normal suite and real
renders run in `tests/PdfImage/` inside the `pdf` image; and `D-89`'s fields with no source yet
(Att, per-line delivery time, Settings texts, job title) wait for their owners and are omitted, never
printed blank.

**Owner decisions — ✅ approved 2026-09-22 with "approved" alone, so each default is the decision.**
- **Q7 · a real render in CI.** Appended as one step to `php-image.yml`'s `verify` job (2.6).
- **Q8 · Arabic wording** of the opening, closing and sign-off lines: drafted in `lang/ar/pdf.php`,
  corrected by the owner at review; the Arabic criterion stays `[~]` until the owner confirms it.
- **Q9 · faces.** Inter for Latin and digits (`Design_System_EN.md` §4.1), Noto Sans Arabic for
  Arabic — not the form's Times-style serif.

- [x] **2.1** `PdfRendererInterface` + `BrowsershotPdfRenderer` — `spatie/browsershot` added, the
      only point in this module that touches `composer.lock`. *Verified by* a real Arabic + English
      render in the `pdf` image (`%PDF-`, Noto Sans Arabic embedded), and the `app` image refusing
      by name, at once, instead of waiting out a timeout.

      *(2026-09-22, #204 — chromium flags are `verify.php`'s; JavaScript off because the sandbox is.)*
- [x] **2.2** Fonts (four faces, OFL licences) and the `D-89` letterhead into `crm/resources/pdf/`,
      embedded base64. *Verified by* a test that the template references no OS font and no URL.

      *(2026-09-23, #218 — `PdfAssetsInterface`; families `CRM Sans` / `CRM Sans Arabic`, names no OS
      ships.)* ⚠️ **The first version of the embedding test was a false verifier**: it searched the
      PDF's bytes for "Inter", which passed even with both faces deliberately broken, because this
      image installs `fonts-inter` too. Measured instead — with the faces the PDF carries
      `Inter-Regular` + `NotoSansArabic-Regular`, without them `DejaVuSans` — and the test now reads
      `/BaseFont` entries and fails on the same probe.
      **Was blocked on Module 0's filesystem scan** — `StorageServiceTest` exempted only Storage's
      own Infrastructure, and `FilePdfAssets` reads the repository's own fonts and letterhead, which
      is not what `§17` governs (that is uploads, kept outside the application directory and served
      through a permission-checked API). Requested from that module's owner rather than written by
      us, and closed by his #219.
- [x] **2.3** `subject` (the deal's title, through Deals' own contract) and `signatoryName` (the
      creator, through `UserFactsInterface`, an `IdentityContract` grant) join the view.
      *Verified by* mapper tests, and 1.2's leak test still green.

      *(2026-09-23, #220 — a **new** `DealTitlesInterface`, not a sixth method on
      `DealFactsInterface`: Module 6's tests fake that interface, and a method added to it is a fatal
      error in their fakes — measured, not assumed. Both fields are absent rather than refused: a
      deal need not be titled, and Identity does not name the hidden Super Admin or a deleted
      account.)*
- [x] **2.4** The Blade template, `D-89`'s layout, one template for RTL and LTR, every label from
      `lang/{ar,en}/pdf.php`, **percentages as placeholders**, no tax row when exempt (`D-63`), no
      delivery-terms line when the flag is off. *Verified by* HTML tests for each rule, and the
      rendered HTML searched for 1.2's sixteen cost and supplier values.

      *(2026-09-23, #224 — both languages also render on one page through real Chromium with the
      embedded faces.)* **Two defects in my own tests, found by probe, not by review:** the label
      scan first flagged `{{ $view->finalTotal }}` for containing "Total", so it now strips Blade
      expressions and reads the forbidden literals out of `lang/en/pdf.php` itself; and it excluded
      every label containing a colon — which is the intro line — so a hard-coded copy of that line
      passed until the filter was narrowed to `:placeholder`. **§14.6's "template editable from the
      Super Admin screen" is not met** and is on the debt register above, by the owner's ruling.
      The Arabic prose is the agent's draft and stays `[~]` until the owner corrects it (Q8).
- [~] **2.5** Live page numbering (Chrome `footerTemplate`) and rows that never split. *Verified by*
      a 40-line quotation over several pages numbered correctly, and a 3-line one on one page.

      *(2026-09-24, #226 — `CustomerQuotationHtml::footer()`, the page box moved from the template's
      `@page` to the renderer so the margin and the footer are one decision.)* ⚠️ **The footer is the
      one place the document does not embed its faces, and not by choice:** Browsershot passes
      `footerTemplate` to Chrome as a command argument, so a base64 face in it makes the command
      exceed the OS limit — `proc_open(): posix_spawn() failed: Argument list too long`, measured,
      not reasoned. It therefore names `Inter` / `Noto Sans Arabic`, which `docker/php/Dockerfile`
      installs in the image that renders and which under `D-66` is the production environment too.
      The page above the footer still embeds everything. **`[~]` and not `[x]`:** a PDF's text is
      glyph indices, so the printed numbers cannot be read back in a test — what is proven here is
      that 40 lines paginate, 3 lines do not, and that asking for the footer changes the document
      (proven by a probe that removed the wiring). **A person still has to look at the numbers, at
      Point 2.7**, and the same goes for the no-split rule, which is asserted as CSS and not as a
      measured row position.
- [x] **2.6** The real render in CI (Q7). *Verified by* the job failing on a broken renderer first.

      *(2026-09-24, #227 — one step appended to `php-image.yml`'s `verify` job, which already builds
      the pdf image.)* It runs `php artisan test tests/PdfImage` inside `crm-php:ci-pdf`, the only
      image with a browser — the shards cannot run these, and a `markTestSkipped` there would have
      reported a pass for a test that never ran, which is point 0.6's failure in a different
      costume. `verify.php` proves the **image** renders Arabic; this proves the **module** does.
      *Probed* with the identical command locally: `CHROME_PATH=/nonexistent/chromium` fails the run
      with `No browser at …` and exits 2, so a renderer that stops working turns the build red.
- [~] **2.7** Visual sign-off: Arabic and English sample PDFs on the PR, against the offer form.

      *(2026-09-24, #228 — both pages rendered and **looked at**; samples in
      `~/Desktop/module9-pdf-samples/`, PDF and PNG per language.)* **A defect no test here could
      have caught, found by looking:** on the Arabic page `2026-08-13` rendered as `13-08-2026` —
      bidi reorders a Latin-digit date inside an RTL paragraph. Nothing in the value is wrong, so
      only an eye catches it, and a reader cannot tell which half is the day. Dates are now wrapped
      in U+2066…U+2069 isolates — invisible characters, so Blade still escapes the value — with a
      regression test proven by a probe. **A second finding that was not a defect:** the first
      Arabic capture looked shifted and clipped; measuring `scrollWidth` against `clientWidth` gave
      **794 = 794 in both languages**, so there is no overflow — it was Chromium's `fullPage`
      screenshot of an RTL document. Measured before "fixing" something that was not broken.
      **`[~]` until the owner has looked.** Four questions for him: the Arabic prose (Q8, still the
      agent's draft); whether Arabic should read `٥٪` rather than the bidi-rendered `(%5)`;
      whether money wants thousands separators (`34,854.10`); and whether the date should be
      `14/07/2026` as the paper form writes it rather than `2026-07-14`.

### Step 3 — generation *(point list published 2026-09-27 with Steps 4 and 5, **approved** by merging #236)*

Step 3 builds `POST /api/v1/quotations/{quotation}/pdf`, the job on the `pdf` queue (`PRF-04`,
`§15.1`), its retry and failure record (`§14.6`, Q3) and the stored snapshot (`D-71`, Q4). It first
closes the two things owed before the endpoint: `LineDescriptionsInterface`'s binding (1.2's open
note) and `tests/Feature/Pdf` running in no CI shard (the debt row "Measured live, 2026-09-26").
**What `main` already holds (measured 2026-09-27 at `142ceb1`):** `quotation.generate_pdf` and
`quotation.export_pdf` seeded exactly as §3.5 reads (`PermissionMatrix.php:301-315`); a `pdf` worker
draining `--queue=pdf --tries=3` (`docker-compose.yml`); `quotation_files` (1.3), with
`AttachmentParent::Quotation` refused by the attachment composite until Step 4; and **no event of any
kind** raised by Quotations.

**Owner decisions this list needs — each names its default, and the default is what ships if the
owner says only "approved".** ✅ **Approved 2026-09-27 with "do all the work", so every default
below is now the decision** — Q11's stated risk and Q13's amendment of Q5 included.
- **Q10 · the snapshot is taken when the button is pressed.** The endpoint maps the quotation to
  `CustomerQuotationView` and hands the view to the job, which renders exactly that. The criterion's
  "fixed snapshot" is then the quotation as the employee saw it — an edit landing between the click
  and the render cannot change the document — and a quotation that cannot be described (a line whose
  supplier offer was archived, so 1.2's mapper refuses the view) is a `422` at once, not three failed
  attempts in the queue. **Default: yes.** The alternative maps inside the job.
- **Q11 · every status may be printed.** §3.5's *generate PDF* row carries no state condition, unlike
  *edit*'s "Own (Draft)", and the ordering note above already names a status rule as a check at this
  endpoint. **Default: no status check** — the documented reading. **The risk, stated:** a Draft's or
  a Pending quotation's prices can leave the building as a PDF before anyone approved them. The
  alternative — `approved` and later only, `422 quotation_not_approved` otherwise — is a `D-xx`.
- **Q12 · no `Idempotency-Key`.** `OpenAPI §9.1` asks for one on "actions that change
  irreversible-equivalent business state"; a generation adds one snapshot and changes nothing else
  (Q4), so a replay costs a file, not a wrong state. The screen (5.1) disables its button while a
  generation is queued. **Default: no key**, with this as the recorded reason.
- **Q13 · Q5 amended — a generated PDF is scanned like any other file, not inserted as `clean`.**
  Q5's default needs `FileWriterInterface::create()` to take a scan status, and it has no such
  parameter; Storage is Module 0, where since 2026-09-10 a change is requested, not written. Our
  render through `ScanStoredFile` — the path Deals, SupplierQuotations and Purchase Orders already
  take — reaches the same `clean` without a change to anyone's module, and a scanner outage becomes
  one more failure the retry absorbs (`DownloadFile` serves nothing that is not clean). **Default:
  scan.** The alternative keeps Q5 and requests the parameter from Module 0's owner.
- **Q14 · a send queues a PDF** — `D-90`'s "connecting the two is Module 9's to do". Flow 1 step 8 and
  §6.1 ("Sent — PDF generated") give a sent quotation its PDF; `D-90` lets the send not wait for it.
  **Default: a successful send queues one generation in the sender's name, and the send does not
  wait.** Quotations raises no event, so this needs Module 7's owner to dispatch a `QuotationSent`
  event once the send commits, published in `QuotationsContract`, for `Pdf` to listen to — **3.6 is
  blocked on that request**, the way 2.2 waited on #219. The alternative keeps generation manual and
  records in a `D-xx` that Flow 1 step 8 is a second click.
- **Q15 · the document's language** — *found while building 3.3, not in the list as approved; its default
  ships as the others' did, and the owner may overrule it.* `CustomerQuotationHtml::render()` takes a
  locale and nothing chose it: customers carry no language, and the company's own offer form
  (`D-89`) is English while the application's default locale is Arabic. **Default: the generation
  request names `ar` or `en`, falling back to the request's own locale (`Accept-Language`, `OpenAPI
  §2`), and `pdf_generations.locale` records it.** The alternative — always the request's locale —
  would make an Arabic-screen employee switch the whole interface to print an English offer.

- [x] **3.1** `tests/Feature/Pdf` (47 tests on `main`, 48 with #228) and `tests/Feature/Support` (12)
      join the shard matrix (`php-image.yml:263-279`), and a test fails when a directory under
      `tests/Feature` is named in no shard or in two — the check `php-image.yml:252-255` leaves to a
      person adding up three `Tests:` lines, which is how 59 tests went unrun. *Verified by* the PR's
      three shards summing to the local full suite, and the guard failing on a probe that drops
      `tests/Feature/Pdf`.

      *(2026-09-27, #240 — both in `suppliers-customers`; the guard is a `build` step, not a PHPUnit test,
      because no container a test runs in can see `.github/`. Probed three ways: missing, doubled, stale.)*
- [x] **3.2** `LineDescriptionsInterface` bound: `Pdf/Infrastructure/CatalogLineDescriptions` takes
      each supplier line's `catalogItemId` from `SupplierItemPricingInterface::priceFor()` and its
      label from `CatalogItemLabelsInterface`; Pdf's ruleset gains `SupplierQuotationsContract` and
      `CatalogContract`. It returns the catalog label and nothing else, although `SupplierItemPrice`
      carries the supplier's price. *Verified by* a supplier line holding a distinctive price that is
      asserted absent from the result, and an archived supplier line having no entry, so 1.2's
      refusal fires. **A duplicate, stated:** the same join is `ShowQuotation::lineNames()` in Module
      7's Application layer, which `Pdf` may not reach. The ceiling: this class is deleted the day
      `QuotationLine` carries the name.

      *(2026-09-27, #241 — `CatalogLineDescriptions`; a probe leaking the price and one removing the
      binding each turned their test red.)*
- [x] **3.3** `pdf_generations`, Pdf's own table: `id` (the `job_id` of `OpenAPI §4.3`),
      `quotation_id`, `status` (`queued` · `completed` · `failed`, a CHECK), nullable `file_id`,
      `attempts`, `failure_reason`, the audit columns with `created_by` as the requester, and
      `deleted_at` (`DB-01`); every foreign key declared (`DB-04`) and an index for "the latest for one
      quotation". Written through the query builder behind a `Pdf` interface — **no Eloquent model,
      so `D-77`'s split is still not forced** (1.0). *Verified by* up and down inside the suite (never
      a `migrate:*` against `crm`), the CHECK refusing an unknown status, and each key refusing an
      orphan.

      *(2026-09-27, #242 — beyond the listed columns: `locale` (Q15) and `finished_at`, with four more
      CHECKs tying the file, the reason and the end time to the status. No interface yet: its first
      caller is 4.2, so it arrives there rather than as dead code here.)*
- [x] **3.4** The job, on the `pdf` queue: render the view it was handed, `store()` it under
      `AttachmentParent::Quotation`, write the `files` row, the `quotation_files` pivot and the
      generation's `file_id` in one transaction, scan (Q13), mark `completed`. **Idempotent
      (§15.1):** an attempt that finds `file_id` set only re-scans, so no retry writes a second file.
      The retry count is the worker's `--tries=3`, as for every job here; `failed()` marks the
      generation `failed` with its reason — Q3's failure record — and Q3's notification row goes on
      the debt register naming §18.2. *Verified by* a renderer that fails twice then succeeds leaving
      exactly one file, one that always fails ending `failed` with no file, and a scanner outage
      retried to `completed`.

      *(2026-09-28, #260 — `store()` is handed the bytes as a `data:` URL, so no Module 0 change and no
      temp file (the storeContents request is withdrawn); a real Chromium render, 356 KB, round-tripped.)*
- [x] **3.5** `POST /api/v1/quotations/{quotation}/pdf`, in our own block of `routes/api.php`, under
      `permission:quotation.generate_pdf`. The scope is the deal owner's (owner ruling 2026-09-11),
      read through `DealFactsInterface` and resolved by `DealRowScope` — published in our own
      `DealsContract`, not copied. The view is mapped here (Q10); the answer is `202` with `job_id`
      and `queued`; audited `QUOTATION_PDF_REQUESTED` with the caller as the actor, since a queued job
      has no request to take one from. Refusals as named cases, each a test: the CEO and the Outdoor
      Supervisor `403` (no grant); Procurement (`Asgn`, Q2) and the Team Leader (`Team`, `D-a`) `403`
      by name; a quotation outside an `Own` caller's reach `404`, which does not confirm it exists; an
      indescribable one `422`. *Verified by* those tests and `permission-matrix-auditor` on the PR.

      *(2026-09-29, #265 — the view is mapped at the press, so an unprintable quotation is a 422 worded
      by its missing fact, and the panel now shows those words; the job leaves after the commit.)*
- [x] **3.6** A send queues a generation (Q14). **Blocked** until Module 7's owner publishes
      `QuotationSent`. *Verified by* a send leaving one `queued` generation in the sender's name, and
      a failed render leaving the quotation `sent`.

      *(2026-09-29, #266 — `QuotationSent` arrived with F-31 · 1.3; the listener reuses 3.5's
      `RequestQuotationPdf`, and a refusal or an unprintable quotation leaves the committed send alone.)*

### Step 4 — reading and downloading *(published and approved with Step 3, #236)*

- [x] **4.1** `QuotationPdfAttachmentPermission` in `Pdf/Application/Access`, registered for
      `AttachmentParent::Quotation` in `ParentAwareAttachmentPermission`'s map (our append in
      `AppServiceProvider`), so the existing `GET /files/{file}/download` serves a quotation PDF under
      `quotation.export_pdf` at its scope (`D-38`; `OpenAPI §8.3`'s re-check at request time). The
      Manager, the Team Leader and the CEO hold ✅, read as `All` (§3.2, `D-91`); the sales roles
      `Own`; Procurement fails closed (Q2). *Verified by* a download per role — the CEO's, with no
      generate grant, among them — and 1.3's "refused until Step 4" test turned around.

      *(2026-09-27, #255 — `DealRowScope::reaches()` published in our `DealsContract`; the tests store a real
      file through `store()` and remove only the paths they wrote, since tests share the dev volume.)*
- [x] **4.2** `GET /api/v1/quotations/{quotation}/pdf` — what `OpenAPI §4.3` leaves to "the module
      contract": the latest generation (`job_id`, `status`, `requested_at`, `completed_at`,
      `failure_reason`) and the latest completed file's id, under `quotation.export_pdf` at its
      scope. One object, not a list: Q4 serves the most recent, and earlier snapshots stay stored
      (`DB-01`) with no screen of their own. *Verified by* each status read back, and 4.1's role cases.

      *(2026-09-28, #256 — `completed_at` only for a completed render; a failed one leaves the previous
      file as `latest_file_id`; `QuotationPdfAccess` is now the one reach behind 4.1 and 4.2.)*

### Step 5 — the screen *(published and approved with Step 3, #236)*

- [~] **5.1** `QuotationPdfPanel.vue` and `services/pdf.ts`: *Generate* under
      `quotation.generate_pdf`; a queued state that polls 4.2 until `completed` or `failed`; the
      failure's reason; *Download* under `quotation.export_pdf` through `services/files.ts`'s existing
      download. Both languages; empty, loading, error and refused states. *Verified by* Vitest,
      `rtl-ui-verifier`, and the browser at 375 px and on the desktop, in Arabic and English.

      *(2026-09-28, #258 — built ahead of 3.5 on its contract, `POST …/pdf` with `{locale}` (Q15), so
      Generate says "could not be requested" until 3.5 lands; checked in headless Chrome over CDP with
      canned PDF answers. `[~]` until the Browser-MCP pass: the extension was not connected.)*
- [ ] **5.2** The panel on the quotation page: one import and one element in
      `QuotationDetailView.vue`, beside `QuotationPurchaseOrder` (`:611`). **Requested from Module 7's
      owner, not written by us** (the per-module rule); until it lands, 5.1 stands on its own tests.
- [x] **5.3** Module 9 closes: the Arabic manual test list (in the message and on the PR), the section
      frozen to `checklist/module-09.md` with its stub, the ownership row, and the guide's Current
      State.

      *(2026-09-29, #267 — closed at 23 of 30 without waiting on the owner or Module 7, on the owner's
      instruction: 5.2 stays Module 7's line, and the list was handed over without prior approval.)*

**What stays open after 5.3, stated now rather than discovered then:** the failure *notification*
(Q3 — §18 belongs to no module, so that criterion stays `[~]`); the Arabic criterion until the owner
confirms Q8's prose; 2.5's page numbers until an eye has read them (2.7); "visible in Queue Monitor"
(`§15.1`), because Horizon is not installed — the `pdf_generations` row and `failed_jobs` are the
record meanwhile; §14.6's editable template and the footer band (debt register above); and `D-89`'s
Att, per-line delivery time, Settings texts and job title, each waiting on its owner.

### قائمة الاختبار اليدوي — الوحدة 9 (إنشاء ملف PDF)، سُلِّمت 2026-09-29 (#267)

> ⚠️ لوحة «ملف PDF للعميل» لا تظهر على صفحة عرض السعر حتى يضيف مالك الوحدة 7 سطرها (5.2). بنود القسم (ب) مكتوبة جاهزة للتشغيل بعد ذلك مباشرة. ما يُفحص اليوم هو القسم (أ)، وما لا يُختبر بالنقر مذكور في (ج) مع سببه.

**(أ) ملفات العينة: تُفحص اليوم.** المجلد `~/Desktop/module9-pdf-samples/`، وأي دور يكفي.

1. افتح `quotation-ar.pdf` ⇒ يجب أن ترى النص العربي متصل الحروف، من اليمين إلى اليسار، بالخطوط المضمّنة، دون مربعات أو حروف منفصلة. **[المعيار: العربية تُعرض صحيحة بخطوط مضمّنة]**
2. في الملف نفسه ابحث عن أي اسم مورّد أو سعر شراء أو تكلفة أو هامش ⇒ يجب ألّا يظهر شيء منها، وأن تكون كل الأسعار الظاهرة أسعار البيع للعميل. **[المعيار: لا اسم مورّد ولا سعره في أي مكان من الملف]**
3. انظر إلى تذييل كل صفحة ⇒ يجب أن ترى «صفحة X من Y» بأرقام صحيحة. **[المعيار: ترقيم الصفحات ديناميكي]**
4. انظر إلى تاريخ العرض ⇒ يجب أن يظهر بترتيبه الصحيح (مثل 2026-08-13)، لا مقلوباً.
5. افتح `quotation-en.pdf` ⇒ يجب أن ترى النسخة الإنجليزية من اليسار إلى اليمين، بتخطيط نموذج العرض نفسه (`D-89`).
6. قارن النسختين بنموذج العرض الأصلي للشركة ⇒ دوّن رأيك في الأسئلة الخمسة المفتوحة: صياغة النص العربي (Q8)، و`٪` أم `(%5)`، وفواصل الآلاف، وشكل التاريخ، وشريط التذييل (صورة أم نص من الإعدادات).

**(ب) لوحة «ملف PDF للعميل» على صفحة عرض السعر: بعد 5.2.** الأدوار بالترتيب، والمدير أولاً.

7. (المدير) افتح عرض سعر لم يُنشأ له ملف ⇒ ترى قسم «ملف PDF للعميل»، وعبارة «لم يُنشأ ملف PDF لعرض السعر هذا بعد.»، واختيار «لغة المستند»، وزر «إنشاء ملف PDF»، دون زر تنزيل. *(الحالة الفارغة)*
8. (المدير) أعد تحميل الصفحة ⇒ تظهر لحظةً «جارٍ تحميل حالة ملف PDF…» ثم الحالة. *(حالة التحميل)*
9. (المدير) اختر «English» واضغط «إنشاء ملف PDF» ⇒ تظهر «جارٍ إنشاء ملف PDF… تتحدّث هذه الحالة تلقائيًا.» والزر معطّل. ثم خلال ثوانٍ، ودون إعادة تحميل، تظهر «ملف PDF جاهز.» وزر «تنزيل ملف PDF». **[المعيار: الإنشاء غير متزامن على طابور `pdf` ويُحفظ مع عرض السعر]**
10. (المدير) اضغط «تنزيل ملف PDF» ⇒ يُنزَّل ملف باسم رمز العرض (مثل `QT-2026-0007.pdf`)، وهو بالإنجليزية.
11. (المدير) كرّر 9 و10 على عرض سعر بنوده من ثلاثة موردين، ثم افتح الملف ⇒ لا اسم مورّد ولا سعر شراء ولا تكلفة ولا هامش. **[المعيار: عرض بثلاثة موردين ⇒ لا اسم مورّد ولا سعره]**
12. (المدير) عدّل العرض، ثم اختر «العربية» واضغط «إنشاء ملف PDF» ⇒ يُنشأ ملف جديد بالعربية، ويعطي التنزيل الأحدث، والملف الأول باقٍ محفوظاً لم يتغيّر. **[المعيار: لقطة ثابتة]**
13. (المدير) أوقف «إظهار شروط التسليم في ملف PDF» في العرض، ثم أنشئ الملف ⇒ لا يظهر قسم شروط التسليم في الملف. **[المعيار: `show_delivery_terms = false` ⇒ يُحذف القسم]**
14. (مدير النظام، ثم المدير) امسح اسم الشركة من إعدادات النظام، ثم اضغط «إنشاء ملف PDF» ⇒ رسالة «لا يمكن إنشاء ملف PDF للعميل بعد: اسم الشركة غير مُعرَّف في إعدادات النظام.»، ولا يُنشأ شيء. أعد الاسم بعدها. *(رفض 422)*
15. (المدير) أرسل عرضاً معتمَداً إلى العميل ⇒ يصبح «مُرسَل» فوراً دون انتظار. ثم تُظهر اللوحة «جارٍ إنشاء ملف PDF…» ثم «ملف PDF جاهز.»، والملف باسمك وبلغة شاشتك. *(Q14)*
16. (المدير) بدّل لغة الواجهة إلى English ⇒ كل نصوص اللوحة بالإنجليزية ومن اليسار إلى اليمين. ارجع إلى العربية ⇒ من اليمين إلى اليسار. *(اللغتان والاتجاهان)*
17. (المدير، على الجوال بعرض 375) ⇒ اللوحة داخل الشاشة دون تمرير أفقي، والأزرار تنزل إلى سطر ثانٍ، وكل زر يسهل لمسه.
18. (المدير) افصل الشبكة ثم أعد فتح الصفحة ⇒ حالة خطأ داخل القسم مع زر «إعادة المحاولة». أعد الشبكة واضغطه ⇒ تعود الحالة. *(حالة الخطأ)*
19. (الرئيس التنفيذي) افتح عرضاً له ملف ⇒ ترى الحالة وزر «تنزيل ملف PDF» فقط، دون اختيار اللغة ودون زر الإنشاء. **[المعيار: الرئيس التنفيذي يُنزّل الملف الموجود ولا يُنشئ جديداً أبداً]**
20. (المبيعات الداخلية) افتح عرضاً لصفقة تملكها ⇒ تُنشئ وتُنزّل كما في 9 و10.
21. (المبيعات الخارجية) افتح عرضاً لصفقة تملكها ⇒ مثل 20. أما عرض صفقة زميل فلا يصل إليه أصلاً.
22. (قائد الفريق) اضغط «إنشاء ملف PDF» ⇒ رسالة «لا تملك صلاحية إنشاء ملف PDF لعرض السعر هذا.»، ويبقى التنزيل متاحاً له. *(رفض بالاسم، `D-a`)*
23. (المشتريات) إن وصلتَ إلى صفحة عرض السعر ⇒ داخل القسم «لا تملك صلاحية الوصول». *(حالة الرفض، Q2)*
24. (المشرف الميداني) إن وصلتَ إلى صفحة عرض السعر ⇒ لا يظهر قسم ملف PDF أصلاً.

**(ج) ما لا يُختبر بالنقر اليوم، ولماذا**

25. القسم (ب) كله ينتظر سطر مالك الوحدة 7 في صفحة عرض السعر (5.2). اكتملت 3.5، فيمكنه إضافته الآن.
26. **[المعيار: فشل الإنشاء ⇒ إعادة محاولة تلقائية + إشعار الموظف]** إعادة المحاولة (ثلاث مرات) مبنية ومختبرة، والسبب يظهر في اللوحة. أما الإشعار فغير مبني، لأن وحدة الإشعارات فارغة (Q3، وسطرها في سجل الديون يسمّي §18.2). ولا توجد طريقة يدوية سهلة لإفشال المتصفّح عمداً.
27. **[المعيار: الملف يُبنى من نموذج عرض لا يستطيع بنيوياً حمل حقول المورّد أو التكلفة أو الهامش]** يُثبت بالاختبارات (1.1، 1.2، 2.4) لا بالنقر.
28. ظهور المهمة في «مراقب الطوابير» (§15.1): Horizon غير مثبّت، والسجلّ حتى ذلك الحين هو صف `pdf_generations` و`failed_jobs`.
29. القالب القابل للتحرير (§14.6) وشريط التذييل، وحقول `D-89`: ‏Att، ومدة التوريد لكل بند، ونصوص الإعدادات، والمسمى الوظيفي. كلٌّ منها ينتظر مالكه.
