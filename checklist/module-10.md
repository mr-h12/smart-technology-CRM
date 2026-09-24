> Frozen history of Module 10, cut verbatim from `CHECKLIST.md` on 2026-09-24 (commit `db7ff88`).
> Open boxes here are stale copies — the live ones are in `CHECKLIST.md`. Do not tick here.

## Module 10 — Customer Response & Purchase Orders

> As a sales employee, I want to record the customer's response, so that the deal moves along the
> correct path.

**Tables** `purchase_orders` — auto `po_number` + free-text `customer_po_reference` + date + attachment

**Acceptance criteria**
- [x] Partial or Counter → full copy saved automatically, employee edits the new version *(1.4 #211: `CreateQuotationVersion::copyOf` in the response's transaction, the answer names `new_version`; 3.1 #221 opens the new draft)*
- [x] Counter or Rejected → **mandatory reason** before the status is accepted *(1.4 #211 `rejection_reason_required` on `counter`, none on `partial` (§6.3); 1.5 #212 on `rejected` from `sent` and `expired`; the CHECK at `create_quotations.php:277-279`; 3.1 #221 the reason field)*
- [x] Rejected → quotation archived · **customer stays in the list** · deal becomes Lost *(1.5 #212: Q12's last-live count under `FOR UPDATE`, then 1.2's `quotationRejected` → `lost`; the archive is the `history` bucket, no restore (Q6, `D-90`); the customer row is not written)*
- [x] `valid_until` passes with no reply → **Expired** automatically (J-01) *(2.1 #214: `quotations:expire` daily on `maintenance` and once on the `scheduler` container's start (`D-55`), `QUOTATION_EXPIRED` with a system actor)*
- [x] Search works on both the internal PO number and the customer's reference *(2.2 #216 `SearchIndex::PurchaseOrders` = `po_number` + `customer_po_reference`; 3.3 #223 the search box)*
- [x] Every version preserved via `parent_id` + `version` *(1.4 #211 the copy is a new row under `parent_id`; 3.2 #222 "Previous Quotations" lists every version, each chain together)*

### Point list — published 2026-09-23, approved by merging #206

**What is on `main` (measured 2026-09-23 at `1573287`):** the edges `approved → sent` and
`sent → accepted|partial|counter|rejected|expired` (`QuotationStatusTransition.php:29-39`) with **no
writer** — no route, use case or screen sends a quotation or records a response, and `sent_at` is
read but never written. `rejection_reason` is already required by a CHECK for `rejected` **and**
`counter` (`create_quotations.php:277-279`). 7 · 4.3's copy exists (`POST /new-version`, from
`partial|counter|expired`, by hand) and copies `returned_at`/`return_note` onto the new version. The
permissions `quotation.send_to_customer` and `quotation.record_customer_response` are seeded (Manager
All, TL Team, both Sales Own) and unused. `PO-` needs no change to `DocumentNumberAllocator`;
`purchase_order_files` exists **without** its foreign key (`FilesMigrationTest.php:265`); no
`purchase_orders` table, no PO permission, no `SearchIndex` case. `SupplierItemQuantityInterface::consume`
has no caller and is outside `SupplierQuotationsContract`. No deal write is reachable from another
module (`ChangeDealStatus` only); the deal reaches `lost` only from `quotation_sent` or `negotiations`
(`DealStatusTransition.php:39-40`). `J-01` does not exist, and nothing runs the scheduler (debt register).

**The owner's answers, 2026-09-23 (Q1–Q11 asked in conversation; Q12 raised by the owner):**

- **Q1 · send.** Built here, **without waiting for the PDF**. Flow 1 step 8 couples sending with the PDF,
  so the deviation is **`D-90`**, not only a debt line.
- **Q2 · the deal moves on two events only.** Sending moves `supplier_quotation → quotation_sent`
  (§4.4 "Quotation Sent · Sales (after approval)"); a rejection moves it to `lost` under Q12's rule, the
  rejection reason becoming the lost reason. Accepted, Partial and Counter do not move the deal —
  §4.4 gives `won` to TL/Manager.
- **Q3 · how Quotations moves a deal.** A narrow write interface in `DealsContract` that runs
  `ChangeDealStatus` inside the caller's transaction and recomputes the customer status as it does
  today. No domain event.
- **Q4 · placement.** Inside `crm/app/Modules/Quotations/`, Module 8's Q1 reasoning; the PO is written in
  the acceptance's transaction. Quotations gains `StorageContract` for the PO's attachment.
- **Q5 · the PO at acceptance.** Accepted **requires** `customer_po_reference` and `po_date` and writes
  `purchase_orders` with a `PO-YYYY-NNNN` number (`D-12`, `D-53`, §4.6) in the same transaction, plus one
  `consume()` per quotation line (`D-81`, F-05 · 1.5). The attachment is uploaded **after** acceptance.
- **Q6 · archive on rejection.** The `history` bucket (`QuotationListCriteria::BUCKETS`) is the quotation
  archive; **no restore is built**. The owner's ruling on Flow 7's "Restore · Manager": when a customer
  comes back after a rejection, **a new deal is opened — a Lost deal is never revived**. Recorded in `D-90`.
- **Q7 · Partial and Counter copy automatically** (§6.3, `D-08`), in the response's transaction; the
  response returns the new draft's id. `new-version` by hand stays for `expired`. The copy stops carrying
  `returned_at`/`return_note`.
- **Q8 · `expired → rejected`** is a new edge (§10.5 "records Rejected with reason 'no response'"), reason
  required, and Q12's rule applies to it.
- **Q9 · `J-01`.** Daily on `maintenance`; `sent` with `valid_until` before today in `locale.timezone`
  ⇒ `expired`; audited with a system actor. **Catch-up on startup is required:** §15 marks `J-01` ✅ and
  `D-55`/`ST-05` say missed jobs run on startup (only `J-15`'s ❌ is exempted in §15). `J-02` marks ✅ too
  and skips it — registered as debt, not fixed here. §4.5 row 3 ("Expired with no reply ⇒ No Response")
  is registered as debt with its owner named.
- **Q10 · no PO permission.** A PO is read by whoever may view its quotation, scoped through the deal;
  its file is attached under `quotation.record_customer_response` (§17: a file's permission is its
  parent's, `D-38`).
- **Q11 · routes.** `PATCH /quotations/{id}/send`; `PATCH /quotations/{id}/respond` with
  `{response: accepted|partial|counter|rejected, reason?, customer_po_reference?, po_date?}` under
  `quotation.record_customer_response`; `GET /purchase-orders`, `GET /purchase-orders/{id}`,
  `POST /purchase-orders/{id}/documents` (owner, 2026-09-23 at 1.1: `/documents`, the deals and supplier-quotations shape, not the `/files` first proposed). The `PATCH`es carry `If-Match` and no `Idempotency-Key` (`OpenAPI
  §7.2`'s reading for the approval actions); `consume()` keeps its own per-line key.
- **Q12 · a deal may hold several live quotations — measured, and the rule.** Nothing forbids it: no
  constraint on `quotations.deal_id` beyond `UNIQUE (parent_id, version)`, no check in `CreateQuotation`,
  no document limits it; the dev database holds `DL-2026-0002` with **4** live and `DL-2026-0003` with **2**.
  *Live* = the `active` bucket: `draft`, `pending`, `approved`, `sent`. **Rule:** a rejection
  (`sent → rejected` or `expired → rejected`) moves the deal to `lost` **only when no other quotation of
  that deal is live** afterwards; otherwise the quotation is rejected and the deal is untouched. Counted
  inside the rejection's transaction, the deal's quotations locked `FOR UPDATE`, so two last rejections
  racing cannot both see one survivor. Recorded in `D-90`.

**The owner's answers during 2.2's questions, 2026-09-23:**

- **The Team Leader and Procurement read every quotation**, and through it every purchase order —
  `D-91`, built as point 2.2a before 2.2. `quotation.view` only; their bare ✅ cells in §3.5 follow
  the new `All` (§3.2's reading), so both see cost and margin on every quotation (accepted).
- **«إشعار خصم» is a discount on the sale price only** — the quotation's own `discount_amount`, not a
  separate credit note. Closes the question that waited for the accountant.
- **What a purchase order shows (2.2).** List: PO number, customer's PO reference, PO date, quotation
  code, customer name, the quotation's final total with its currency. Detail: all of that, plus when
  and by whom it was recorded, whether it has an attachment (the file itself is 2.3), the deal code,
  the salesperson who owns the deal, the quotation's status, and the total's breakdown (subtotal,
  discount, tax, additional items). **Never** cost, margin or suppliers. Sort `po_date` / `po_number` /
  `created_at` (default `-created_at`), no filter besides `q`; the quotation detail always carries
  `purchase_order` (null when none), and `respond` stops adding its own copy.

**Two edge rules this list adds, for the owner to confirm at merge** (no document settles them):

- **a · send from a deal that is not ready.** A deal before `supplier_quotation` (`lead` … `supplier_rfq`)
  cannot reach `quotation_sent` in one move, so send is refused `422` naming the deal's status; a deal
  already at `quotation_sent` or later is left where it is.
- **b · a rejection on a deal with no `lost` edge** (already `lost`, or `won` and beyond): the quotation
  is rejected and the deal is untouched, and the response says so.

#### Step 1 — send and the customer's response (backend)

- [x] **1.1** Docs only. The `D-90` row (Q1's PDF deviation, Q6's ruling, Q2's two deal moves, Q12's
      last-live rule, rules a and b) — the master is hook-protected, so the point hands the owner a
      script asserting its anchor once. `OpenAPI §7.1` gains the purchase-order routes and `§7.2` the
      `send` and `respond` rows (body, permission, audit event, state change, no `Idempotency-Key`).
      *(2026-09-23, #207 — `D-90` lands when the owner runs `paste_d90.py`; the upload route is `/documents`)*
- [x] **1.2** `DealsContract` gains the write: `quotationSent(dealId, actorId)` and
      `quotationRejected(dealId, reason, actorId)`, each through `ChangeDealStatus` inside the caller's
      transaction. Touches Module 5 (the second developer's) on F-13 · 1.2's precedent (#194). Proven:
      a rolled-back caller leaves the deal where it was; a deal with no `lost` edge is untouched (rule b).
      *(2026-09-23, #209 — `DealOutcomeInterface` + `RecordQuotationOutcome`, unrestricted scope; 1.3/1.5 take `deal_id` only from the authorised quotation)*
- [x] **1.3** `PATCH /quotations/{id}/send` under `quotation.send_to_customer`: `If-Match`,
      `approved → sent`, `sent_at`, `QUOTATION_SENT`, the deal moved per Q2 and rule a, one transaction.
      No PDF (`D-90`).
      *(2026-09-23, #210 — `SendQuotation`; rule a is `422 business_rule_blocked` · `deal_not_ready_to_send`)*
- [x] **1.4** `PATCH /quotations/{id}/respond` for `partial` and `counter`: `counter` needs a reason
      (`422 rejection_reason_required`), `partial` does not (§6.3); the new version is written in the same
      transaction through 4.3's copy, which stops copying `returned_at`/`return_note`; the response names
      the new draft. Audit: `QUOTATION_PARTIAL` / `QUOTATION_COUNTERED` + `QUOTATION_VERSION_CREATED`.
      *(2026-09-23, #211 — `RespondToQuotation` + `CreateQuotationVersion::copyOf`; a stray field is refused, the answer carries `new_version`)*
- [x] **1.5** `respond` with `rejected`, from `sent` and from `expired` (Q8's new edge): reason required,
      `QUOTATION_REJECTED`, then Q12's last-live count under `FOR UPDATE` and 1.2's `quotationRejected`
      only when it is zero. Proven with two live quotations on one deal: the first rejection leaves the
      deal, the second makes it `lost`.
      *(2026-09-23, #212 — the lock is taken before the write; the answer carries `deal_lost` (owner))*
- [x] **1.6** `respond` with `accepted`: migration `purchase_orders` (uuid, `quotation_id` FK and unique
      alive, `po_number` unique, `customer_po_reference`, `po_date`, audit columns, soft delete, `down()`)
      plus the foreign key `purchase_order_files` has owed since Module 0; `customer_po_reference` and
      `po_date` required; `PO-` from `DocumentNumberAllocator`; `SupplierItemQuantityInterface` joins
      `SupplierQuotationsContract` and `consume()` runs once per line keyed by the line's id; audit
      `QUOTATION_ACCEPTED` (old/new `consumed_quantity`) + `PURCHASE_ORDER_CREATED`; one transaction.
      Ticks **F-05 · 1.5**.
      *(2026-09-23, #213 — the answer carries `purchase_order` (owner A); old balance = new − quantity, exact under `If-Match`)*

#### Step 2 — `J-01` and the purchase order's read side

- [x] **2.1** `J-01 expire_quotations`: a use case and a job on `maintenance`, daily; `sent` and
      `valid_until` before today in `locale.timezone` ⇒ `expired`, `QUOTATION_EXPIRED` with a system actor,
      idempotent. A `scheduler` service in `docker-compose.yml` runs `J-01` once on start (the `D-55`
      catch-up) and then `schedule:work` — closing the "nothing runs the scheduler" debt row, and from
      then on `J-02` and `J-15` fire in the stack too. No deal move (Q2), no customer status (debt row).
      *(2026-09-23, #214 — one `UPDATE … RETURNING`, `user_id` NULL; unset/unknown zone ⇒ `app.timezone` (Q-A); dev `locale.timezone` = `Africa/Cairo`)*
- [x] **2.2a** `D-91`: the Team Leader and Procurement read every quotation. `PermissionMatrix` moves
      §3.5's TL `view` `Team → All` and Procurement's `Asgn → All`, with their bare ✅ cells (TL: view
      cost & margin, edit margin, edit tax, export PDF; Procurement: view cost & margin); every
      explicit cell keeps `Team` / `Asgn`. A migration swaps the live grants on an already-seeded
      database, audited `ROLE_PERMISSIONS_UPDATED` with the system actor, reversible. The `D-91` row and
      §3.5's cells go into the master through the owner's `paste_d91.py`.
      *(2026-09-23, #215 — 145 → 138 permission rows, grants 218 unchanged; dev `rbac:verify` 7/7 drift ⇒ matches)*
- [x] **2.2** `GET /purchase-orders` (paginated, scoped through the quotation's deal) with `q` over
      `po_number` **and** `customer_po_reference` through a new `SearchIndex::PurchaseOrders`;
      `GET /purchase-orders/{id}`; the quotation detail names its PO.
      *(2026-09-23, #216 — `has_attachment` through Storage's `hasFiles` (owner A); exempt ⇒ no tax keys; `respond` writes the PO before its re-read)*
- [x] **2.3** The PO's attachment: `POST /purchase-orders/{id}/documents` under
      `quotation.record_customer_response`, the list of its files, and the download mapping for
      `AttachmentParent::PurchaseOrder` (an unmapped parent is refused today, `ParentAwareAttachmentPermission.php:33-35`);
      `AttachDealDocument`'s shape (validate, store, scan after commit).
      *(2026-09-23, #217 — `documents` on the detail replaced `has_attachment` (owner A1); several per order (B1); download under `quotation.view`)*

#### Step 3 — the screens

- [x] **3.1** The quotation detail: a *Send* button (`approved`, `quotation.send_to_customer`) and a
      *Record the customer's response* dialog — four outcomes, the reason field for Counter and Rejected,
      the PO reference and date for Accepted; Partial and Counter open the new draft; an `expired`
      quotation offers *Reject* with its reason; `409` shows the refresh message (§10.5).
      *(2026-09-23, #221 — in-page dialog; Expired pre-fills «لا رد» (§10.5, owner); `deal_lost` shown as one line (rule b, owner); the PO block stays 3.3's)*
- [x] **3.2** "Previous Quotations" in the deal detail (§6.3; the sixth criterion): the deal's quotations
      by version chain, through the existing `GET /quotations?filter[deal_id]`.
      *(2026-09-23, #222 — every version, live ones included (owner); `sort=code,created_at` lays each chain out, a copy keeping its code; one page of 100 (owner))*
- [x] **3.3** Purchase orders: a list searchable by both numbers, the PO on its quotation, and the upload
      of its attachment.
      *(2026-09-23, #223 — `/purchase-orders` + sidebar on `quotation.view`, §8 names none (owner Q-A); no PO page, the order and its files live on the quotation (Q-B); search + prev/next only (Q-C); `displayDate()` draws a date-only field in UTC (Q-D))*

#### Step 4 — close the module

- [x] **4.1** Arabic manual test list, freeze to `checklist/module-10.md`, stub here, ownership row.
      *(2026-09-24, PR of this stub — 20 of 20; the six criteria ticked with the point that proves each; 61 checks, approved by the owner before the PR)*

#### قائمة الاختبار اليدوي — الوحدة 10 كاملة *(النقطة 4.1، 2026-09-24)*

> تُنفَّذ من أعلى إلى أسفل بالترتيب المكتوب، لأن كل مجموعة تستعمل ما أنشأته التي قبلها.
>
> **الأدوار المطلوبة:** *مبيعات داخلية* (صاحب الصفقة والعرض) · *مدير* (يعتمد، ونطاقه الكل) ·
> *الرئيس التنفيذي* (قراءة فقط) · *مشتريات* و*قائد فريق* (يقرآن كل العروض، `D-91`) ·
> *مبيعات خارجية* (نطاقه الخاص) · *مشرف المبيعات الخارجية* (لا صلاحية له على العروض).
>
> ⚠️ **قبل البدء:** قاعدة التطوير أُعيدت إلى بياناتك الحقيقية بلا صفقات ولا عروض، فالمجموعة «أ»
> تُنشئ ما يلزم. وإن كنت متقمّصًا حسابًا آخر فأنهِ التقمّص وسجّل الدخول من جديد.
>
> ⚠️ **على عرض الهاتف (375 بكسل)** الصفحة أعرض من الشاشة بنحو 46 بكسل، وزر القائمة يكاد يختفي.
> هذا دَين معروف (`CHECKLIST.md:905`) وستصلحه جولة الإصلاح (F-21)، فلا يُحسب عيبًا جديدًا هنا.

**أ — التحضير** *(تمهيد لما بعده، من الوحدات 5–8)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 1 | مبيعات داخلية | افتح «الطلبات / الصفقات» ← «صفقة جديدة» على أحد عملائك، واحفظ ⇒ صفقة جديدة `DL-…` بحالة «مبدئي» |
| 2 | مبيعات داخلية | افتح «عروض المورّدين» ← «عرض جديد»: مورّد، والصفقة من الخطوة 1، وعملة، وبند بسعر 250 وكمية **10**، واحفظ ⇒ العرض محفوظ |
| 3 | مبيعات داخلية | انقل الصفقة خطوة خطوة حتى «عرض المورّد» ⇒ الحالة «عرض المورّد» |
| 4 | مبيعات داخلية | أنشئ عرض سعر للصفقة من بند المورّد بكمية **2**، وأرسله للاعتماد ⇒ «بانتظار الاعتماد» |
| 5 | مدير | افتح «الاعتمادات» واعتمد العرض ⇒ حالة العرض «معتمد» |

**ب — الإرسال إلى العميل** *(`D-90`: الإرسال لا ينتظر ملف PDF؛ والقاعدة «أ»)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 6 | مبيعات داخلية | افتح العرض المعتمد ⇒ زر **«إرسال إلى العميل»** ظاهر، ولا يطلب ملف PDF |
| 7 | مبيعات داخلية | اضغط «إرسال إلى العميل» ⇒ الحالة **«مُرسَل»**، و«تاريخ الإرسال للعميل» ممتلئ |
| 8 | مبيعات داخلية | افتح الصفقة ⇒ حالتها صارت **«أُرسل عرض السعر»** |
| 9 | مبيعات داخلية | افتح عرضًا بحالة «مسودة» أو «بانتظار الاعتماد» ⇒ **لا** زر «إرسال إلى العميل» |
| 10 | مبيعات داخلية | أنشئ صفقة ثانية وعرض مورّد لها (كالخطوتين 1 و2)، **واتركها «مبدئي»**. أنشئ منها عرض سعر، واعتمده بالمدير، ثم اضغط «إرسال إلى العميل» ⇒ **يُرفض** برسالة تذكر حالة الصفقة، ويبقى العرض «معتمد» *(القاعدة «أ»)* |
| 11 | الرئيس التنفيذي | افتح عرضًا معتمدًا ⇒ **لا** زر «إرسال إلى العميل» |
| 12 | قائد فريق | افتح عرضًا معتمدًا ⇒ الزر ظاهر، لكن الضغط عليه **يُرفض برسالة «غير موجود»** ولا يتغير العرض. هذا سقف معروف: نطاق «الفريق» لا يصل إلى شيء حتى يوجد كيان فريق (`D-a`) |

**ج — رد العميل: قبول وأمر شراء** *(أمر الشراء `PO-` عند القبول، واستهلاك كمية المورّد)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 13 | مبيعات داخلية | افتح العرض المُرسَل من الخطوة 7 ⇒ زر **«تسجيل رد العميل»** ظاهر |
| 14 | مبيعات داخلية | اضغطه، اختر «مقبول»، واترك «رقم أمر الشراء لدى العميل» و«تاريخ أمر الشراء» فارغين، واضغط «تسجيل الرد» ⇒ **لا يُحفظ**، ورسالة تحت كل حقل من الحقلين |
| 15 | مبيعات داخلية | املأ الحقلين واضغط «تسجيل الرد» ⇒ الحالة **«مقبول»**، ويظهر قسم **«أمر الشراء»** برقم `PO-2026-…` ورقم العميل والتاريخ |
| 16 | مبيعات داخلية | افتح الصفقة ⇒ حالتها **ما زالت «أُرسل عرض السعر»**. القبول لا يحرّك الصفقة؛ نقلها إلى «ناجحة» يدوي (§4.4) |
| 17 | مبيعات داخلية | افتح عرض المورّد من الخطوة 2 ← «تعديل» ⇒ تحت البند: **«المسجَّل 10 · المستهلَك 2 · المتاح 8»** |
| 18 | مبيعات داخلية | في قسم «أمر الشراء» ← «أرفق ملفًا بأمر الشراء» وارفع PDF ⇒ صف باسم الملف ومعه «تنزيل» |
| 19 | مبيعات داخلية | ارفع ملفًا ثانيًا ⇒ **صفّان**، فالأمر الواحد يحمل عدة ملفات. ثم اضغط «تنزيل» ⇒ ينزل الملف نفسه |
| 20 | الرئيس التنفيذي | افتح العرض نفسه ⇒ يرى قسم «أمر الشراء» وملفاته، و**لا** حقل رفع |

**د — مقبول جزئيًا وعرض مقابل** *(معيار: نسخة كاملة تُحفظ تلقائيًا ويعدّلها الموظف · معيار: السبب إلزامي للعرض المقابل)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 21 | مبيعات داخلية ثم مدير | كرّر الخطوات 4–7 لعرض ثانٍ على الصفقة الأولى حتى يصير «مُرسَل» |
| 22 | مبيعات داخلية | «تسجيل رد العميل» ← «مقبول جزئيًا» **دون سبب** ← «تسجيل الرد» ⇒ يُقبل (الجزئي لا يحتاج سببًا، §6.3). حالة العرض «مقبول جزئيًا»، وتُفتح **مسودة جديدة «نسخة 2»** بكل البنود منسوخة |
| 23 | مبيعات داخلية | عدّل الكمية في المسودة الجديدة واحفظ ⇒ يُحفظ. ثم ارجع إلى النسخة الأولى ⇒ **لا تُعدَّل**، وحالتها باقية «مقبول جزئيًا» |
| 24 | مبيعات داخلية ثم مدير | أرسل النسخة 2 للاعتماد، واعتمدها، وأرسلها للعميل ⇒ «مُرسَل» |
| 25 | مبيعات داخلية | «تسجيل رد العميل» ← «عرض مقابل» **دون سبب** ← «تسجيل الرد» ⇒ **يُرفض**، ورسالة «السبب» مطلوب |
| 26 | مبيعات داخلية | اكتب السبب وسجّل ⇒ الحالة «عرض مقابل»، وتُفتح **«نسخة 3»** مسودةً قابلة للتعديل |

**هـ — الرفض** *(معيار: السبب إلزامي للرفض · معيار: العرض يُؤرشف، والعميل يبقى في القائمة، والصفقة تصبح خاسرة · قاعدة آخر عرض حي، Q12)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 27 | مبيعات داخلية | افتح مسودة «نسخة 3» ⇒ **لا** زر «تسجيل رد العميل». الرد يُسجَّل على «مُرسَل» أو «منتهي» فقط |
| 28 | مبيعات داخلية ثم مدير | كرّر 1–5 لصفقة ثالثة، لكن أنشئ منها **عرضين**، واعتمدهما، وأرسلهما ⇒ عرضان «مُرسَل» على صفقة واحدة |
| 29 | مبيعات داخلية | في العرض الأول: «مرفوض» **دون سبب** ⇒ **يُرفض**، ورسالة السبب المطلوب |
| 30 | مبيعات داخلية | اكتب السبب وسجّل ⇒ العرض «مرفوض»، **والصفقة لا تتغير**، لأن العرض الثاني ما زال حيًّا |
| 31 | مبيعات داخلية | ارفض العرض الثاني بسبب ⇒ العرض «مرفوض»، ويظهر سطر **«أصبحت الصفقة خاسرة.»** |
| 32 | مبيعات داخلية | افتح الصفقة الثالثة ⇒ حالتها **«خاسرة»**، وسبب الخسارة هو سبب الرفض |
| 33 | مبيعات داخلية | افتح «عروض الأسعار» ← تبويب «النشطة» ⇒ العرضان المرفوضان **غير موجودين**. ثم تبويب **«السجل»** ⇒ **موجودان** (الأرشيف) |
| 34 | مبيعات داخلية | افتح «العملاء» ⇒ عميل الصفقة الخاسرة **ما زال في القائمة** |
| 35 | مدير ثم مبيعات داخلية | ينقل المدير **الصفقة الأولى** يدويًا إلى «ناجحة». ثم «نسخة 3» تُرسَل للاعتماد، وتُعتمد، وتُرسَل، وتُرفض بسبب ⇒ العرض «مرفوض»، والصفقة **تبقى «ناجحة»** *(القاعدة «ب»)* |

**و — الانتهاء التلقائي** *(معيار: مرور «صالح حتى» دون رد ⇒ «منتهي» تلقائيًا، `J-01`)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 36 | مبيعات داخلية ثم مدير | على الصفقة الأولى، أنشئ عرضًا تاريخه و«صالح حتى» فيه **أمس**، واعتمده، وأرسله ⇒ «مُرسَل» (الإرسال لا يمنع تاريخًا فائتًا) |
| 37 | مبيعات داخلية | أنشئ عرضًا آخر «صالح حتى» فيه **غدًا**، وأرسله ⇒ «مُرسَل» |
| 38 | المالك (طرفية) | شغّل `docker compose restart scheduler` (المهمة `J-01` تعمل عند كل تشغيل)، ثم أعد تحميل الشاشة ⇒ عرض الخطوة 36 صار **«منتهي»**، وعرض الخطوة 37 **ما زال «مُرسَل»** |
| 39 | مبيعات داخلية | افتح العرض المنتهي ← «تسجيل رد العميل» ⇒ الخيار الوحيد **الرفض**، والسبب مملوء مسبقًا بـ**«لا رد»** |
| 40 | مبيعات داخلية | سجّل ⇒ الحالة «مرفوض» (الانتقال الجديد من «منتهي» إلى «مرفوض») |
| 41 | مبيعات داخلية | على عرض منتهٍ آخر (كرّر 36 و38) اضغط «نسخة جديدة» ⇒ تُنشأ مسودة جديدة يدويًا *(7 · 4.3 باقٍ للمنتهي)* |

**ز — عروض الأسعار السابقة** *(معيار: كل نسخة محفوظة عبر `parent_id` و`version`)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 42 | مبيعات داخلية | افتح الصفقة الأولى وراقب اللحظة الأولى ⇒ «جارٍ تحميل عروض أسعار الصفقة…» ثم قسم **«عروض الأسعار السابقة»** |
| 43 | مبيعات داخلية | اقرأ القسم ⇒ **كل** العروض بكل نسخها، وسلسلة عرض الخطوة 21 **متجاورة**: نسخة 1 «مقبول جزئيًا»، ونسخة 2 «عرض مقابل»، ونسخة 3 «مرفوض». ولا تختفي أي نسخة |
| 44 | مبيعات داخلية | أنشئ صفقة جديدة بلا عروض وافتحها ⇒ «لم يُنشأ أي عرض سعر لهذه الصفقة بعد.» |

**ح — قائمة أوامر الشراء والبحث** *(معيار: البحث يعمل برقم أمر الشراء الداخلي وبرقم العميل)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 45 | مبيعات داخلية | انظر القائمة الجانبية ⇒ بند **«أوامر الشراء»**. اضغطه ⇒ «جارٍ تحميل أوامر الشراء…» ثم الجدول |
| 46 | مبيعات داخلية | اقرأ الأعمدة ⇒ رقم أمر الشراء · رقم أمر الشراء لدى العميل · تاريخ أمر الشراء · عرض السعر · العميل · الإجمالي بعملته. **لا** تكلفة ولا هامش ولا مورّد |
| 47 | مبيعات داخلية | ابحث بـ`PO-2026-0001` ⇒ صفّه وحده |
| 48 | مبيعات داخلية | ابحث برقم العميل الذي كتبته في الخطوة 15 ⇒ **الصف نفسه** |
| 49 | مبيعات داخلية | ابحث بشيء لا يطابق ⇒ «لا نتائج لهذا البحث» |
| 50 | مبيعات داخلية | ابحث برقم عرض السعر `QT-…` ⇒ **لا نتائج**. هذا مقصود: العقد يقصر البحث على الرقمين، ورفضتَ توسيعه |
| 51 | مبيعات داخلية | اضغط صفًّا ⇒ يفتح **عرض السعر** وفيه قسم «أمر الشراء» (لا صفحة مستقلة للأمر) |
| 52 | مبيعات خارجية | افتح «أوامر الشراء» ⇒ **«لا توجد أوامر شراء ظاهرة لك»**. أمر زميله خارج نطاقه |
| 53 | مشتريات | افتح «أوامر الشراء» ⇒ يرى الأمر **وإن لم يكن له** (`D-91`) |
| 54 | قائد فريق | افتح «أوامر الشراء» ⇒ يرى الأمر أيضًا (`D-91`) |
| 55 | الرئيس التنفيذي | افتح «أوامر الشراء» ⇒ يرى الأمر |
| 56 | مشرف خارجي | انظر القائمة الجانبية ⇒ **لا** بند «أوامر الشراء». ثم اكتب `/purchase-orders` في العنوان ⇒ **صفحة رفض**، لا جدول فارغ |
| 57 | مدير (طرفية) | شغّل `docker compose stop php` ثم أعد تحميل «أوامر الشراء» ⇒ **حالة خطأ** مع زر إعادة محاولة. شغّل `docker compose start php` واضغط الزر ⇒ يعود الجدول |

**ط — التعديل المتزامن** *(`409`، §10.5)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 58 | مبيعات داخلية | افتح عرضًا مُرسَلًا في نافذتين. سجّل ردًّا في الأولى، ثم سجّل ردًّا في الثانية دون تحديث ⇒ رسالة **«عدّل شخص آخر عرض السعر هذا. أعد التحميل…»**، ولا يُكتب الرد الثاني |

**ي — اللغة والاتجاه والهاتف** *(كل ما سبق، مرتين)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 59 | مبيعات داخلية | بالعربية: حوار «تسجيل رد العميل»، وقسم «أمر الشراء»، و«أوامر الشراء» ⇒ **RTL**، والإجمالي محاذًى إلى نهاية السطر، والتواريخ مقروءة |
| 60 | مبيعات داخلية | بدّل إلى الإنجليزية ⇒ **LTR** في الشاشات الثلاث، **ولا نص عربي متبقٍّ**، وأسماء الحالات الأربع للرد مترجمة |
| 61 | مبيعات داخلية | على عرض الهاتف: «أوامر الشراء» ⇒ الجدول يتمرّر أفقيًا **داخل إطاره**. وحوار الرد يُملأ ويُسجَّل (مع تحذير الشريط العلوي أعلاه) |

**ما لا يمكن اختباره بعد، ويجب أن تراه لا أن يُخفى:**

1. **الإرسال مع ملف PDF** — `D-90` يرسل بلا PDF. الملف هو الوحدة 9، ويعمل عليها المطوّر الثاني (#224).
2. **كتابات قائد الفريق** (الإرسال والرد) تُرفض كلها حتى يوجد كيان فريق (`D-a`). قراءته تعمل.
3. **«منتهي بلا رد» ⇒ حالة العميل «لا رد»** (§4.5 الصف 3) — دَين مسجّل له مالكه، ولم يُبنَ هنا.
4. **استرجاع عرض مرفوض** غير مبني عمدًا. عودة العميل تعني صفقة جديدة، والصفقة الخاسرة لا تُحيا (`D-90`).
5. **ما بعد أمر الشراء** (المشتريات والتوريد) هو الوحدة 11.
6. **`J-02` لا يلحق ما فاته عند التشغيل** — دَين مسجّل، و`J-01` وحده يلحق.
