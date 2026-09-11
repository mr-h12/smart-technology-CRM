> Frozen history of Module 6, cut verbatim from `CHECKLIST.md` on 2026-09-12 (commit `f8b993c`).
> Open boxes here are stale copies — the live ones are in `CHECKLIST.md`. Do not tick here.

## Module 6 — Supplier Quotations

> As a sales employee, I want to record a supplier's price offer and attach their file, so that I can
> use it to build the customer's quotation.

**Tables** `supplier_quotations` (**nullable** `deal_id`) · `supplier_quotation_items`

**Acceptance criteria**
- [x] Offer containing a product not in the catalog → product added automatically *(Points 3.1–3.5:
      the boundary accepts a name, the use case resolves it through Catalog's published contract
      inside `DB-11`'s transaction, on both `POST` and `PATCH`. Proven end to end at both endpoints
      and at both use cases; `D-45`'s audit row is Catalog's, written by `SaveCatalogItem`.)*
- [x] Saved offer appears on the supplier page under "Linked Quotations" *(Point 4.4, as
      `GET /supplier-quotations?filter[supplier_id]=…` — **a filter on this module's list, not offers
      embedded in the supplier payload**: `OpenAPI §6.2` forbids `include` returning unrestricted
      collections, and reading Module 6's rows from inside Module 4 would be the cross-module database
      access `CLAUDE.md` forbids. Proven **in both directions** — the filtered supplier's offer is
      present and a second supplier's is absent — because a filter that returns everything passes any
      test that only asserts the wanted row is there.* ⚠️ **The supplier *page* does not exist.** The
      criterion says "appears on the supplier page"; what is closed is the API that page will call.
      The screen is frontend and Module 6 has none, so the visual half is untestable today and is
      named in the module's manual test list as such.)
- [x] Offer not linked to a deal → saves normally, available to any deal *(`D-51`. The save half has
      held since Point 2.2 — `deal_id` is nullable and no route requires it — and Point 4.4 closes the
      second half at the list: an offer with a `null` `deal_id` is listed beside a linked one, and
      `filter[deal_id]` selects only the linked one. "Available to any deal" is not consumed anywhere
      yet: nothing attaches an offer to a deal after the fact, because the customer quotation that
      would do it is Module 7.)*
- [x] File upload → type, size and **true MIME** validated, stored under a UUID name *(Point 5.4,
      closed at this module's own endpoint rather than at the validator. The rules themselves are
      `FinfoUploadValidator`'s and `UploadValidationTest` owns their 26 cases; what 5.4 proves is
      that `POST /supplier-quotations/{id}/documents` is **wired** to them and that the stored file
      comes back out. An ELF header named `offer.pdf` is refused as `unsupported_type`, plain text
      is refused, one byte over `config('files.max_size_bytes')` is refused as `too_large`, and the
      stored basename matches a UUIDv7 regex while containing no part of the caller's filename.
      The journey both ways: a clean upload is downloaded by the **CEO**, who §3.6 gives `view` and
      no `upload_attachment` — the case `D-38` and Point 5.1's `mayView` exist for — while an
      infected one is refused **to the caller who uploaded it**, because `SEC-15` gates on
      `scan_status` and not on ownership. ⚠️ **Image compression, §17's own row, is not part of this
      criterion's wording and remains unbuilt** — on the register, by the owner's decision of
      2026-09-04.)*
- [x] Shared screen — not restricted by ownership *(Point 6.6. The criterion names a **screen**, so
      it could not be closed while Module 6 was API-only; Steps 6.2–6.5 built one, and this point
      closes it at both ends.
      **At the collection, stated as the thing it claims.** The existing per-role provider asserted a
      **200** — which a list narrowed to the caller's own rows would also answer, with an empty page.
      One new data-provided case asserts the **id** instead: an offer `created()` posts **as the
      Manager** is present in every reader's list, for all six roles §3.6 grants `view`. `entered_by`
      is `DB-02`'s `created_by`, set from the authenticated actor and never from the body, so every
      other reader is looking at a row they did not enter.
      **The detail route needed nothing new**, and the point wrote a copy before measuring that:
      `SupplierQuotationReadEndpointTest::test_that_every_role_section_3_6_grants_view_to_can_read()`
      already carries it, because a `GET /{id}` for a row the caller may not see is a **404** — its
      200 cannot be the empty answer a collection's can. The duplicate was deleted inside this point.
      **At the screen**, the `view`-only CEO is asserted to still see the row after both write
      controls are refused — refusing the writes must not empty the table.
      **Verified by breaking it, twice** (both green on arrival, so the probes are the proof): a
      `where('created_by', Auth::id())` on `list()` reddened **5 of 6** readers, and the same on
      `find()` reddened **5 of 6** of the *existing* read test — the Manager passing in both,
      correctly, the row being theirs. Restored and re-verified each time.)*

⚠️ **This module is being built out of the documented delivery order, by the owner's instruction.**
`CLAUDE.md`'s Required Delivery Order reads `… 5 Deals → 6 Supplier Quotations`, and Module 5 still
has open acceptance criteria owned by a second developer. Recorded here rather than in `docs/`, and
**awaiting a `D-xx`** — no authoritative source has been reinterpreted, and nothing below assumes
Module 5 is finished.

#### Step 1 — schema *(point order approved 2026-09-02)*

- [x] **1.1** `supplier_quotations` — §7.2's fields, `DB-01`/`DB-02`'s block, `DB-09`'s indexes, and
      closing the `supplier_quotation_files.supplier_quotation_id → supplier_quotations` debt Module 0
      Point 5.1 left open, in the same migration that creates the parent it was waiting for.
      **`supplier_id` is `NOT NULL` and `deal_id` is nullable** — §4.1's entity map draws exactly one
      line into this entity, and `D-51` states the purpose of the second being optional: the offer is
      standalone and "available to any deal", which a required link would forbid.
      **`currency_id`, not §7.2's literal `currency`.** `currencies_code_unique_alive` is a *partial*
      unique index — deliberately, so an archived currency does not reserve `USD` forever — and
      PostgreSQL cannot point a foreign key at a partial unique index. `fx_rates` already made this
      choice; this table follows it rather than inventing a second convention.
      **`total_price` uses the `money()` macro, not `decimal()`** (`DB-07`, `D-68`): Laravel's
      default (8,2) truncates silently. A CHECK allows both money columns or neither and refuses one
      alone — a price with no currency cannot be converted or printed. **Whether the write path fills
      `total_price` from a typed figure or from the sum of Point 1.2's items is Step 2's question and
      is deliberately not decided by this column.**
      **No `scopeIndex()`**: §3.6 grants every role `Scope::All` ("a shared screen — not restricted by
      ownership"), so there is no scope column to index.
      ⚠️ **Four schema tests in four other modules were rewritten in this point, which is an explicit
      override of module isolation.** `SupplierSchemaMigrationTest`, `CurrencySchemaMigrationTest`,
      `DealSchemaMigrationTest` and `CustomerSchemaMigrationTest` each rolled back with
      `migrate:rollback --path=<one file>` and each went red on this point's foreign keys without its
      own `down()` changing. The cause is not a defect in this migration: `--path` does not choose
      which migrations roll back — `Migrator::rollback()` takes the last batch and uses the path only
      to resolve entries to files — so under `RefreshDatabase`, where the schema is one batch, that
      idiom runs a `down()` while every later table still stands. All four now use `migrate:reset`,
      the fix `RbacSchemaMigrationTest` and `AuditLogMigrationTest` already chose for the same
      failure mode. The alternative — reaching into each ancestor's test again for every future child
      table — is the same override paid repeatedly instead of once. Six tests still on the old idiom
      are recorded in the debt register rather than converted here.
- [x] **1.2** `supplier_quotation_items` — §7.2's one `Line items` row ("Product · **price** ·
      quantity") as three `NOT NULL` columns, `DB-01`/`DB-02`'s block, and an index on each of the two
      joins. **All three are required**, which is §4.1's argument from 1.1 rather than a new one: a
      line with no product, no price or no quantity is not the row §7.2 describes. §5.6 states the
      price half outright — "Product or price missing at the supplier → **block save**" — and §10.4
      lists "missing price" among the red inline validations. **`unit_price` uses `money()` and
      `quantity` uses `quantity()`** (`DB-07`, `D-68`); `decimal()` would be (8,2) and silently
      truncate, which the test pins by round-tripping `1234.567891`. Two CHECKs: `unit_price >= 0`
      (zero is a price — a free accessory is a real offer; a negative one is not a discount, §5.2 puts
      discount on the customer quotation as a percentage) and **`quantity > 0`, which goes beyond the
      literal wording of the approved point list** — §5.1 computes `line_total = unit_price ×
      quantity`, so a line for none of something totals nothing while still appearing on the offer.
      **No unique key on (quotation, product)**: §7.2's "+ to add more" places no limit and a quantity
      break is the ordinary reason to quote one product twice — a constraint no source asks for would
      be a rule invented here. **Nothing here decides whether the parent's `total_price` is typed or
      summed** — Step 2's open question, and a cross-row rule no CHECK can express.
      ⚠️ **`CatalogItemSchemaMigrationTest` was converted to `migrate:reset` in this point**, a second
      module-isolation override. It is the case the 1.1 debt entry predicted: `catalog_items` had no
      child until this table, and the test went red (`SQLSTATE[2BP01]`) on a `down()` that did not
      change. Converted here because it was failing, not on suspicion. Its now-unused
      `private const MIGRATION` went with it
- [x] **1.3** domain draft, `SupplierQuotationDirectoryInterface`, Eloquent directory, and `SQ-`
      allocation reusing the existing `document_sequences`. The module's first code:
      `SupplierQuotationDraft` (§7.2's fields minus `code`, which §7.2 marks "Automatic", and minus
      `entered_by`, which is `DB-02`'s `created_by` from the authenticated actor), a one-method
      contract, `EloquentSupplierQuotationDirectory`, and the `SupplierQuotation` model.
      **No `RowScope` parameter anywhere**, unlike Deals and Customers: §3.6 gives every role that
      sees this resource `Scope::All` — "a shared screen — not restricted by ownership" — so a scope
      argument would be the parameter every caller passes the same value for.
      **One method on the interface**, because `find()` is Point 2.2 and `list()` is Point 4.1.
      **`SQ-YYYY-NNNN` from `document_sequences`** with the identical single-statement
      `INSERT … ON CONFLICT … DO UPDATE … RETURNING` §4.7's `DL` uses — one round trip under one row
      lock, so two concurrent creates serialise instead of both losing to the unique index. The
      table keys on `(prefix, year)`, which a test pins by moving `DL` to 41 and watching `SQ` still
      start at 1.
      **`total_price` casts through `Precision::CAST_MONEY`** (`D-68`) — the constant's first
      caller; it had been dead since it was written. `offer_date`/`valid_until` are deliberately
      **not** cast: a PostgreSQL `date` arrives as the `YYYY-MM-DD` string §7.2 wants, and `DB-08` is
      a rule about instants, not about the day a supplier priced an offer.
      ⚠️ **Two verifiers were found not to verify, and both were rewritten before this point closed.**
      Adding `code` to the draft's writable list left every test green (the model's `#[Fillable]`
      drops it and the directory overwrites it afterwards), and deleting the money cast left every
      test green (the test fed a six-decimal string straight back to itself). Now a separate test
      asserts the draft's filter directly, and the money test sends seven decimals to get six.
      ⚠️ **`SharedContracts` was widened by one `classLike` entry in both deptrac configs** —
      `^App\Support\Database\Precision$`, not the namespace, so `StandardColumns` stays out of
      every module's reach. The first version of the module's ruleset said "No `SharedContracts`"
      and was wrong: deptrac reported the dependency as *uncovered* until `Precision` had a layer,
      and as a **violation** the moment it had one

#### Step 4 — "Linked Quotations" and the offer list *(point list approved 2026-09-04)*

> **Owner's rulings, 2026-09-04.** The default order is `-offer_date` (newest first), and the
> declared filters are `supplier_id` and `deal_id` only. **"Linked Quotations" is served by a filter
> on this module's own list, not by embedding offers in the supplier payload**: `OpenAPI §6.2`
> forbids `include` from returning "unrestricted collections", and embedding would make Suppliers
> read inside SupplierQuotations, which `CLAUDE.md` does not allow. §7.1 lists `linked_quotations`
> as an **Automatic** field of a supplier, which a derived list satisfies.

- [x] **4.1** `SupplierQuotationListCriteria` · `InvalidSupplierQuotationListQuery` ·
      `SupplierQuotationPage` — `OpenAPI §6`'s query contract in Domain, with no endpoint yet.
      **`page`/`per_page` (25, max 100), `sort` and `filter` allowlists, and a refusal for
      everything else** — §6.1 "Invalid or excessive values return `400 invalid_request`" and §6.2
      "never ignore them silently". Parsed in Domain rather than by a Form Request because a Form
      Request failure is a 422 and the contract asks for a 400; the rendering of that 400 is 4.3.
      **Two filters, each answering a criterion rather than a guess**: `supplier_id` is the build
      plan's "Linked Quotations" (§7.1's `linked_quotations`, "Automatic") and `deal_id` is `D-51`'s
      "available to any deal". No `q`, no `group_by`, no `include`, no currency or date filter —
      §6.2 makes the allowlist the point, and nothing declares those.
      **`total_price` is declared filterable by nothing and sortable by nothing**, on
      `SupplierListCriteria`'s reasoning for `color_rating`: an offer carries its own currency (§7.2,
      Point 1.1's `currency_id`) and this table has no base amount, so ordering by the number alone
      would rank an EGP total against a USD one. An allowlist that quietly offered a meaningless
      ordering would be worse than a 400.
      **`-offer_date` is written into `DEFAULT_SORT` as documentation** — §6.2 requires the default
      order to be "resource-specific and documented" and the master documentation documents none.
      ⚠️ `offer_date` is nullable and PostgreSQL sorts nulls first on a descending order; **where the
      nulls go is 4.2's**, the same division `EloquentCatalogItemDirectory` draws with `nulls last`.
      **17 tests. RED observed first: 17 failed** (no class existed).
      ⚠️ **One verifier was proven useless and rewritten before this point closed.** The refusal test
      asserted `__($key) !== $key` per locale; deleting the Arabic sentence left it **green**, because
      Laravel falls back to `fallback_locale` and returns the English one. Rewritten against
      `Translator::get($key, [], $locale, false)` — the concrete class, since the contract's `get()`
      has no fallback argument — and the same deletion then reddened with "has no ar sentence of its
      own". Flipping `DEFAULT_SORT_DESCENDING` and removing the filter allowlist each reddened one
      case too.
- [x] **4.2** `SupplierQuotationDirectoryInterface::list()` and its Eloquent implementation.
      **What 4.1 deliberately left here got decided here.** The filters and the sort allowlist were
      already checked by the criteria; the query owns two things the contract could not state:
      **`nulls last` on both directions of `offer_date`** — the column is nullable (Point 1.1: §7.2
      marks neither date required) and PostgreSQL sorts nulls **first** on a descending order, so the
      owner's `-offer_date` default would have opened a supplier's page with the offers nobody
      dated — and **`orderBy('id')` as a deterministic tiebreak**, on
      `EloquentCatalogItemDirectory`'s reasoning: equal sort keys may come back in any order, so two
      offers of one date could appear on page 1 and page 2 of the same listing, or on neither.
      **`total` is counted before the page is taken** (`OpenAPI §6.1`), and only live rows are
      listed — `SoftDeletes` on the model is `DB-01` here, not a `whereNull` written by hand. No
      scope narrows it (§3.6).
      **The list returns headers, not offers-with-lines**: a collection carrying every offer's lines
      is the "unrestricted collection" §6.2 tells `include` not to return, and the lines are
      `find()`'s.
      **The `match (true)` is PHPStan's price, not a style choice** — level 10 requires
      `orderByRaw` to take a `literal-string`, so each field/direction pair is written out and the
      unreachable default arm exists to fail loudly if `ALLOWED_SORTS` ever grows a field with no
      ordering behind it.
      **5 tests. RED observed first: 5 failed.** All four claims proven by breaking them: dropping
      `nulls last`, counting the page instead of the query, adding `withTrashed()`, and ignoring the
      supplier filter each reddened exactly one test.
      ⚠️ **PHPStan caught a docblock that looked fine**: `@param` written inline after prose on one
      line is not parsed, so the helper's array had no value type. Fixed before the point closed.
      **The plans were measured, not assumed** (50k rows, a scratch table rolled back). The
      supplier-filtered list — the "Linked Quotations" path — is a `Bitmap Heap Scan` on
      `supplier_quotations_by_supplier` over 250 rows then a top-N heapsort: 252 buffers, 0.15 ms.
      ⚠️ **The *unfiltered* list cannot use `supplier_quotations_by_offer_date`** and sorts the whole
      table: `Seq Scan` over 49,500 rows plus a top-N heapsort — 417 buffers, 3.4 ms — with the index
      present and analysed. The reason is the order itself: 1.1's index is ascending, so reading it
      backwards yields `DESC NULLS FIRST` while the default order is `DESC NULLS LAST`, and the `id`
      tiebreak is not in it either. **This is a stated ceiling, not a defect**: 3.4 ms sits far under
      `PRF-01`'s 500 ms, the screen that matters is the filtered one, and the upgrade — an index on
      `(offer_date DESC NULLS LAST, id)` — belongs to whoever measures the unfiltered list as hot,
      not to a point that has no endpoint yet.
- [x] **4.3** `ListSupplierQuotations::handle()` · `GET /api/v1/supplier-quotations` · the
      controller's `index` · `SupplierQuotationPayload::many()`/`pagination()` · the collection
      envelope (`OpenAPI §4.2`), behind `permission:supplier_quotation.view` — `view`, not `create`,
      so the CEO reads and cannot write (§3.6). Includes the refusal test every new route owes.
      **`handle()` is one delegation and no 404**: an empty result is an empty page, not a missing
      resource, so the `null`-to-§5.1 translation `one()` performs has nothing to translate here.
      The route is registered **before** `{supplierQuotation}` so a literal segment can never be
      read as an id, and it carries no `RowScope` (§3.6, "a shared screen — not restricted by
      ownership").
      **The point also wired a 400 that had been thrown into nothing since 4.1.**
      `InvalidSupplierQuotationListQuery` existed with no renderer behind it; this point added
      `ApiExceptionRenderer::invalidSupplierQuotationListQuery()`, its `bootstrap/app.php`
      registration, and the `errors.invalid_request` envelope message **both lang files were
      missing** — every other module already carried that key. The criteria stay parsed in Domain,
      not in a Form Request, because `OpenAPI §6.1`/§6.2 require `400 invalid_request` where a Form
      Request failure would be a 422.
      **15 tests. RED observed first: 15 failed — 405, because no route existed.**
      Three verifiers proven by breaking them: the route grant `view`→`create` reddened the CEO case
      (403 where 200 is required); an `items` key added to `of()` reddened the §6.2 "headers, not
      lines" case; unregistering the renderer reddened the 400 case with a **500**, which is the
      exact failure that case exists to catch. All restored.
      ⚠️ PHPStan caught the new test's `payload()` helper with no `@param` value type — the same
      class of defect 4.2 hit, found before the point closed.
      **What is *not* here:** the filters, the ordering and the full 400 matrix are 4.4; one 400 case
      is asserted here only to prove the renderer is registered at all.
- [x] **4.4** The two acceptance criteria closed at the endpoint: `filter[supplier_id]` returns that
      supplier's offers and nothing else; an offer with no deal lists normally and `filter[deal_id]`
      works; a soft-deleted offer is absent (`DB-01`); and `per_page=101`, an unknown filter and an
      unknown sort answer **400, not 422**.
      **8 tests, and every one of them passed on arrival** — the behaviours were built in 4.1–4.3, so
      there was no RED to observe and the green proved nothing by itself. ⚠️ **This is the case the
      "a verifier is not verified until it has failed" rule exists for**, and each test was instead
      proven by breaking what it covers: ignoring `filter[supplier_id]`; ignoring `filter[deal_id]`;
      `withTrashed()` on the list query; dropping the filter allowlist (**200** where 400 is
      required); widening the sort allowlist (**500**, thrown by the directory's deliberately
      unreachable `match (true)` default arm — 4.2's defensive branch, doing exactly what it was
      written for); and keying `error.details` by field instead of returning a list. Six probes, six
      reddened tests, and `git diff --stat` empty on every restore.
      **`error.details` is asserted as a shape, not only as a value** (`OpenAPI §5`): a renderer that
      returned the map form would still produce a 400 and would pass every other case in the file.
      ⚠️ PHPStan caught the `ids()` helper returning `list` where `list<string>` was declared —
      `array_column` loses the element type — so the helper now asserts the shape it claims. Third
      point in a row where PHPStan found a typing defect in a **test** helper.
      **What is not closed:** the "shared screen" criterion stays unticked. `Scope::All` is enforced
      and tested on all four routes, but the criterion names a *screen* and there is none.

#### Step 5 — §7.2's `pdf_file`, the offer's attachment *(point list approved 2026-09-04)*

> **§17 · `D-71` · `D-38` · `D-40`.** §7.2 line 657 is `pdf_file | Scan or PDF of the offer`, and
> Point 1.1's migration already recorded that this is **not a column**: `D-71` makes it the
> `supplier_quotation_files` pivot, because a polymorphic column cannot carry a foreign key.
>
> **Most of §17 is already built and parent-agnostic**, measured before the list was written: the
> pivot exists *with its parent foreign key* (added by Point 1.1 itself), `AttachmentParent::
> SupplierQuotation` exists, and `FinfoUploadValidator` (true MIME by bytes, `D-40`'s six types, the
> 30 MB ceiling, the integrity checks), `LocalStorageService` (UUIDv7 name, §17's path),
> `ScanStoredFile` and `GET /files/{file}/download` are all indifferent to which parent they serve.
> **This step adds no migration.**
>
> ⚠️ **Image compression is §17's own row and exists nowhere in the repository** — no contract in
> `Storage`, no library in `composer.json`. It is **not** in the acceptance criterion's wording
> ("type, size and true MIME validated, stored under a UUID name"), so the criterion closes without
> it. Left on the debt register by the owner's decision rather than built here, where it would mean
> Module 6 building shared §17 infrastructure that Module 5 owes equally.
>
> ⚠️ The 30 MB ceiling comes from `config/files.php`, not the database, which `CLAUDE.md`'s "limits
> are not code constants" does not allow. The config file's own comment says it moves to Module 2's
> settings table when that exists. **Pre-existing debt, not created here, and not fixed here.**

- [x] **5.1** `SupplierQuotationAttachmentPermission` (`D-38`) and the composite that replaces the
      single `AttachmentPermissionInterface` binding.
      **This point took a decision Module 5 wrote down and deferred.** `DealAttachmentPermission`'s
      docblock: "Whoever builds the second parent's permission decides then whether this class grows
      a `match` or a composite replaces it." The `match` was refused — growing Deals' class to answer
      for supplier quotations puts §3.6's rule and `SupplierQuotationDirectoryInterface` inside
      Module 5, and `CLAUDE.md` forbids that crossing more firmly than it forbids a registry.
      `ParentAwareAttachmentPermission` routes by parent; each module keeps its own answer; the map
      lives in `AppServiceProvider`, already outside every module boundary. `PurchaseOrder` and
      `Report` have no entry and are refused — `DenyAllAttachmentPermission`'s deny-by-default kept.
      **Why it is load-bearing:** `GET /files/{file}/download` carries `auth` and nothing else, so
      `mayView()` is the entire check. Before this point a supplier quotation's attachment would have
      uploaded and stored correctly and then downloaded as a 404.
      **No `RowScope`** (§3.6 grants one scope, `All`), and **`view`, not `upload_attachment`** — the
      CEO holds the first and not the second, so they read an attachment they cannot add.
      **deptrac:** `SupplierQuotations` gains `StorageContract` and `IdentityContract`, both
      justified in the config. `IdentityContract` is the entry this module went four steps without,
      because every earlier route was guarded by `permission:` middleware and there is no middleware
      behind `mayView()`.
      **RED 13 of 15.** The two that passed on arrival are the regression guards — the deal answer
      surviving and unclaimed parents still refused — and saying so is the point of naming them.
      ⚠️ **Four probes, and the fourth changed the code.** Dropping the map entry, the grant check and
      the `DB-01` existence check each reddened one test. Dropping the
      `$link->parent !== SupplierQuotation` guard reddened **nothing**: a foreign parent's id is not
      an offer's id, so `find()` already returns `null`. A branch with no observable effect is the
      dead code the waste audit names, and it was removed inside the point that created it — after
      being defended in a comment written minutes earlier. **Reasoning said "guard"; the probe said
      "dead".**
      ⚠️ An existing Storage test caught the binding change on its own
      (`assertInstanceOf(DealAttachmentPermission::class, …)`) and was repointed at the composite —
      a verifier doing its job without being asked.
- [x] **5.2** `AttachSupplierQuotationDocument` — validate, store, then a `DB-11` transaction over
      the `files` row, the pivot row and the audit entry, with the virus scan **outside** it. Its own
      audit event. No `RowScope` (§3.6).
      **`AttachDealDocument`'s shape with one parameter fewer**, and the missing parameter is the
      whole difference: §3.4 gives deals five reaches so that use case takes `array $heldScopes` and
      resolves a `DealRowScope`; §3.6 gives this resource one, `All`, so the parameter would be the
      one every caller passes the same value for. Nothing is authorised inside the use case — that
      is the route's middleware (5.3) on the way in and Point 5.1's `mayView()` on the way out, and
      a second authorisation model in a use case is what `SEC-07` exists to prevent.
      **The parent is read before the bytes are looked at:** an upload to an offer that is not there
      is a 404, not a 422 about the file (`OpenAPI §5.1`), and validating first would run §17's work
      for a request that was never going to be written.
      **No migration and no new `Storage` code** — the pivot, its parent foreign key,
      `AttachmentParent::SupplierQuotation`, `FinfoUploadValidator`, `LocalStorageService` and
      `ScanStoredFile` were all already there and already parent-agnostic.
      **11 tests. RED observed first: 11 failed.** Four probes, all reddened: validating before the
      parent check, dropping the audit row, **moving the scan inside the transaction** — which rolls
      a committed upload back on a scanner outage, the exact failure the ordering exists to prevent
      — and dropping the parent check.
      ⚠️ **A restore failed silently and two probes stacked.** `git checkout -- <file>` does nothing
      for an **untracked** file, and `|| true` swallowed it. Caught by opening the file rather than
      trusting the command; the remaining probes used a real backup copy verified with `diff -q`.
      Recorded because the rule it broke — "never assume, open it and look" — is the one that keeps
      being the useful one.
      ⚠️ PHPStan caught three typing defects in the test's database reads (`->first()->prop`, casts
      of `mixed`). **Fourth point running** where PHPStan's finding was in a test helper rather than
      in the code under test.
      ⚠️ `UploadRejectionReason::Empty` does not exist — the case is `EmptyFile`, the value is
      `empty`. Written from the shape instead of from the file, and caught on the first run.
- [x] **5.3** `POST /api/v1/supplier-quotations/{id}/documents` behind
      **`permission:supplier_quotation.upload_attachment`** — a third grant, independent of `view`
      and `create`, which §3.6 gives to five roles and **not** to the CEO. Form Request, controller,
      payload, 201, and the refusal test every new route owes.
      **A third grant, and unlike Module 5 it did not have to be borrowed.** §3.4 seeds no "attach
      document" row for deals, so `POST /deals/{id}/documents` carries `deal.edit` — the closest
      documented permission. §3.6 *does* seed `upload_attachment`, so this route carries it, and the
      CEO — `view` as `All`, no cell under `upload_attachment` — is refused where they are served by
      Point 2.3's read. That 403 is the assertion that proves the grant is distinct rather than
      decorative, and the probe below is what proved the assertion.
      **No `mimes:` and no `max:` in the Form Request**, matching `AttachDealDocumentRequest` and for
      its stated reasons: `mimes:` reads the browser-supplied extension, which is the exact signal
      §17 says a spoofed `.pdf` controls, and `max:` would be a second ceiling beside
      `config('files.max_size_bytes')`. One check, and it is the one that reads the bytes.
      **No migration, no lang key, no `Storage` code, no deptrac config change** — 5.1 already
      granted `StorageContract` and `IdentityContract`, and nothing here crosses a new boundary.
      **15 tests. RED observed first: 15 failed.** The two 404 cases reddened on
      `error.code`, not on the status: a route that does not exist answers 404 as well, so asserting
      the envelope's code is what separates "not found because the offer is absent" from "not found
      because the endpoint was never built".
      **Four probes, all reddened, all restored and each restore verified with `diff -q`:** swapping
      the middleware to `supplier_quotation.view` (CEO 403 + `SEC-09` withdrawal red), removing the
      middleware entirely (three authorisation tests red), dropping the `201` (eight red), and making
      the payload emit a `storage_path` (the leak test red — which is what proves
      `assertJsonMissingPath` can fail at all).
      ⚠️ **`git checkout -- routes/api.php` restored to `HEAD`, not to the pre-probe state**, and so
      deleted this point's own uncommitted route along with the probe. Caught by grepping the file
      afterwards rather than by trusting the command, and the route was re-applied. This is the same
      family as 5.2's untracked-file restore failure and the same lesson: **the restore is a step to
      verify, not a step to assume.**
      **Deliberate simplification, ceiling stated:** no `attributes()` and no `attributes` lang
      block. This module has none — `SaveSupplierQuotationRequest` ships 422s naming `supplier_id`
      and `code` untranslated — so translating one field would leave the other seven. **Ceiling:**
      the Arabic 422 for a missing file reads `document` in Latin script. Registered below.
- [x] **5.4** The acceptance criterion closed at the endpoint: type, size, **true MIME**, UUID name,
      and the full journey upload ⇒ download — clean is downloadable, infected is not, not even by
      the caller who uploaded it.
      **Seven tests, and all seven passed on arrival.** That is the honest and expected result for a
      point that closes a criterion over code Points 5.1 and 5.2 already built, and it means the
      tests proved nothing by going green. **The whole verification is the four probes**, each of
      which reddened and each of whose restores was checked with `diff -q`:
      bypassing `FinfoUploadValidator` with a hard-coded `AllowedFileType::Pdf` (the type, MIME and
      size cases red); storing under the source filename instead of `Str::uuid7()` (the UUID case
      red, among twelve); **gating `mayView` on `upload_attachment` instead of `view` (exactly one
      test red — the CEO's download)**; and feeding the infected-journey case a clean PDF (exactly
      one red). The third is the sharpest evidence in this step: it isolates Point 5.1's single most
      load-bearing decision to a single assertion.
      **No `app/` change at all** — `git status` on the point is one modified test file. The three
      cross-module probes (`LocalStorageService`, and the two in this module) were reverted from
      backups before any commit.
      ⚠️ **Two more fixture copies created**, and they are registered rather than removed:
      `pdfBytesWithEicarSignature()` now stands in **3** test files and `executable()` in **3**.
      Removing them needs the shared test-fixture location that is still unapproved.

#### Step 6 — the frontend *(point list approved 2026-09-05)*

> **Why this step exists at all.** Every module through 4 ships a screen — `customers`, `suppliers`,
> `catalog`, `users`, `roles`, `settings`, `currencies`, `limits`, `lists` all have one under
> `resources/js/pages`. **Module 6 was the first to ship API-only**, which is why the owner could not
> see any of it, and why "shared screen — not restricted by ownership" is still unticked: it names a
> screen, and an API test cannot close it.
>
> ⚠️ **`§13` and `Design_System_EN.md` have not been read for this screen yet.** Point 6.2 begins by
> reading them, and if they describe a layout other than the one assumed here, **they win** and this
> list is corrected rather than followed.
>
> ⚠️ **There is no deals screen** (`resources/js/pages/deals` does not exist), so 6.2's `deal_id`
> filter has no list to draw from. A raw identifier field is the stated, ugly ceiling until Module 5
> builds one.

- [x] **6.1** `services/supplier-quotations.ts` — the typed client for the five routes, with its spec.
      **The query shape is the server's, exactly:** `ALLOWED_FILTERS` is `['supplier_id', 'deal_id']`
      and `ALLOWED_SORTS` is `['offer_date', 'created_at']`, both closed, so **there is no `q`** —
      this list declares no search and offering one would produce a runtime 400 rather than a missing
      feature. An unset filter is omitted, never sent empty.
      **`total_price` is `string`, not `number`.** `DB-07` forbids floating point near money and the
      server sends `4500.000000`; parsing it would reintroduce the float `D-68` was decided to avoid.
      Nothing in the client sums anything — the total is entered (owner, 2026-09-02).
      **No `code` field on the draft and no delete call:** §7.2 marks the code "Automatic" and the
      server answers a supplied one with 422, and §3.6 seeds no `delete` grant.
      **8 tests. RED first** — the module did not exist, so the suite failed to import.
      ⚠️ **A probe found a real hole and the point closed it.** Renaming the upload's form field from
      `document` to `file` — which would 422 every upload *and* make `ApiExceptionRenderer`'s
      hard-coded `'field' => 'document'` point at a control that does not exist — reddened **nothing**.
      The assertion on the `FormData` key was missing and was added; the same probe now fails, as do
      probes that send empty filters (2 red) and that add a `q` (2 red).
      ⚠️ **Measured, not assumed:** the PHP suite grew by two on a frontend-only change, because
      `LogicalPropertiesTest` data-provides over every `.vue`/`.php`/`.ts` file and the two new files
      became two new data sets. Benign, and checked rather than shrugged at.
      ⚠️ **`NoHardCodedTextTest` pins an explicit list of `.vue` filenames.** Point 6.2 must add its
      component to that list, having first run the scan against it — the test's own stated terms.
- [x] **6.2** `SupplierQuotationsView.vue` — the list, its route and its nav entry behind
      `supplier_quotation.view`, with the two filters, the `-offer_date` default, and the loading,
      empty and error states.
      **The sources were read first, and they carry a conflict.** §8's *Screens by Role* lists
      Supplier Quotations for the Manager, Team Leader, Outdoor Sales, Indoor Sales and Procurement —
      and **not for the CEO**, while §3.6 grants the CEO `supplier_quotation.view` as `All`. This is
      the same conflict the Suppliers and Catalog screens hit, and it takes **the same answer the
      owner already gave on 2026-08-31**: the route and the nav item both follow the permission
      matrix, because keying the menu on §8 would leave a screen a person may open with no way to
      reach it. Followed as precedent rather than decided again; still **awaiting a `D-xx`**.
      **Design System §5.2 and §6.5 shape the rest:** server-side filters and sort, server
      pagination, right-aligned monetary values with tabular numerals, `text-end` rather than
      `text-right` so RTL mirrors, sticky header, column priorities folding the secondary columns
      first, and distinct loading / empty / error / **refused** states.
      **Two filters and no search**, because `ALLOWED_FILTERS` is `['supplier_id', 'deal_id']` and
      this list declares none — a search box would be a control that 400s.
      **8 tests. RED first: the import failed, then 8 failed.**
      ⚠️ **Two stated ceilings, both measured, neither invented here.**
      1. The supplier name comes from one `listSuppliers({ perPage: 100 })` call — `MAX_PER_PAGE` —
         so a supplier past the hundredth shows as an identifier. The join is on this side of the
         wire because `CLAUDE.md` forbids Module 6 reading Module 4's tables.
      2. **The currency is not shown at all.** An offer carries `currency_id`, and
         `CurrencyController::payload()` publishes `code`, `rounding_unit`, `rounding_enabled` and
         `is_base` — **no `id`**, read from the source rather than assumed — so nothing in the SPA
         can turn one into the other. A bare figure is the honest option; inventing a currency
         beside it is not. Registered below against Module 2's payload.
      ⚠️ **Three defects of mine, all caught by a gate rather than by eye.** `vue-tsc` rejected an
      `AuthenticatedUser` fixture twice (`role` is an object, not a string; `is_active` and
      `unconditional_access` are required) — the runtime tests passed with the wrong shape because
      the component never reads those fields. And **one `data-testid` was used twice**, on the header
      count and on a row cell, so `find()` returned the header and the money assertion read
      "1 offers". Renamed to `supplier-quotations-count`.
      ⚠️ **An assertion that could not fail.** `expect(wrapper.text()).not.toContain('s1')` was
      meaningless: the page renders "Supplier Quotation**s**" followed by "**1** offers". Replaced
      with an assertion on the named cell.
      **Three probes, all reddened and restored:** a 403 drawn as an empty list (1 red), the total
      rendered through `Number()` (1 red), and the nav item naming a permission its route does not —
      which `navigation.spec.ts` catches with a generated case per item, verified by running it
      rather than by trusting the docblock that claimed it.
      **`NoHardCodedTextTest` passed the scan on the component first**, and only its pinned filename
      list needed the entry — the test's own stated condition for adding one.
- [x] **6.3** `SupplierQuotationFormModal.vue` — §7.2's header fields, create and edit in one
      dialog, wired into the list screen behind `supplier_quotation.create` (the grant the route
      carries on **both** the POST and the PATCH), with the CEO as §3.6's documented negative case.
      **Five fields:** `supplier_id` (required, a select over the list screen's own suppliers,
      handed down as a prop rather than fetched a second time), `deal_id` (a raw identifier —
      the same stated ceiling the filter carries, there being no deals screen), `offer_date`,
      `valid_until`, `notes`. **No `code`:** §7.2 marks it Automatic and
      `SaveSupplierQuotationRequest` answers a supplied one with `prohibited`. **No `items`:** 6.4's.
      **7 tests. RED first: 7 failed, 8 passed** — then 15 passed.
      ⚠️ **The total and its currency are NOT in this form, by owner's ruling of 2026-09-05.**
      They are one pair (Point 1.1's `CHECK`, the mutual `required_with`) and `currency_id` is a
      UUID the SPA cannot obtain **and cannot even ask for** — two blockers, both measured, both on
      the debt register above. The dialog says so to the reader in `form.totalUnavailable` rather
      than letting a missing total read as a zero one, and — the part that makes it safe — **an edit
      names neither key**, so `SupplierQuotationDraft::only()`'s `array_key_exists` leaves both
      columns exactly as they were. A `null` there would erase a total this form cannot show, and a
      test pins the absence of both keys in the PATCH body.
      ⚠️ **One defect of mine, caught by a gate rather than by eye.** `vue-tsc` rejected
      `view.get(…).exists()` — `get()` throws when the element is missing and its wrapper has
      `exists` omitted from the type, so the assertion could not fail. Replaced with an assertion on
      the rendered sentence. `vitest` had passed it.
      **Three probes, all reddened and restored** (restore verified with `git status`, not only
      against the backup copies): ungating the create button so the CEO sees it (**1 red**), sending
      `total_price: null` in the draft (**2 red** — the erasure guard and the create-body assertion),
      and planting a hard-coded heading in the new component, which `NoHardCodedTextTest` named by
      path (**1 red**). The third is also the test's own stated condition for pinning the filename:
      the scan was run against the component before its name was added to the list.
      **Waste audit:** all 13 new lang keys render (`grep -ro` per key, 1–2 hits each); `dealPlaceholder`
      and `action.{cancel,save,saving,edit}` were reused rather than re-added; every new symbol and
      `data-testid` has a caller. One finding — a fifth copy of the form-modal shape — registered
      above rather than removed, because the fix touches four components outside Module 6.
- [x] **6.4** the line editor — §7.2's `Line items` row, "Product · **price** · quantity (+ to add
      more)", inside `SupplierQuotationFormModal.vue`.
      **`D-22` is made structural rather than validated.** One control per line chooses a catalog
      item **or** "type a name instead", so only the chosen key is ever sent and a line carrying
      both — which `SaveSupplierQuotationRequest` answers with `prohibits` — cannot be built here.
      **The picker offers active items only**, §10.4 read from the source: a deactivated product is
      "**Hidden** from selection lists" for a new quotation while staying functional on an open one.
      `unit_price` and `quantity` stay **strings** end to end (`DB-07`, `D-68`): `inputmode="decimal"`
      on a text input rather than `type="number"`, which would re-format `1500.000000`.
      **8 tests. RED first: 7 failed, 15 passed** — then 23 passed.
      ⚠️ **The dangerous case has its own test, and its own probe.** An edit's lines come from
      `GET /supplier-quotations/{id}`; the list summary has none, because
      `SupplierQuotationPayload::many()` calls `of()` and only `detail()` carries `items`. `items` is
      three-valued on a `PATCH` — absent leaves the lines alone, `[]` clears them — so a **failed**
      detail read must not become an empty editor submitted as `[]`. `linesState` is
      `ready`/`loading`/`unavailable`, the editor is not drawn at all in the third, and `draft()`
      omits the key. Probe 1 removed exactly that guard and the test reddened.
      ⚠️ **Three existing 6.3 assertions were tightened, not loosened.** Opening the dialog now makes
      a catalog read and an edit makes a detail read, so two tests that counted *every* fetch were
      counting the wrong thing. They now assert what they always meant: `writes()` returns the
      methods of the non-GET calls (`[]` for a refused create, `['PATCH']` for a save) and
      `listReads()` counts only calls ending in `/supplier-quotations`. The third now expects
      `items: []` in a create body, which is correct — `forCreate()` folds absent and `[]` together.
      **Three probes, all reddened and restored** (`diff -q` against a pre-probe copy, byte-identical
      each time — `git diff` cannot verify this while the point is uncommitted): dropping the
      `linesState` guard so `items` is always sent (**1 red**), sending both product keys on a line
      (**2 red**), and dropping §10.4's `is_active` filter from the picker (**1 red**).
      **Waste audit — one finding, created by this point and removed inside it.** Four
      `data-testid`s nothing queried: `-lines-none`, `-lines-loading`, `-product-error`,
      `-quantity-error`, plus a redundant hook on the `<fieldset>`. The fieldset's was deleted;
      the other four are now asserted — the line-refusal test names all three `items.0.*` fields
      instead of one, and a new test covers the editor's own empty and loading states, which the
      Module Completion Checklist requires and which exist nowhere else. All 11 new lang keys render
      exactly once; no `catalogLabel` equivalent existed anywhere else in the SPA (`grep -rn
      "service_type ??"` returns one hit, this one).
      ⚠️ **Stated ceiling:** `CatalogItemListCriteria::MAX_PER_PAGE` is 100, so an item past the
      hundredth is absent from the picker. Typing its **name** still resolves to it rather than
      duplicating it — `ProvisionCatalogProduct::productIdFor()` looks the name up before creating —
      so the ceiling costs convenience, not correctness. There is no search-as-you-type here; the
      catalog list declares a `q` and this editor does not use it.
- [x] **6.5** the attachment — §7.2's `pdf_file` row, "Scan or PDF of the offer", as a panel in
      `SupplierQuotationFormModal.vue`.
      **The upload is behind §3.6's third grant**, `supplier_quotation.upload_attachment`, and
      **not** `create`. `PermissionMatrix` gives both to the same five roles today, which is a fact
      about the seed and not about the system: RBAC is database-backed and dynamic (`SEC-07`) and
      §3.11's role screen can revoke one row while the other stands — which is exactly what happened
      to a live role on 2026-08-31. The negative case is therefore a fixture holding `create`
      **without** the third grant, not the CEO, and a probe that re-keyed the control on `create`
      reddened it.
      **The download is a fetch, never a link.** `GET /files/{id}/download` is Storage's one route
      (`grep -c "/files" routes/api.php` → **1**), it authorises through the parent (`D-38`), and the
      credential is an `Authorization` header (`D-74`) — which a browser navigation does not send, so
      an `<a href>` to it is a 401. New: `apiDownload()` in `api.ts` and `downloadFile()` in
      `services/files.ts`, the SPA's first file download. The bearer block was **extracted** into
      `headersFor()` rather than copied — `grep -n 'Bearer '` finds one line.
      **A non-clean file gets no control at all.** `DownloadFile::forActor()` refuses anything
      `! isScannedClean()` **before** it looks at permission (`SEC-15`) and answers 404, so a button
      on a `pending` or `infected` row could only ever fail. Both states render a word beside their
      colour (§6.4).
      **11 tests (8 screen + 3 transport). RED first: 7 of 8 screen tests failed, 24 passed** — then
      31 passed; the transport suite was **green on arrival**, so its three probes are its
      verification.
      ⚠️ **Stated ceiling, owner's ruling of 2026-09-05: the panel lists only what was attached in
      this dialog.** Registered below.
      ⚠️ **Upload needs a saved offer.** The route is `/supplier-quotations/{id}/documents`, so a
      create has no id to post to; the panel says "save the offer first" instead of drawing a control
      that cannot work.
      ⚠️ **One defect of mine, caught by `vue-tsc` and not by vitest** — a `vi.fn(async () => …)`
      declares no parameters, so `mock.calls` types as `[][]` and `calls[0]?.[0]` is an error rather
      than a value. Declaring the parameters is the fix. **And one probe that lied:** removing the
      `finally` from `downloadFile` left `try {` with no handler — a syntax error — so the suite
      errored and the grep printed **nothing**, which reads exactly like a pass. Re-run as a
      well-formed leak, it reddened properly. That is trap 6 in the handoff, hit deliberately.
      **Six probes, all reddened and restored** (`diff -q` against a pre-probe copy): the upload
      keyed on `create` (**1 red**), a download offered for any scan status (**2 red**), the file
      sent under the wrong field name (**2 red**), no bearer on the download (**1 red**), the object
      URL not revoked (**1 red**), and a 404 accepted as a file (**1 red**).
      **Waste audit — nothing found, and here is how it was asked.** Every new symbol has a caller;
      all 11 new lang keys render exactly once (`attachmentForbidden`/`Rejected` twice, upload and
      download); all 8 new `data-testid`s are queried by the spec; the bearer header exists once in
      the tree; `createObjectURL` appears in exactly one non-spec file. `+2` PHP tests
      (2226 → 2228) is `LogicalPropertiesTest`'s per-file provider over the two new `.ts` files —
      measured at 131 → 133, not inferred.
- [x] **6.6** tick **"shared screen — not restricted by ownership"** and publish the module's full
      manual test list.
      **The criterion was not ticked on the strength of the screen existing.** The per-role provider
      already in `SupplierQuotationListEndpointTest` asserted a **200**, which an owner-scoped list
      would also answer — with an empty page. Two new data-provided cases assert the **id** of an
      offer `created()` posts **as the Manager**: present in every reader's list, and openable on the
      detail route, for all six roles §3.6 grants `view`. On the screen, the `view`-only CEO is
      asserted to still see the row after both write controls are refused.
      **7 tests (6 backend + 1 screen assertion), green on arrival — so two probes are the proof.**
      `where('created_by', Auth::id())` on `list()` reddened 5 of 6 readers, and the same on `find()`
      reddened 5 of 6 of the **existing** read test; the Manager passed in both, correctly, the row
      being theirs. Both restored and re-verified.
      ⚠️ **Waste this point created and removed inside it:** a second data-provided case asserting
      the detail route, written before checking whether the claim was already made. It was — in the
      read endpoint's own suite, and for a reason that does not apply to a collection. Six test cases
      deleted, and the surviving docblock now says where the other half lives instead of proving it
      twice.
      **The module's full manual test list is below** — 56 checks in Arabic, grouped by screen in
      walking order, each naming the role it needs, with all five acceptance criteria named in place
      and eight things that cannot be tested yet written out rather than skipped. It replaces the
      pre-Step-5 list that lived only in the conversation and the API-client addendum above: there is
      a screen now, so what needed Postman is now clicked.
      ⚠️ **A gate of mine was run wrong, and the wrong result is worth recording.** A full
      `php artisan test` was started in the background on `main` and then a probe mutated a source
      file **while it ran**; the run reported `4 failed` from a tree that never existed. It was
      re-run on the restored tree rather than explained away. A long suite and a probe must not share
      a working directory.

#### Step 3 — `D-22`'s automatic product add *(point list approved 2026-09-03)*

> **Owner's rulings, 2026-09-03.** A line names a product by `catalog_item_id` **or** by
> `product_name`, exactly one; and a name already present is the **same product**, reused rather
> than added twice. The owner also described the screen this serves: a searchable product dropdown
> that tells the user "this is a new product and it will be saved", fills the rest of the product in
> automatically, and shows it in the list next time. That screen is **frontend** and Module 6 has
> none yet — these points build the backend that makes it possible, and the dropdown itself is
> already served by the existing `GET /catalog-items?q=`.

- [x] **3.1** `CatalogProductProvisionerInterface` — Catalog publishes `D-22` as one method, and
      `deptrac` opens exactly that one class to Module 6.
      **The rule belongs to Catalog because the product is added to the catalog**, and Module 6 is
      where a missing product is discovered (§7.2's line). `deptrac.modules.yaml` grants
      `SupplierQuotations` three layers and Catalog is not among them, which is `CLAUDE.md`'s
      "cross-module work goes through interfaces or domain events" made mechanical.
      **A name in, an id out.** The exposing collector is a `classLike` on the one interface —
      `Precision`'s precedent, not `AuditContract`'s directory split, which would have handed Module
      6 the whole of Catalog's domain. That stays narrow only while the signature is primitives: a
      `CatalogItemSummary` would drag `Domain\Listing` across with it.
      **The create delegates to `SaveCatalogItem`** rather than writing beside it. `AUD-01` names
      create explicitly and `D-45`'s stated mitigation for opening the catalog to every employee is
      "every edit is written to the audit log"; a second write path would spend exactly what `D-45`
      bought. Proven by breaking it — creating through the directory instead reddened the audit test.
      **`lower(name) = lower(?)`, not `ILIKE`**: the caller's name is a literal, and `ILIKE` reads
      `%` and `_` in it as wildcards, so a product called "50% glycol" would match rows it is not.
      **Live means not soft-deleted (`DB-01`); product means §7.3's `kind`; a *deactivated* product
      is still reused** — `is_active` is not an archive, and §10.4 (`D-37`) gives a deactivated item
      a documented life on an open quotation, where a second row of the same name would have none.
      ⚠️ **The narrow layer collided with Catalog's own collector, and the tool said so.** `Catalog`
      collects `app/Modules/Catalog/.*`, so the interface sat in two layers and
      `ProvisionCatalogProduct` depending on the interface it implements was reported as *Catalog
      must not depend on Catalog*. `--clear-cache` did not move it. Fixed by making `Catalog` a
      `bool` collector that excludes the published class — the same disjointness Identity, Audit,
      Storage and Customers get for free from their `(Domain|Application)` split, expressed for a
      one-class contract. **The boundary was then proven**: a throwaway class in Module 6 importing
      `CatalogItemDirectoryInterface` produced a violation, and removing it returned to zero.
      **8 tests (29 assertions). RED observed by removing the production code: 8 failed.**
- [x] **3.2** An index on `catalog_items` for 3.1's case-insensitive name lookup, with a tested
      `down()` (`DEV-03`).
      ⚠️ **The citation in this line was wrong and is not used.** `DB-09` names "customer · owner ·
      deal status · dates · entity codes" and a product name is none of them — Point 1.2's migration
      argued exactly that when it left the table unindexed. The rule that does cover it is
      `Coding_Standards_EN.md` §7: "Add indexes for each new join, permission scope, filter, sort,
      and **common lookup** … verify them with realistic query plans." Point 3.1 made it a common
      lookup: `findProductIdByName()` runs once per line of every supplier-quotation save (`D-22`,
      Points 3.4 and 3.5), inside the write transaction `PRF-01` caps at 500 ms.
      **`CREATE INDEX catalog_items_by_lower_name ON catalog_items (lower(name), id) WHERE
      deleted_at IS NULL`** — functional because a btree over `name` cannot answer `lower(name) = ?`,
      and `DB::statement` because Blueprint has no functional-index API (`customers_by_owner`'s
      precedent).
      ⚠️ **`id` is in it because the plan said so, and the obvious index was useless.** Point 3.1
      ends the lookup with `orderBy('id')` and takes one row, so the query is `ORDER BY id ASC LIMIT
      1` — and `catalog_items_pkey` wins that, returning the first match in key order with no sort
      while filtering the whole table. Measured on 50k rows **before** the migration was written:
      `(lower(name))` alone and `(lower(name), kind)` both planned as `Index Scan using ..._pkey`,
      15,469 rows removed by filter, 15,521 buffers, 3.6 ms — indistinguishable from having no index
      at all. With `id` as the second column: `Index Scan using ..._by_lower_name`, 4 buffers,
      0.012 ms. **`kind` stays out**: a two-value `Filter` on rows the name has already found, and
      measured to change nothing.
      **Partial on `WHERE deleted_at IS NULL`** because `SoftDeletes` puts that predicate in every
      one of these queries (`DB-01`). **Not `CONCURRENTLY`** — a migration runs inside a transaction
      and PostgreSQL refuses it there; the ceiling is written into the migration.
      **2 tests (9 assertions). RED observed before the migration existed: 2 failed**, the plan test
      naming `catalog_items_pkey`. Both verifiers then proven by breaking them: removing `id`
      reddened the shape test, misspelling the index reddened both, and misspelling it in `down()`
      reddened the `migrate:reset` test at that line — so `DEV-03` is exercised, not assumed.
      **A stated ceiling:** the plan test sets `enable_seqscan = off` on a `RefreshDatabase` table of
      a few rows, so it proves the index is *reachable* by the lookup, not that production's planner
      picks it. The realistic-volume plan is the 50k measurement above, run by hand. Removing `id`
      left that test green — the shape test is what holds that half.
- [x] **3.3** The boundary: a line accepts `product_name` as an alternative to `catalog_item_id`,
      exactly one required, `exists` unchanged for an id that is sent.
      **`required_without` on both sides, `prohibits` on the id.** One of the two is mandatory and
      the pair is refused — an id and a name can disagree, and neither §7.2 nor `D-22` says which
      would win, so the boundary refuses rather than choose. The `*` in both parameters resolves to
      the line's own index because `Prohibits` and `RequiredWithout` are both in Laravel's
      `$dependentRules`; that was read in `Validator.php`, not assumed.
      **The name's rules are the catalog column's** (Module 4 Point 1.2): `max:255` for
      `varchar(255)` and `regex:/\S/` for `CHECK (name IS NULL OR btrim(name) <> '')`, mirrored so a
      constraint violation never reaches the caller as a 500. `SaveCatalogItemRequest`'s precedent.
      **Whitespace is the framework's, and is not re-implemented.** Laravel's global `TrimStrings`
      runs before validation and `TransformsRequest::cleanValue()` recurses into arrays, so a line's
      `product_name` is trimmed like any other field — both read in `vendor/` this session. A second
      trim in `prepareForValidation()` would be the duplicate the waste audit names. The reliance is
      load-bearing for 3.4 (" Copper " and "Copper" must be one product), so it is pinned by a test
      that reddens when the middleware is removed.
      **`SupplierQuotationDraft::ITEM_WRITABLE` gains the name**, and the draft's filter still drops
      everything else.
      **6 tests. RED observed first: 5 failed** (the trim test was green before and after — it pins
      framework behaviour this point relies on). All four verifiers then proven by breaking them:
      dropping `prohibits`, dropping the name's `required_without`, dropping the name from
      `ITEM_WRITABLE`, and removing `TrimStrings` from `bootstrap/app.php` each reddened exactly one.
      ⚠️ **A name-only line 500s until Point 3.4 lands** — measured, not predicted:
      `SQLSTATE[42703] … column "product_name" of relation "supplier_quotation_items" does not
      exist`. The boundary now accepts a payload the write path cannot serve, because resolving the
      name into a product is 3.4's transaction. **This branch must not merge before 3.4.** No test
      pins that state: it is a defect to close, not behaviour to record.
- [x] **3.4** `CreateSupplierQuotation` resolves each line's product inside its existing transaction
      (`DB-11`).
      **Inside the transaction, and that is the whole design.** A product added for line one must not
      outlive an offer that line two destroyed, which is exactly what `DB-11` is for. Catalog's own
      writer opens a nested transaction — a savepoint in PostgreSQL, not a second commit — so the
      outer rollback still takes it. **Proven by moving the call outside the transaction**: the
      rollback test reddened with "DB-11: the added product outlived the offer".
      ⚠️ **That test had been passing for the wrong reason** before this point: the write threw on
      the missing `product_name` column before any product could be added, so all its counts were
      zero for an unrelated reason. It only became a real check once the feature existed — recorded
      here because the same shape ("green, but not because the thing works") is easy to inherit.
      **The name is replaced, not kept beside the id**: `catalog_item_id` is the column, and a
      leftover `product_name` reaches an insert with no such column. Proven by removing the `unset`:
      two tests reddened.
      **The audit records the resolved id.** By the time `AUD-02`'s new values are written the name
      has become an id, and a row naming a product the reader cannot resolve answers "what was
      created?" with a string.
      **`SupplierQuotationDraft::withItems()`** is how the answer comes back — the draft stays a data
      holder and the catalog write stays in the use case, where the transaction is. Point 3.5 is its
      second caller.
      **5 tests. RED observed first: 4 failed**, the fifth being the rollback test described above.
      ⚠️ **The `PATCH` half is still open**: `UpdateSupplierQuotation` does not resolve its
      replacement set until Point 3.5, so a name sent there still reaches the insert and PostgreSQL
      answers `42703`. The module's acceptance criterion "offer containing a product not in the
      catalog → product added automatically" is therefore **left unticked** until 3.5, even though
      the create path closes it end to end (`POST` answers 201 and the catalog gains the product).
- [x] **3.5** `UpdateSupplierQuotation`'s replacement set does the same.
      **The loop was extracted, not copied.** Point 3.4 wrote it as a private method of the create,
      which was right while there was one caller; this point is the second, so it became
      `ResolveLineProducts` — one class, two callers, one place where "what is a line's product"
      is decided. A second copy would be the duplicate the waste audit names: correct in both
      places, wrong the moment one changes.
      **Inside the transaction, proven the same way**: moving the call out of
      `UpdateSupplierQuotation`'s transaction reddened the rollback test with "DB-11: the added
      product outlived the edit". As on the create, that test had been green beforehand for an
      unrelated reason (the write threw on the missing column), so it only became a real check here.
      **After the not-found check, and the comment says why that is thrift rather than
      correctness** — a `PATCH` at an absent offer throws inside the transaction, so the rollback
      would take the catalog row anyway; the order only avoids writing one for a request already on
      its way to a 404. The first draft of that comment claimed the placement was what prevented it,
      which was false, and it was corrected before commit.
      **`items` stays three-valued** (owner, 2026-09-02): absent means untouched, so the resolution
      is skipped entirely rather than run over an empty set.
      **3 tests. RED observed first: 2 failed**, the third being the rollback test above.

#### Step 2 — the write path and one read *(point list approved 2026-09-02)*

> **Owner's ruling, 2026-09-02 — `total_price` is entered by the user.** §7.2 lists it as a field of
> its own, which permits it to differ from the sum of the lines; the owner chose that reading over
> "always computed" and over "typed with a warning". Nothing in this module computes it, and
> `CreateSupplierQuotationTest` submits a total that contradicts its lines to keep it that way.
> **Awaiting a `D-xx`**; `docs/` is untouched.
>
> The four points below replace the three the module's shape sketched. 2.1 as sketched was the
> largest point in the module — transaction, audit, lines, Form Request, route, permission and
> `Idempotency-Key` in one turn — which is not "the smallest unit that can be verified on its own".
> The split puts the layers below HTTP in 2.1 and the HTTP surface in 2.2.

- [x] **2.1** `CreateSupplierQuotation` — the header, its lines and `AUD-01`'s record in one
      transaction (`DB-11`). `SupplierQuotationDraft` now carries `items` beside `attributes`: one
      submitted payload, two tables, so `fill()` cannot be handed a key that is not a column.
      **No scope parameter and no scope resolution**, unlike `SaveDeal`: §3.4's create row carries
      scopes and §3.6's does not — every role that may create here holds `Scope::All` under "a shared
      screen — not restricted by ownership", so there is no owner column to file under. The
      permission is checked at the route (§3.12 rule 1), which is 2.2's.
      **The lines are written with one `insert()` and no Eloquent model.** The only things this
      module does with a line are insert a batch and (from 2.2) read them back; neither needs casts,
      events or a soft-delete trait, and one statement beats one save per line.
      **The rollback is the point, and it was proven by breaking it:** removing the transaction
      reddened `test_that_a_refused_line_rolls_back_the_whole_offer` alone, and computing
      `total_price` from the lines reddened the owner's-ruling test alone. Both restored by inverse
      edit, `shasum` back to `7f47a342…`.
      ⚠️ **The `AuditEnforcementTest` signal hole grew to eleven here** — see the debt register.
- [x] **2.2** `POST /api/v1/supplier-quotations` — the route, `permission:supplier_quotation.create`,
      `SaveSupplierQuotationRequest`, `SupplierQuotationController` and `SupplierQuotationPayload`.
      **Every rule that mirrors a database constraint is there for one reason:** a constraint
      violation reaches the caller as a **500**. Points 1.1 and 1.2 put four on these tables — the
      foreign keys, `unit_price >= 0`, `quantity > 0`, and the CHECK allowing both money columns or
      neither — and each is mirrored so the boundary answers 422 first. Proven by deletion: removing
      the `exists` rule on `catalog_item_id` turned that test's failure into
      *"Expected response status code [422] but received 500"*.
      **`whereNull('deleted_at')` on every existence rule** — `DB-01` soft-deletes everything and
      Laravel's `exists` is a raw table query that would accept an archived supplier, deal, currency
      or product.
      **§3.6 supplies two documented negative cases and neither had to be invented:** the CEO holds
      `view` and a dash under `create / edit`; the Outdoor Supervisor holds a dash in every column.
      Both are 403s, and `SEC-09` is proven separately by deleting the grant row from the database.
      **The 201 carries the header, not the lines** — `OpenAPI §4.1` asks for a single-resource
      envelope and says nothing about nesting children; reading them back is 2.3, and a payload
      field with no read path behind it would be this point inventing one. The lines are asserted
      against the table instead.
      **`code` is `prohibited`**, on `SaveCatalogItemRequest`'s reading of `OpenAPI §6.2`: refusing
      beats ignoring, because a caller who sends one has a wrong idea about where a code comes from.
      **`total_price` has `min:0`, which has no citation** — it mirrors the `unit_price >= 0` CHECK
      on a line, because §5 has no negative price anywhere. Flagged as a boundary rule with no
      source rather than presented as one.
      ⚠️ **No `Idempotency-Key`, and this is a real gap rather than a reading** — see the point
      proposed below and the debt register.
      **23 tests. RED first: 23 failed before any of it existed.**
- [ ] **2.2b — proposed, not approved: the `Idempotency-Key` store.** `OpenAPI §9.1` names *supplier
      quotations* outright among the critical POSTs that require the header — unlike a customer, a
      supplier or a catalog item, each of which Modules 3 and 4 correctly found **absent** from that
      list and said so in the route file. Module 6 is the first module that actually owes one, and
      **Module 5 owes one too**: deals are on the same list and `routes/api.php` does not mention the
      header on `POST /deals` at all.
      §9.1 is not one middleware. It requires a **persisted** store — actor, route, key, request
      hash, final status and response, for a retention period — replay of the original response
      without repeating the side effect, and `409 idempotency_conflict` when a key is reused with a
      changed payload, with authorisation re-checked on every replay. That is a table, a migration,
      a middleware and its own tests, and folding it into 2.2 would have rebuilt exactly the
      oversized point the owner's four-way split removed.
      **It also needs an owner decision this point cannot make: where the store lives.** A middleware
      that persists is a database writer, and
      `AuditEnforcementTest::test_nothing_outside_a_module_writes_to_the_database` forbids `app/Http`,
      `app/Support` and `routes` from writing at all. So the store is either its own module or a
      named exception to that rule — and it is shared by Modules 5, 6, 7, 10 and 13
- [x] **2.3** `GET /supplier-quotations/{id}` — `find()` on the contract, no scope (§3.6), lines
      included. `SupplierQuotationDetail`, `SupplierQuotationLine`, `SupplierQuotationNotFound`,
      `ListSupplierQuotations`, the directory's `find()`/`readLines()`, the controller's `show()`,
      `SupplierQuotationPayload::detail()`, the route, the renderer arm and both lang files.
      **The route carries `permission:supplier_quotation.view`, not `create`, and §3.6's two rows
      are why:** the CEO holds `view` as `All` and a dash under `create / edit`, so the caller Point
      2.2 refuses with a 403 is served with a 200 here. Proven by breaking it — swapping the
      middleware to `create` reddened three tests and nothing else.
      **`SupplierQuotationDetail` composes `SupplierQuotationSummary` rather than repeating its nine
      fields.** A second header shape would be the duplicate the waste audit names — two classes that
      must change together and eventually do not — so `detail()` delegates to `of()`.
      **The lines are not on `SupplierQuotationSummary`**: `create()` returns that type and Point
      2.2's 201 does not carry them (`OpenAPI §4.1`), so a field there would have no reader.
      **`DB-01` is enforced twice, because it arrives twice.** The header is filtered by the model's
      `SoftDeletes`; the lines are read through the connection with no model, so they carry an
      explicit `whereNull('deleted_at')`. Each half has its own test and each test was proven by
      breaking its own guard.
      **The 404 says nothing about which of `OpenAPI §5.1`'s two cases applied** — an absent row and
      an archived one answer identically, and `SupplierQuotationNotFound` records that only the first
      is reachable under §3.6.
      **`readLines()` refuses a non-string column rather than casting it.** PHPStan reported three
      `Cannot cast mixed to string`; a `(string)` cast would have silenced it and turned a driver
      returning a float into a silently rounded price, which is the one failure `DB-07` exists to
      prevent. `nextCode()` already refuses loudly for the same reason.
      **`SupplierQuotationLine` carries no `id`.** §7.2 names three things on a line and nothing
      addresses a single line yet; whether `PATCH` edits lines by identity or replaces the set is
      Point 2.4's decision, not this point's.
      ⚠️ **Ordered by `id`, which is stability and not insertion order** — the table has no ordering
      column at all. See the debt register.
      ⚠️ **Two verifiers were found not to verify and both were fixed inside this point.** The 401
      test authenticated itself before asserting 401 (see the harness debt below), and nothing read
      the new lang file — `__()` returns the key when the file is missing, so `supplier_quotations.php`
      could have been deleted and stayed green. The 404 test now asserts the sentence, and removing
      the file reddens it; removing the Arabic half reddens `LocaleTest`.
      **18 tests (140 assertions). RED first: 16 failed before any of it existed.**
- [x] **2.4** `PATCH /supplier-quotations/{id}` — `SupplierQuotationDraft::forUpdate()`,
      `UpdateSupplierQuotation`, the directory's `update()`/`replaceLines()`, the controller's
      `update()`, the route, and `supplier_id` becoming `sometimes|required` on a `PATCH`.
      **Which fields survive an edit: all seven, `supplier_id` included.** Two §7.2 rows stay out and
      each has a source — `code` is "Automatic" (§7.2, §4.7) and stays `prohibited`, and `entered_by`
      is `DB-02`'s author, which an edit may not rewrite. Nothing else is frozen because nothing
      freezes it: §3.6 grants "edit" over the screen, `D-51` makes the offer standalone and reusable,
      and `D-36` has a supplier's price changing under a customer quotation that survives by holding
      its own snapshot. Inventing an immutability rule with no citation is what `CLAUDE.md` forbids.
      **The permission is `supplier_quotation.create`, because §3.6's write row is one cell** —
      "create / edit ✅" — which is how `SupplierController` already reads §3.7's write cell. The CEO
      therefore reads an offer (2.3) and cannot edit it, and both halves are tested.
      **`items` is three-valued on an edit:** absent leaves the lines alone (that is what `PATCH`
      means), `[]` clears them, a list replaces the whole set. **Replaced lines are soft-deleted, not
      removed** (`DB-01`), and `readLines()` already filters them out of the read.
      ⚠️ **No optimistic locking, and that is a citation rather than a deferral.** `OpenAPI §9.2`
      scopes the version-token/`If-Match`/409 pattern to *quotations* and closes with "the same
      pattern may be adopted later for other high-contention resources **only through a documented
      contract update**"; §9.1, three lines above, lists "deals, quotations, **supplier quotations**"
      as separate resources, so §9's "quotation" is Module 7's. `DB-12` reads the same way. **Owner
      confirmed 2026-09-02.** On the debt register, because Module 7 will need it built.
      ⚠️ **A verifier was found not to verify, and the fix was a new test file.** The endpoint test
      named `..._rolls_back_the_whole_edit` proved nothing about `DB-11`: with the transaction
      removed, all 26 endpoint tests still passed, because Point 2.2's Form Request refuses a bad
      line with a 422 before any write is attempted. The rollback is now proved in
      `UpdateSupplierQuotationTest`, which calls the use case directly with a `catalog_item_id` the
      foreign key refuses — that test reddens when the transaction is removed. The endpoint test was
      renamed to what it actually proves.
      ⚠️ **`AuditEnforcementTest` caught the new writer on its first full-suite run**, exactly as
      designed, and `UpdateSupplierQuotation` is now listed AUDITED. It is the first use case in this
      module the detector can see: it calls `->update(`, one of the scanned DML verbs, while
      `CreateSupplierQuotation` reaches the database only through `->transaction(` and stays part of
      the eleven-class hole.
      **29 tests (26 endpoint + 3 use case). RED first: 26 failed before any of it existed.**

#### قائمة الاختبار اليدوي — إضافات الخطوة 5 *(المرفقات، 2026-09-05)*

> **هذه إضافة لا قائمة كاملة.** القائمة الأصلية للوحدة 6 نُشرت في المحادثة يوم 2026-09-05 وسبقت
> النقطتين 5.3 و5.4، فهي لا تذكر نقطة نهاية الرفع ولا التنزيل. **الأصل ما يزال في المحادثة وحدها
> ولم يُنقل إلى هذا الملف**، لأن نقله يعني إعادة كتابته من الذاكرة، وهو ما تمنعه قاعدة «لا تفترض —
> افتحه وانظر». الأسطر أدناه هي ما أضافته الخطوة 5 فقط.
>
> ⚠️ **كلّها تُنفَّذ عبر عميل API (Postman أو ما يشبهه)، لا عبر شاشة.** الوحدة 6 بلا واجهة أمامية —
> `grep -rn "supplier-quotations" crm/resources/js` يعيد صفرًا — وهذا هو نفسه سبب بقاء معيار
> «شاشة مشتركة» غير مؤشَّر. مكتوبة بالعربية كما يفرض `CLAUDE.md`، رغم أن بقيّة هذا الملف بالإنجليزية.

| # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
|---|---|---|---|
| 1 | مدير | ارفع ملف PDF على عرض مورّد قائم ⇒ **201** ومعه `original_name` و`mime_type` و`size_bytes` و`scan_status: clean` | رفع الملف |
| 2 | مدير | افحص جسم الاستجابة نفسه ⇒ **لا يوجد** `storage_path` ولا أي مسار على القرص | §17 |
| 3 | مدير تنفيذي | جرّب الرفع على العرض نفسه ⇒ **403** — يقرأ العرض ولا يرفع عليه | §3.6 |
| 4 | مشرف الخارجي | جرّب الرفع ⇒ **403** | §3.6 |
| 5 | مدير | غيّر امتداد ملف تنفيذي إلى `.pdf` وارفعه ⇒ **422** ورمز `unsupported_type` | MIME حقيقي |
| 6 | مدير | ارفع ملفًا نصّيًّا `.txt` ⇒ **422** ورمز `unsupported_type` | النوع |
| 7 | مدير | ارفع ملفًا أكبر من الحدّ المُعَدّ (30 ميغابايت) ⇒ **422** ورمز `too_large` | الحجم |
| 8 | مدير | أرسل الطلب بلا حقل `document` ⇒ **422** والحقل المذكور هو `document` | — |
| 9 | مدير | ارفع على معرّف عرض غير موجود ⇒ **404**، ثم على عرض محذوف ناعمًا ⇒ **404 بالنصّ نفسه** | `DB-01` · `OpenAPI §5.1` |
| 10 | مدير تنفيذي | نزّل المرفق الذي رفعه المدير عبر `GET /files/{id}/download` ⇒ **200** والملف مطابق بايتًا ببايت | `D-38` |
| 11 | مشرف الخارجي | جرّب تنزيل المرفق نفسه ⇒ **404** وليس 403 | `OpenAPI §5.1` |
| 12 | مدير | ارفع ملفًا يحمل توقيع EICAR ⇒ **201** و`scan_status: infected`، ثم جرّب تنزيله **بنفس الحساب** ⇒ **404** | `SEC-15` |

**ما لا يمكن اختباره اليوم، ويجب أن تراه لا أن يُخفى:** حالات RTL/LTR، والتحميل، والفراغ، والخطأ،
ورسالة الرفض كما يقرؤها إنسان — لا شاشة تعرض أيًّا منها. اسم الحقل في رسالة الـ 422 العربية سيظهر
`document` بحروف لاتينية (سقف معلن في النقطة 5.3). وضغط الصور (§17) غير مبنيّ أصلًا فلا سطر له هنا.

---

#### قائمة الاختبار اليدوي — الوحدة 6 كاملة *(النقطة 6.6، 2026-09-05)*

> **هذه هي القائمة الكاملة التي تُغلق الوحدة**، وهي تُغني عن القائمتين السابقتين: الأولى نُشرت في
> المحادثة قبل الخطوة 5، والثانية أعلاه أضافت المرفقات عبر عميل API. الآن توجد شاشة، فكل ما كان
> يُختبَر بـ Postman يُختبَر بالنقر. تُنفَّذ من أعلى إلى أسفل بالترتيب المكتوب.
>
> **الأدوار المطلوبة أربعة:** *مدير* (أو أي من الخمسة: قائد فريق · مبيعات خارجية · مبيعات داخلية ·
> مشتريات) · *الرئيس التنفيذي* (قراءة فقط) · *مشرف المبيعات الخارجية* (ممنوع تمامًا) · *المدير
> الأعلى* (لسحب صلاحية في الفحصين 31 و32).
>
> ⚠️ **قبل البدء:** إن كنت متقمّصًا حسابًا آخر فأنهِ التقمّص وسجّل الدخول من جديد، وإلا فالقائمة
> الجانبية تعرض صلاحيات الحساب القديم.

**أ — القائمة الجانبية والوصول** *(معيار: شاشة مشتركة — غير مقيَّدة بالملكية)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 1 | مدير | افتح النظام وانظر القائمة الجانبية ⇒ يجب أن ترى **«عروض المورّدين»** قبل «الكتالوج» |
| 2 | مدير | اضغط «عروض المورّدين» ⇒ ينتقل إلى `/supplier-quotations` وتظهر الشاشة |
| 3 | الرئيس التنفيذي | افتح القائمة الجانبية ⇒ يجب أن ترى «عروض المورّدين» **أيضًا** (§3.6 يمنحه `view`، خلافًا لـ §8 — قرار المالك 2026-08-31) |
| 4 | مشرف خارجي | افتح القائمة الجانبية ⇒ **لا يوجد** بند «عروض المورّدين» |
| 5 | مشرف خارجي | اكتب `/supplier-quotations` في شريط العنوان مباشرة ⇒ صفحة رفض، **لا** جدول فارغ |

**ب — شاشة القائمة: الحالات الأربع**

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 6 | مدير | افتح الشاشة وراقب اللحظة الأولى ⇒ تظهر **حالة تحميل** قبل الجدول |
| 7 | مدير | على قاعدة بلا عروض ⇒ **حالة فراغ**، لا جدول فارغ ولا خطأ |
| 8 | مدير | أوقف الخادم ثم أعد تحميل الشاشة ⇒ **حالة خطأ** ومعها زرّ إعادة محاولة يعمل بعد تشغيل الخادم |
| 9 | مدير | اطلب من المدير الأعلى سحب `supplier_quotation.view` من دورك ثم أعد التحميل ⇒ **رسالة رفض**، لا قائمة فارغة (`SEC-09`) — ثم أعِد المنحة |

**ج — شاشة القائمة: المحتوى والترتيب والترشيح**

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 10 | مدير | انظر الأعمدة ⇒ الرمز · المورّد · الإجمالي · تاريخ العرض · صالح حتى · الصفقة |
| 11 | مدير | انظر عمود الرمز ⇒ بالصيغة `SQ-2026-0001` |
| 12 | مدير | انظر عمود المورّد ⇒ **اسم** المورّد لا معرّفه |
| 13 | مدير | انظر عمود الإجمالي ⇒ محاذاة إلى نهاية السطر، وبأرقام متساوية العرض، **وبنفس عدد الخانات العشرية الذي أرسله الخادم** |
| 14 | مدير | افتح الشاشة أول مرة ⇒ الترتيب الافتراضي **تاريخ العرض تنازليًا** (الأحدث أولًا) ومعه سهم |
| 15 | مدير | اضغط ترويسة «تاريخ العرض» ⇒ ينقلب الترتيب وينقلب السهم |
| 16 | مدير | اختر مورّدًا من مرشِّح «المورّد» ⇒ الجدول يعرض عروض ذلك المورّد **وحدها** |
| 17 | مدير | اكتب معرّف صفقة في «الصفقة» واضغط «تطبيق» ⇒ عروض تلك الصفقة وحدها |
| 18 | مدير | مع مرشِّح لا يطابق شيئًا ⇒ رسالة فراغ **مختلفة**: «لا توجد عروض مطابقة» لا رسالة الفراغ العامة |
| 19 | مدير | مع أكثر من 25 عرضًا ⇒ يعمل زرّا «السابق» و«التالي» ويتعطّل كلٌّ منهما عند طرفه |
| 20 | مدير | **ابحث عن مربّع بحث** ⇒ **لا يوجد ولا يجب أن يوجد**؛ هذه القائمة لا تعلن بحثًا وسيردّ الخادم 400 |

**د — إنشاء عرض: الترويسة** *(معيار: عرض غير مرتبط بصفقة يُحفظ عاديًا)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 21 | مدير | انظر أعلى الشاشة ⇒ زرّ **«عرض جديد»** ظاهر |
| 22 | الرئيس التنفيذي | انظر أعلى الشاشة ⇒ **لا** زرّ «عرض جديد»، **ولا** زرّ «تعديل» في أي صفّ — **ومع ذلك الجدول يعرض كل الصفوف** *(معيار «شاشة مشتركة»)* |
| 23 | مدير | اضغط «عرض جديد» ⇒ يفتح حوار **فارغ**، وفيه **لا يوجد حقل للرمز** (§7.2 يجعله تلقائيًا) |
| 24 | مدير | اضغط «حفظ» دون اختيار مورّد ⇒ رسالة «المورّد مطلوب» تحت الحقل، **ولا يُرسَل أي طلب** |
| 25 | مدير | اختر مورّدًا واترك «الصفقة» **فارغة** واحفظ ⇒ يُحفظ عاديًا ويظهر في القائمة بعمود صفقة «غير مرتبط» *(معيار `D-51`)* |
| 26 | مدير | افتح الحوار واقرأ صفّ التحذير ⇒ يقول إن **الإجمالي وعملته لا يمكن إدخالهما بعد** *(سقف معلن، قرار المالك 2026-09-05)* |
| 27 | مدير | اكتب في «الملاحظات» ثم اضغط «إلغاء» ⇒ تحذير «تغييرات لم تُحفظ» مع «متابعة التعديل» و«تجاهل التغييرات» |
| 28 | مدير | كرّر الفحص 27 بمفتاح **Escape** بدل «إلغاء» ⇒ نفس التحذير، لا إغلاق صامت |
| 29 | مدير | افتح عرضًا له إجمالي مسجَّل، غيّر الملاحظات فقط واحفظ ⇒ **الإجمالي في القائمة لم يتغيّر** *(الحوار لا يرسل الحقل أصلًا)* |
| 30 | مدير | اضغط «تعديل» على صفّ ⇒ يفتح الحوار **ممتلئًا** بمورّد وتواريخ ذلك العرض |

**هـ — بنود العرض** *(معيار: منتج غير موجود في الكتالوج يُضاف تلقائيًا — `D-22`)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 31 | مدير | افتح حوار إنشاء ⇒ قسم «بنود العرض» يقول «لا توجد بنود بعد» ومعه زرّ «إضافة بند» |
| 32 | مدير | اضغط «تعديل» على عرض له بنود وراقب اللحظة الأولى ⇒ **«جارٍ تحميل بنود العرض…»** قبل ظهورها |
| 33 | مدير | اضغط «إضافة بند» ⇒ يظهر صفّ فيه منتج وسعر وحدة وكمية وزرّ حذف |
| 34 | مدير | افتح قائمة المنتج ⇒ تحتوي أصناف الكتالوج، **ولا تحتوي أي صنف مُعطَّل** *(§10.4)* |
| 35 | مدير | اختر «اكتب الاسم بدلًا من ذلك» ⇒ يظهر مربّع «اسم المنتج»، **وتختفي إمكانية إرسال الاثنين معًا** |
| 36 | مدير | اكتب اسم منتج **غير موجود** في الكتالوج، وسعرًا وكمية، واحفظ ⇒ يُحفظ العرض، ثم افتح شاشة الكتالوج ⇒ **المنتج صار موجودًا** *(معيار `D-22`)* |
| 37 | مدير | كرّر الفحص 36 **بنفس الاسم حرفًا بحرف** على عرض آخر ⇒ الكتالوج **لا يزداد صفًّا**؛ أُعيد استخدام الصنف نفسه |
| 38 | مدير | اكتب سعرًا سالبًا واحفظ ⇒ رسالة الخادم تظهر **تحت حقل السعر في ذلك البند بالذات**، لا في أعلى الحوار |
| 39 | مدير | افتح عرضًا له بنود، احذفها كلّها واحفظ ⇒ العرض يبقى وبنوده تُمحى |
| 40 | مدير | اقطع الشبكة، اضغط «تعديل» على عرض، ثم أعِدها واحفظ ⇒ رسالة «تعذّرت قراءة بنود العرض… ولن يمسّها هذا الحفظ»، **والبنود تبقى كما كانت بعد الحفظ** |

**و — المرفق** *(معيار: رفع ملف — النوع والحجم والنوع الحقيقي، واسم UUID)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 41 | مدير | افتح حوار **إنشاء** ⇒ قسم المرفق يقول **«احفظ العرض أولًا»**، ولا يوجد حقل ملف |
| 42 | مدير | افتح حوار **تعديل** ⇒ يوجد حقل ملف، ومعه جملة تقول إن المعروض هو مرفقات هذا الحوار فقط |
| 43 | مدير | ارفع ملف PDF سليمًا ⇒ يظهر صفّ باسم الملف ومعه زرّ **«تنزيل»** |
| 44 | مدير | اضغط «تنزيل» ⇒ ينزل الملف نفسه، **وباسمه العربي كاملًا إن كان اسمه عربيًا** |
| 45 | مدير | أعِد تسمية ملف تنفيذي إلى `offer.pdf` وارفعه ⇒ **مرفوض**؛ الفحص يقرأ البايتات لا الامتداد *(§17)* |
| 46 | مدير | ارفع ملفًا أكبر من الحدّ المضبوط ⇒ **مرفوض** برسالة حجم |
| 47 | مدير | ارفع ملفًا بتوقيع EICAR ⇒ يظهر الصفّ بحالة **«مرفوض: الفحص وجد فيروسًا»** و**بلا زرّ تنزيل** *(`SEC-15`)* |
| 48 | مدير | ارفع ملفًا بينما الفحص لم ينتهِ ⇒ حالة **«لم ينتهِ فحص الفيروسات»** و**بلا زرّ تنزيل** |
| 49 | المدير الأعلى ثم مدير | اسحب `supplier_quotation.upload_attachment` من دور المدير وحده، ثم افتح حوار تعديل ⇒ **حقل الملف اختفى بينما زرّ الحفظ باقٍ** *(المنحة الثالثة، `SEC-07`)* — ثم أعِد المنحة |
| 50 | الرئيس التنفيذي | افتح عرضًا ⇒ **لا حقل رفع** أصلًا؛ §3.6 لا يمنحه المنحة الثالثة |
| 51 | مدير | افتح ملف التخزين على الخادم ⇒ اسم الملف **UUID**، ولا يحمل أي جزء من الاسم الأصلي، وخارج جذر الويب *(§17)* |

**ز — صفحة المورّد** *(معيار: العرض المحفوظ يظهر تحت «العروض المرتبطة»)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 52 | مدير | احفظ عرضًا لمورّد ما، ثم افتح «عروض المورّدين» ورشِّح بذلك المورّد ⇒ العرض موجود، **ولا يظهر عرض مورّد آخر** *(معيار «العروض المرتبطة»، مطبَّقًا كمرشِّح)* |

**ح — اللغة والاتجاه** *(كل ما سبق، مرّتين)*

| # | الدور | الخطوة ⇒ المتوقَّع |
|---|---|---|
| 53 | مدير | بدّل اللغة إلى العربية ⇒ الشاشة والحوار **RTL**: الأعمدة تبدأ من اليمين، والإجمالي محاذًى إلى اليسار، والأسهم والأزرار منعكسة |
| 54 | مدير | بدّل إلى الإنجليزية ⇒ **LTR** وكل ما سبق منعكس، **ولا نصّ عربيّ متبقٍّ** |
| 55 | مدير | في كلتا اللغتين، افتح الحوار واقرأ كل تسمية ⇒ **لا نصّ إنجليزي داخل الواجهة العربية ولا العكس** |
| 56 | مدير | في العربية، ارفع ملفًا مرفوضًا ⇒ ⚠️ **اسم الحقل سيظهر `document` بحروف لاتينية** — سقف معلن منذ النقطة 5.3، ليس عيبًا جديدًا |

**ما لا يمكن اختباره اليوم، ويجب أن تراه لا أن يُخفى:**

1. **الإجمالي والعملة** — لا يمكن إدخالهما ولا تصحيحهما من أي شاشة. قائمة العملات لا تنشر `id`
   ومسارها خلف `admin.system_settings` (المدير الأعلى وحده). قرار مُعلَّق.
2. **مرفق رُفِع في جلسة سابقة لا يظهر إطلاقًا.** لا مسار في الـ API يسرد ملفات كيان. فحوص 43–48
   كلها تُنفَّذ **داخل الحوار نفسه دون إغلاقه**؛ إغلاقه يُخفي المرفق.
3. **الصفقة معرّف خام مكتوب باليد** في المرشِّح وفي الحوار — لا شاشة صفقات بعد (الوحدة 5).
4. **ترتيب البنود** لا يُحفظ؛ ترتيبها بعد الحفظ هو ترتيب الخادم لا ترتيبك.
5. **قائمة المورّدين وقائمة الكتالوج تقفان عند 100** صنف/مورّد. الصنف بعد المئة يُكتب بالاسم
   (ويُعاد استخدامه لا يُكرَّر)؛ المورّد بعد المئة يظهر كمعرّف.
6. **ضغط الصور (§17)** غير مبنيّ — لا سطر له هنا.
7. **لا قفل تفائلي:** تعديلان متزامنان على عرض واحد ⇒ الأخير يفوز بلا تحذير.
8. **معايير الوحدة 5** (الصفقات) ليست هنا؛ الوحدة 5 يملكها مطوّر آخر ولم تكتمل.
