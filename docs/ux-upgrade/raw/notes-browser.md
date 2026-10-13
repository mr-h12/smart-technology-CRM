# F-42 · 2.2 browser notes (raw). Proxy :8765 → nginx :8443. Seed: 8 roles, 178 customers, 6 deals, 10 quotations.
id | screen | role | theme/locale/width | seen | cause | confirms 2.1?
B-01 | all (S5–S13) | Manager | warm/ar/1280 | document.title is "Smart Technology CRM" on every route | no document.title write (2.1 shell) | confirms
B-02 | all but /403 | Manager | warm/ar/1280 | two <h1> per page: context bar + page header, same text (deals: «الطلبات / الصفقات» twice, visible) | AppContextBar h1 + page h1 | new
B-03 | S5 deals 43, S6 deal 143, S7 quotations 223, S9 quotation 46, S13 POs 15 | Manager | warm/ar/1280 | Arabic-Indic digits in dates (٢٠٢٦/١٠/١٢) — D-70 says Western in every locale | toLocale*('ar-EG') / toLocale*(locale) without -u-nu-latn | confirms + widens (5 screens, not 2)
B-04 | landing | Manager | warm/ar/1280 | lands on «تشخيص النظام» (Ping: latency, request id) | LANDING_ROUTE → dashboard unregistered → FALLBACK home=Ping | new
B-05 | S8 builder | Manager | — | /quotations/:id/edit → /quotations/new?deal=X (history nav) shows the PREVIOUS deal (DL-0006 for DL-0005's URL); full reload shows DL-0005 | QuotationBuilderView.vue:148 reads route.query.deal once, load onMounted only (673), App.vue:96 RouterView no key → instance reused | new; a save in that state likely targets the wrong deal (not tried — would write data)
B-06 | S5 deals | Manager | warm/ar/1280 | every row carries a full «تغيير الحالة» button under the chip; ~5 rows visible at 800px height | DealStatusControl per row | new (density)
B-07 | S5 deals | Manager | warm/ar/1280 | count reads «6 صفقة» — Arabic needs «6 صفقات» (plural form) | i18n plural rule for ar | new (check other counts)
B-08 | S9 quotation | Manager | warm/ar/1280 | deal shown as raw UUID «الطلب 01a1…» | quotation payload has deal_id only | confirms Q-06
B-09 | S1–S13 | Manager | warm/ar/1280 | 1–54 interactive targets under 24px tall per page (customers 54) | — | measure which in 375 pass
B-07b | S10 customers | Manager | warm/ar/1280 | «178 عملاء» — customers uses vue-i18n's 3 forms, deals/quotations one fixed form; Arabic number agreement needs 6 (11+ → «عميلاً») | locales/ar.json deals.total / quotations.total / customers.total | new (content)
B-10 | S6 deal timeline | Manager | warm/ar/1280 | actor shown as raw UUID «01a0cfb9-…» and run together with the date (no gap): «…f409١٢‏/١٠‏/٢٠٢٦» | deal-detail-timeline-who/when adjacent spans; GET /deals/{id}/timeline returns actor_id only | new; name needs non-UI change (like F-41 1.6b owner_name), the gap is UI-only
B-11 | S6 deal page | Manager | warm/ar/1280 | native file input reads «No file chosen / Choose File» in English inside the Arabic page | <input type=file> chrome follows browser locale | new
B-12 | S6 deal page | Manager | warm/ar/1280 | «عرض سعر جديد» (the main next step) and «تغيير الحالة» are identical secondary buttons; no back link to the list (customer page has «العودة إلى العملاء») | — | new (hierarchy, consistency)
B-13 | S6 deal page | Manager | warm/ar/1280 | last-activity time shows seconds «١٢:٤٩:٠٨ ص» | toLocaleString default | new (polish)
B-08b | S9 quotation | Manager | warm/ar/1280 | the raw-UUID deal link wraps onto 2 lines in the header card | — | widens B-08
B-14 | S9 quotation | Manager | warm/ar/1280 | money and quantities print as stored strings: «16610.000 EGP», «19435.400», qty «10.000», no thousands grouping | QuotationDetailView prints API strings; D-99 fixed the PDF form (2 dp money, trimmed qty/%) but the screen was never given one | new — owner decision: apply D-99's display form on screen? (display-only; rounding a displayed amount is a business call)
B-15 | S9 quotation | Manager | warm/ar/1280 | page order: header → PO → PDF → lines → totals; the final total (the figure everyone looks for) is at the bottom after every line, not in the header | layout | new (hierarchy)
B-11b | S9 quotation (PO attach) | Manager | warm/ar/1280 | the same English native file input «No file chosen Choose File» | — | widens B-11
B-16 | S10 customers | Manager | warm/ar/1280 | every row ends in a solid red «أرشفة» (archive) button — the destructive action is the loudest element on each of 178 rows, beside a ghost «تعديل» | danger-filled button per row | new (hierarchy; DS §6.2 — confirm what DS says for destructive actions)
B-03b | S10 customers vs S5 deals | Manager | warm/ar/1280 | dates disagree between screens: customers «2026/9/23» (Western), deals «٢٠٢٦/١٠/١٢» (Arabic-Indic) | different toLocale calls per page | confirms 2.1 date-format inconsistency
B-17 | S10 customers | Manager | warm/ar/1280 | the bulk «إسناد إلى» panel with a disabled «إسناد المحدد (0)» is always shown above the table, before any row is selected | — | new (progressive disclosure)
B-18 | S13 POs vs S9 | Manager | warm/ar/1280 | currency placement differs: list «EGP 19435.400», quotation page «19435.400 EGP» | per-page formatting | new (consistency)
B-19 | S13 POs | Manager | warm/ar/1280 | pager «السابق / التالي» drawn (disabled) when there is 1 page | — | polish
B-20 | S12 approvals | Manager | warm/ar/1280 | only the empty state seen: the seed has no pending quotation; seeing rows needs a submit-for-approval write — owner's call | data | coverage gap
B-21 | text contrast, S5/S7… S9/S10/S12 | Manager | warm, mono, midnight /ar/1280 | NO text pair under 4.5:1 (3:1 large) in any theme; checker proven by a planted #eee-on-white (1.16) and #222-on-midnight (1.20) | — | negative result, agrees with D-73
B-22 | pickers (SearchCombobox) | all | warm 1.12 · mono 1.04 · midnight 1.03 | keyboard-highlighted option = surface-muted on surface, the only signal → fails SC 1.4.11 in ALL THREE themes | SearchCombobox.vue:220 bg-only active state | confirms 2.1 + widens (2.1 said mono only)
B-23 | inputs, focus ring | all | warm/mono/midnight | input border 4.53/4.40/3.96 vs its bg; focus ring 5.18/5.17/8.90 vs surface — pass | — | negative result
B-24 | S1 impersonation banner | any | warm/ar/1280 | «باسم Test Outdoor Supervisor (outdoor_supervisor)» — the raw role slug, not the role's name, inside Arabic text | ImpersonationBanner prints role slug | new (content)
B-25 | S4 /403 refused | OSup, CEO | warm/ar/1280 | refused page has no way onward (no link home/back); context-bar title becomes «لا تملك صلاحية الوصول» | ForbiddenView = PermissionDeniedState only | new (minor)
B-26 | S6/S11 out-of-scope record | OSup | warm/ar/1280 | deal: «تعذّر فتح هذه الصفقة.» (one line, no next step); customer: «تعذّر فتح هذا العميل — السجل غير متاح. راجع الرابط، أو ارجع…» — two wordings for the same 404 case | per-page ErrorState text | new (consistency)
B-27 | S5 deals | OSup (out), TL (team), Proc (asgn) | warm/ar/1280 | empty list with the honest message «لا توجد صفقات ظاهرة لك…» + full filter bar + «صفقة جديدة»; count «0 صفقة» | scope resolves to no rows (router comment) | seen OK; filter bar above an always-empty list is noise for these roles
B-28 | CEO pass | CEO | warm/ar/1280 | read-only correct: no write button on deals/deal/customers/quotation; edit + approvals → 403 | — | negative result
B-29 | S5 deals details cell | all | — | the details popover trigger is a <button> whose accessible name is the whole ≤2000-char text | DealsView details button | new (a11y minor) [F-41]
B-30 | S9 quotation | Team Leader | warm/ar/1280 | TL (quotation.view ALL) opens QT-0011: «العميل 01a0cfc2-…» — customer shown as raw UUID (TL's customer.view is team → no rows, so no name) | page falls back to customer_id | new; name needs non-UI (customer_name on the quotation payload)
B-31 | S9→S8 | Team Leader | warm/ar/1280 | draft QT-0011 offers «تعديل», «إرسال للاعتماد», «حذف»; «تعديل» opens an error page «تعذّر فتح هذه الصفقة، فلا يمكن إنشاء عرض سعر لها.» — a CREATE message on the EDIT route | QuotationDetailView.vue:119-124 gate on permission NAME (hasPermission) not scope; TL edit scope = team → no rows; builder's deal-load error text is create-only | new, MAJOR; proper fix = per-row abilities from the API (non-UI; SPA must not own permission decisions, D-67); the wrong message is UI-only. Submit/Delete not clicked (writes).
B-32 | TL pass | Team Leader | warm/ar/1280 | deals 0, customers 0 («لا عملاء» + «عميل جديد»), approvals empty, quotations 10, POs 2 | team scope → no rows (router comments, D-a) | seen; the TL's working screens are empty by design
B-33 | S8 builder | Indoor Sales | warm/ar/1280 | no total anywhere while building: §7.2 asks for "final total in distinct visual groups"; the seller sees the price only after saving and opening the quotation page | no quotation preview endpoint in OpenAPI (grep preview|calculate = 0); the SPA may not calculate (D-67) | new, MAJOR, needs non-UI (a server preview) — or the owner accepts save-then-see
B-34 | S8 builder | Indoor Sales | warm/ar/1280 | the only Save («حفظ التعديلات») is at the page bottom (y≈1238 of 1306), not sticky; with 14 lines it is far below | static footer | new (major for long quotations)
B-35 | S8 builder | Indoor Sales | warm/ar/1280 | additional-item amount input shows the stored «1000.000000» (6 dp) | input bound to the raw API string | new (same root as D-99's PDF six zeros)
B-36 | landing | Indoor Sales | warm/ar/1280 | lands on /customers — the only role whose landing exists | LANDING_ROUTE | confirms B-04 by contrast
B-37 | Outdoor Sales pass | OSales | warm/ar/1280 | owns nothing in the seed → deals 0, quotations 0; refusals as expected | seed data | coverage note
B-38 | S9 quotation (PDF section) | Procurement | warm/ar/1280 | the «ملف PDF للعميل» card renders the full page-level refusal (lock icon, «لا تملك صلاحية الوصول», "contact the admin if you think this is an error") in the middle of a page Procurement may read — a section-level refusal shown as an error | QuotationPdfPanel renders PermissionDeniedState | new (major-ish: alarming, implies a fault); its heading also joins the page outline
B-30b | S9 quotation | Procurement | warm/ar/1280 | customer as raw UUID again (Proc customer.view = asgn → no rows) | as B-30 | widens B-30 to Procurement
B-39 | Procurement pass | Procurement | warm/ar/1280 | lands on Ping; deals 0 (asgn → no rows, no «صفقة جديدة» — correct, no deal.create); quotations 10; customers 0 | — | seen
B-40 | states: loading | Manager | warm/ar/1280 | deals: spinner + «جارٍ تحميل الصفقات…» + skeleton rows, filters stay usable — good | fetch held 8 s in-page | negative result
B-41 | states: error (HTTP 500 forced in-page) | Manager | warm/ar/1280 | deals, deal, quotation, builder, customers, approvals, POs all show «حدث خطأ ما. تعذّر تحميل هذه البيانات…» + «إعادة المحاولة», role=alert — good. Detail pages (deal, quotation, builder) drop their header entirely, so the error says nothing about WHICH record failed | ErrorState replaces the whole page on detail views | mostly negative; minor context gap
B-42 | en/1280 all S5–S13 | Manager | warm/en/1280 | lang=en dir=ltr, no overflow, 0 Arabic-Indic digits, Arabic only in user data (terms text) | — | negative result
B-43 | S10 customers | Manager | en/1280 | 53 targets <24px: 28 row checkboxes 16×16 and 25 table-cell text links 16px high; rows ~57px apart so SC 2.5.8's spacing exception likely holds | — | minor
B-44 | all, 375 | Manager | warm/ar+en/375 | no page-level horizontal overflow anywhere; the quotation lines table scrolls inside its own frame (570 in 341) | — | negative result
B-45 | all, 375 | Manager | ar+en/375 | 13 focusable sidebar links sit off-screen in the closed drawer on every page (deals 19–20) | AppSidebar closed = inset-inline-start:-16rem, no inert | confirms 2.1 shell #1 on screen
B-46 | drawer, 375 | Manager | en/375 | opening the drawer leaves focus on the menu button; aside has no aria-modal; Escape closes | AppSidebar/App.vue | confirms 2.1
B-47 | S5 deals, 375 | Manager (impersonating) | warm/ar/375 | first data row starts at y=694 of 812: banner 120 + header to 243 + title/«صفقة جديدة»/5 filters; without the impersonation banner ≈ y=574 (71%) | chrome + unstacked filter form | new (major for phone users)
B-48 | S5 deals, 375 | Manager | warm/ar/375 | deal code wraps over 3 lines «DL- / 2026- / 0006»; «تسجيل الخروج» wraps to 2 lines in the header | no nowrap on codes | new (minor)
B-49 | S10 dialog | Manager | en/375 | «New customer» dialog: focus stays on the opener behind it, 137 controls behind remain tabbable, Escape from there does nothing; Cancel closes | CustomerFormModal no focus move/trap | confirms 2.1 dialogs on screen
B-50 | S3 login | — | — | NOT seen in the browser: checking it signs the owner out; the code (LoginView.vue:58,159) is the evidence | — | coverage gap

## Coverage grid (what was actually visited)
| | Mgr | CEO | TL | OSup | OSales | ISales | Proc |
|---|---|---|---|---|---|---|---|
| ar/1280 warm, all 9–11 routes | ✔ measured + S5,S6,S9,S10,S13 seen | ✔ | ✔ | ✔ | S5,S6,S7 only | ✔ + S8 seen | S5,S7,S9,S10 |
| mono + midnight (text + non-text contrast) | S5,S7,S9,S10,S12 measured | – | – | – | – | – | – |
| en/1280 | ✔ all | – | – | – | – | – | – |
| ar/375, en/375 | ✔ (overflow, focus) + S5 seen | – | – | – | – | – | – |
| loading | S5 seen | – | – | – | – | – | – |
| error (500) | S5,S6,S8,S9,S10,S12,S13 | – | – | – | – | – | – |
| refused | /403 + sections | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ PDF section |
| empty | S12 | – | S5,S10,S12 | S5,S10 | S5,S7 | – | S5,S10 |
Not visited: S3 login (would sign out), S12 with rows (needs a write), themes per role other than Manager (themes are CSS tokens, role-independent), dark-theme screenshots by eye.
B-51 | console, whole session | all roles | — | 0 JS exceptions; errors are only "Failed to load resource": 2× cert (pre-proxy-fix load), 1× 401 (signed out), 22× 404 + 2× 403. The 404s visible in the buffer are GET /customers/{id} by scoped roles (customer name lookup, see B-30), the 403s GET /quotations/{id}/pdf by Procurement (B-38). 173 older requests fell out of the buffer, so not every 404 was matched to a URL. | — | expected refusals; the customer lookups 404 on every visit by TL/Proc is the cost of B-30
