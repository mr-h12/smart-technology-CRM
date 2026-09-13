> Frozen history of Module 4, cut verbatim from `CHECKLIST.md` on 2026-09-12 (commit `f8b993c`).
> Open boxes here are stale copies — the live ones are in `CHECKLIST.md`. Do not tick here.

## Module 4 — Catalog & Suppliers

> As a sales employee, I want to browse the product/service catalog and the supplier list, so that I
> can build a quotation.

**Tables** `catalog_items` · `suppliers`

**Endpoints** *(none published by the build plan — §7.1's conventions govern; see Step 2)*

#### Step 1 — schema *(point order approved 2026-08-30)*

- [x] **1.1** `suppliers` — §7.1's fields, `DB-01`/`DB-02`'s block, `DB-04`'s actor keys.
      **§7.1's field list is read out of the master documentation by the test**, the same way Module 3
      Point 1.1 reads §4.2: a column dropped from both the migration and a hand-written list would
      pass a check that only agrees with itself. The parser stops at `**Colour meanings:**` because
      §7.1 carries a *second* table below it whose first column is a colour, not a field — a slice
      running to §7.2 reads "🟢 Green" as a column name.
      ⚠️ **`linked_quotations` is deliberately not a column.** §7.1 annotates it "Automatic" and §4.1
      draws `Supplier ──► Supplier Quotation` with the key on the *quotation*; Module 6 derives the
      link from `supplier_quotations.supplier_id`. A column here would be a denormalised copy whose
      only guaranteed property is going stale. The test pins **both** halves — that the document
      still says "Automatic", and that no column was written anyway — so the exemption cannot
      outlive its reason.
      **`color_rating` and `type` are CHECKs, not enum tables**, and `DB-05` is why: it names four
      lists that must be enum tables — sectors, units, service types, delivery terms — and neither
      of these is one. §7.1 fixes the four colours by giving each a *meaning*, so a fifth is a change
      to that meaning table and therefore a migration, not a row an administrator adds in settings.
      `color_rating` defaults to `white` because §7.1 defines white as "New / not yet rated";
      `D-19` lets any employee change it, which is Point 2.2's endpoint, not a property of the column.
      **Only the name is required.** §7.1 marks *nothing* required, unlike §4.2 which writes
      "Required" beside the customer's name — so the name carries NOT NULL plus a not-blank CHECK
      and everything else is nullable, validated at the boundary. The direction is deliberate:
      tightening `type` later is a Form Request, while loosening a NOT NULL after rows exist is a
      migration.
      **`is_active` is not `DB-01`'s soft delete.** §3.7's write row is "create · edit · deactivate ·
      set colour" and §3.12 rule 3 forbids hard-deleting a supplier outright — `catalog.delete` is
      already seeded with an *empty* grant array, so no role can hold it. §10.4 describes what a
      deactivated supplier still does. `deleted_at` is `DB-01`'s and nothing in this module sets it.
      **No index beyond the block, and that is measured.** `DB-09` requires indexes on "customer ·
      owner · deal status · dates · entity codes" and `suppliers` has none of them: §3.7's matrix
      grants every operational role `Scope::All`, so there is no owner column and no row scoping to
      serve; §4.7 gives `SQ` to the supplier *quotation*, not here. `standardAudit()` already indexes
      `created_by`. The list's filters and sort arrive at Point 2.1.
      **21 tests · 1581 backend (9963 assertions) · 442 frontend · pint 382 files · PHPStan level 10
      clean · deptrac violations 0 / uncovered 0 on both configs.**
      RED first: **19 failed, 2 passed** — and the two that passed are named rather than glossed,
      because one of them passed for the *wrong* reason: `linked_quotations`' absence is trivially
      true when the table does not exist at all. That is why it was the first deliberate break.
      **Two deliberate breaks, and neither was a deletion** — a plausible wrong value proves more:
      (1) `linked_quotations` added as a real column → exactly one test failed, the derived-field
      guard; (2) the unrated default changed `white` → `green` → exactly one test failed, "an unrated
      supplier is white". Both restored and confirmed with `shasum -a 256 -c`. The second break
      mattered: **every one of the 19 RED failures was only "table missing"**, so before it no
      value-specific guard had ever been shown to catch a wrong *value*.
      **Problems found:** the suite reported **9963** assertions where the handoff baseline (9908)
      plus this file's standalone 54 predicts 9962 — one unexplained. Chased rather than waved
      through, by measuring three full runs: neither file → **9908** (the baseline is exactly right),
      migration only → **9909**, migration + test → **9963**. Bisected per suite and then per file to
      `UserSchemaMigrationTest::test_down_restores_the_scaffolded_table_it_replaced`, which rolls
      back **one migration at a time in a `while` loop** and asserts progress on each step. **Any**
      migration dated after `2026_08_23_100000_replace_users_and_create_user_sessions` therefore adds
      exactly **+1 assertion** to that test. Nothing is wrong; it is a structural property to expect
      at Point 1.2 and at every later module's migration.
      **Not covered:** 1.1 is the table. **No model, no repository, no endpoint, no seeder** — and no
      supplier can be created through the application yet. No uniqueness on name or phone, because
      §7.1 declares none. No `catalog_items` (that is 1.2), and no supplier *prices* ever — `D-21`
      keeps them on the supplier quotation, which is Module 6's.
- [x] **1.2** `catalog_items` — §7.3's two tabs in one table, `DB-01`/`DB-02`'s block, `DB-04`'s
      actor keys, and **no price of any kind**.
      **One table, because the build plan names one:** "Tables: `catalog_items` · `suppliers`". A
      product and a service are two *tabs*, not two tables, so `kind` carries the split and every
      column belonging to one tab is nullable — the conditional rules (a product needs a unit, a
      service a type) are Point 3.2's Form Request. A CHECK for them here would put a validation
      rule where no error message can reach it.
      ⚠️ **§7.3 is read differently from §7.1, because it is shaped differently.** §4.2 and §7.1 are
      `| Field | Notes |` tables whose first cell *is* the column name, so Points 3/1.1 and 4/1.1
      could map them mechanically. §7.3 is a two-column `| Product | Service |` layout of prose
      labels — "Category (for search)", "Description · active product". The translation is therefore
      written out as a `LABELS` map, and a **second test reads §7.3 and fails on any label the map
      has not placed**. Without that, the map would be a hand-written list agreeing with itself,
      which is the exact failure Point 1.1 exists to prevent.
      ⚠️ **Owner decision, 2026-08-30 — `company` is one shared column, option (a). Awaiting a
      `D-xx`.** §7.3's field table writes "Providing team / company" against **Service** only, while
      its own prose groups the whole catalog "by company/team name" *and* the build plan's criterion
      requires a **product** to appear "grouped by company". Two sources require it of a product and
      one is merely silent. `docs/` is untouched.
      **No price column, and that is what ticks the criterion.** §7.3 opens "Descriptive data only —
      **no prices**" and `D-21` puts every price on the supplier quotation, where a price belongs to
      an offer on a date rather than to the thing itself. The table has **no numeric column at all**,
      and the test asserts that absence three ways — the documentation still forbids it, no `numeric`
      column exists, and no column *name* matches `/price|cost|margin|amount/i`.
      **`unit` and `service_type` carry no foreign key**, for the reason Module 3 measured on
      `customers.sector`: `enum_lists`'s uniqueness is a *partial* index and PostgreSQL refuses a key
      against one (`SQLSTATE[42830]`). Both halves pinned, so if that index becomes total the
      decision is revisited rather than inherited.
      **No index yet, and §4.7 is why `product_code` is not one.** `DB-09` names "customer · owner ·
      deal status · dates · entity codes"; this table has no customer, no owner (§3.7 grants every
      operational role `Scope::All`), no status and no date. **`product_code` is not an entity
      code** — §4.7's codes are the generated document numbers `DL`, `QT`, `SQ`, `PO`, `RPT-*`, and
      it assigns none to a catalog item; `product_code` is whatever the manufacturer prints on the
      box. Grouping, filters and sort are defined at Point 3.1.
      **19 tests · 1600 backend (10064 assertions) · 442 frontend · pint 384 files · PHPStan level 10
      clean · deptrac violations 0 / uncovered 0 on both configs.**
      RED first: **16 failed, 3 passed** — the three passers named, because one of them ("the catalog
      stores no price") passed for the *wrong* reason again: a table that does not exist has no price
      column either. That made it the first deliberate break.
      **Two deliberate breaks, neither a deletion:** (1) `$table->money('price', true)` added → failed
      exactly one test, the no-price guard, proving the criterion is enforced rather than asserted;
      (2) `company` renamed to `providing_company` → failed exactly the three predicted (the label
      map plus both company data sets), proving the `LABELS` map really binds to the schema. Restored
      and confirmed with `shasum -a 256 -c`.
      **Problems found:** PHPStan level 10 rejected `Schema::getColumnListing()` twice — its elements
      are `mixed`, so both `assertDoesNotMatchRegularExpression()` and the message interpolation
      failed. Narrowed with `assertIsString()` rather than a cast, matching
      `UserSchemaMigrationTest::columns()`; a cast is the escape hatch Coding Standards §5 forbids.
      That edit landed **after** a full suite run had already started, so **that run's number was
      discarded and the suite re-run** rather than reported. The +1 assertion in
      `UserSchemaMigrationTest`'s rollback loop, first measured at Point 1.1, appeared again exactly
      as predicted — 9963 + 84 + 1 = 10048 before the fix, + 16 columns = 10064 after.
      **Not covered:** 1.2 is the table. **No model, no repository, no endpoint, no seeder** — nothing
      can create a catalog item yet. **No cross-field constraint**: the database will accept a
      `service` row carrying a `product_code`, because §7.3 states no such rule and every existing
      table validates cross-field shape at the boundary. Point 3.2 owns it. No uniqueness on
      `product_code` (§7.3 declares none). The "hidden from new selection lists" behaviour (`D-37`,
      §10.4) is **not** here — this point only stores the flag; the selection lists are Modules 6/7.

#### Defect fix — ⚠️ unplanned, owner-approved 2026-08-30

- [x] **D-1** The employee form showed "the change was not accepted" for **every** validation error,
      with no field marked. Reported from the running application: adding an employee with an
      address that already existed produced the generic banner instead of "that email already
      belongs to an account".
      **The backend was correct.** `yousefhasabo@gmail.com` was genuinely already an active row —
      read out of the dev database, not inferred — so `422` was the right answer. The defect was
      entirely in how the screen read it.
      **Root cause.** `CreateUserRequest` carries `Rule::unique('users','email')`, and a Form Request
      is validated **before** the controller runs. So a duplicate address never reaches the use case
      and can never produce `AdministrationRefusal::EmailAlreadyTaken` — it arrives as
      `ApiExceptionRenderer::validation()` writes it: `field: 'email'`, **`code: 'invalid'`**, and the
      server's own localised sentence. `UserFormModal.applyServerErrors` matched `code` against a
      hand-written list of four *domain* refusal codes, **none of which a Form Request can emit**, so
      nothing matched and everything fell through to `_form`. `email_already_taken` was effectively
      unreachable through that endpoint.
      **The blast radius was wider than the report.** Every Form Request failure on that form — a
      name over 255, a malformed address, a short password, a missing role — produced the same
      generic banner.
      **Fix:** when a detail names a field, use `ApiError.messageFor(field)` — the server's own
      sentence, which `api.ts` already exposed for exactly this and which nothing was calling. A
      recognised domain code still wins, because its sentence was written for that rule; the server
      message fills the gap. Kept in a **separate** `serverErrors` map rather than merged into
      `errors`, so a client-side message stays a *key* and keeps re-translating when the person
      switches AR/EN — merging them would have quietly cost that.
      **Why it was never caught:** `UserManagementTest::test_a_duplicate_live_address_is_refused`
      asserted `422` **and nothing else**. The `details[]` shape is a contract the SPA depends on,
      and a contract nothing pins is a contract that drifts. That test now pins `field`, `code`, and
      that the message is non-empty.
      **1631 backend (10309 assertions) · 446 frontend · pint 396 files · PHPStan level 10 clean ·
      deptrac 0/0 both.**
      RED: the two new frontend tests failed with the reported symptom in the assertion message
      ("expected … to contain 'The email has already been taken.'"), while the two **control** tests
      — a domain refusal, and the banner fallback — passed in RED, as controls pinning existing
      behaviour are meant to. The strengthened backend test also **passed on the first run**, and
      that is stated rather than dressed up as a fix: the server was already right.
      **Two deliberate breaks, neither a deletion:** the renderer's `code => 'invalid'` renamed to
      `validation_error` → the strengthened backend test failed, proving the new pin works; and the
      component's `FIELDS` narrowed to `['email']` → failed **exactly** "marks whichever field the
      server names, not only the email", which is the test written to catch a fix that only handled
      the reported case. Both restored under `shasum -a 256 -c`.
      **Problems found:** the first attempt let the server sentence win **over** a recognised domain
      code, which broke `UsersView.spec.ts`'s existing §5.1 test — a pre-existing test catching a
      real design error in my change, not a stale expectation. Precedence corrected so the specific
      sentence wins. That edit also landed after a backend run had started, and since the suite scans
      `resources/js`, **that run's number was discarded and the suite re-run**.
      **Not covered:** only the employee form. `CustomerFormModal` and every other form still map
      errors their own way — the same class of defect may live there and was not audited under this
      fix. No change to any backend behaviour: the renderer, the codes and the messages are
      untouched, and only the test around them got stricter.

#### Step 2 — the suppliers API *(point order approved 2026-08-30)*

- [x] **2.1** `GET /suppliers` + `GET /suppliers/{id}` — `OpenAPI §6`'s query contract, `§4.2`'s
      collection envelope, `§5.1`'s 404, and `D-48`'s search.
      ⚠️ **No row scope, and that is the shape of the whole point.** §3.3 gives customers five
      scopes and Module 3 needed `CustomerRowScope` to express them. §3.7 has **two columns** —
      "All operational roles" and CEO — and the seeded matrix backs both with `Scope::All`
      (`PermissionMatrix`, §3.7). So `SupplierDirectoryInterface` takes **no scope parameter**, the
      controller reads **nothing** off the authorisation decision, and the module's deptrac ruleset
      is two entries shorter than Customers': **no `IdentityContract`**, because a module that
      scopes no rows never has to ask who the caller is. A `SupplierRowScope` resolving to
      "everything" for every caller would be a speculative abstraction with one implementation *and*
      would imply a row-level rule §3.7 does not contain.
      **The negative test is the interesting one.** `CLAUDE.md` requires a negative-authorization
      test per endpoint, and the usual form — find a role that lacks the permission — is impossible
      here, because under §3.7 **no role lacks it**. So the test **withdraws the seeded grant row
      from `role_permissions`** and expects 403, which proves the thing actually worth proving:
      enforcement reads the matrix from the database (§3.12 rule 5, `SEC-07`) rather than hard-coding
      it. All seven granted roles are also asserted to see every supplier — a two-column table is
      exactly the kind that gets transcribed with one row missing.
      ⚠️ **`filter[is_active]` is tri-state; unset lists deactivated suppliers too.** A deliberate
      difference from Module 3's `is_archived`, which defaults to false because §9 Flow 7 archives a
      customer *to take it out of the working list*. Nothing says that of a supplier: §10.4's hiding
      rule governs the **selection lists** in Modules 6/7, not this screen — and a management list
      that hid deactivated suppliers by default is one nobody could ever reactivate from.
      **`color_rating` is filterable but not sortable.** Sorting it would order green/red/white/
      yellow alphabetically, which is not the ranking §7.1 gives those colours. A meaningful ranking
      is an unrecorded product decision, and quietly offering a meaningless one is worse than a 400.
      **`SearchIndex::Suppliers` added** — the case the enum's own docblock anticipated. Its
      `filterable()` is **empty on purpose**: that list is for filters that are *always* applied,
      because `PostgresSearchDriver`'s `MAX_RESULTS = 500` cap makes narrowing-after-search return
      the wrong page rather than fewer rows. Customers has two such filters (the owner scope and the
      archived flag); suppliers has none. ⚠️ The 500-row cap is inherited: a `q` matching more than
      500 suppliers by name truncates before `filter[...]` applies. That ceiling is the driver's,
      not this point's, and Module 15 lifts it.
      ⚠️ **§8 and §3.7 disagree, and the API follows §3.7. Awaiting a `D-xx`.** §3.7 grants
      `catalog.view` to the CEO and the Outdoor Supervisor, while §8 gives the CEO no catalog or
      supplier screen at all and the Outdoor Supervisor a Catalog but no Suppliers. §3.12 rule 1
      makes the API the enforcement point, so the route follows the matrix, and this is the same
      class of disagreement already recorded for Procurement/Customers. `docs/` untouched.
      **⚠️ Owner's ruling, 2026-08-31 — the sidebar follows §3.7 as well.** Both navigation items,
      Suppliers and Catalog, are keyed on `catalog.view` and on nothing else. The CEO and the
      Outdoor Supervisor therefore see both, which is what §3.7 grants and what §8 does not
      describe. Keying the sidebar on §8's screen list instead would have produced the mirror image
      of the defect §5.1 forbids: a screen a person is permitted to open, with no way to reach it —
      and `navigation.ts` already states the rule in the other direction, that a dead link is not a
      permission problem but a lie. `SEC-09` is unaffected either way: hiding or showing a link is
      presentation, and the API is still the gate. **A divergence from §8, recorded here awaiting a
      `D-xx`; `docs/` is untouched.**
      **The ruling is recorded, not yet applied, and it could not be applied here.** `navigation.ts`
      may not name a route `router/index.ts` does not register:
      `LogicalPropertiesTest::test_every_navigation_item_names_a_registered_route` fails on a dead
      link, and `navigation.spec.ts` asserts an item's `permission` agrees with its route's
      `meta.requiredPermission` — both read in the source, 2026-08-31. Each route arrives with its
      screen, so the **Suppliers item lands in Point 4.1** and the **Catalog item in Point 4.3**,
      each keyed on `catalog.view` exactly as ruled.
      **31 tests · 1631 backend (10304 assertions) · 442 frontend · pint 396 files · PHPStan level 10
      clean · deptrac violations 0 / uncovered 0 on both configs.**
      RED first: **31 failed, 0 passed** — no test passed for a wrong reason here, because the routes
      did not exist at all.
      **Two deliberate breaks, neither a deletion:** (1) `permission:catalog.view` removed from the
      list route → failed **exactly** the withdrawn-grant test and nothing else, which is what proves
      that test can detect missing enforcement rather than merely passing beside it; (2)
      `filter[is_active]` defaulted to `true` — the plausible mistake of copying Module 3 — → failed
      exactly the "deactivated supplier is listed" test. Both restored, both confirmed with
      `shasum -a 256 -c`.
      **Problems found:** three, all caught by the gates. (1) PHPStan required the exact
      `array{page: int, …}` shape on `SupplierPayload::pagination()` and a `@return` on the test's
      data provider. (2) pint's `ordered_imports` on the two shared files. (3) **deptrac reported 17
      module violations** — `Suppliers: ~` denies *everything*, framework included, so the module's
      first endpoint needed its ruleset written as this file requires: a named exception with a
      reason. Granted `Framework` and `SharedContracts` only. Those fixes landed **after** a full
      suite run had started, so **that run's number was discarded and the suite re-run**.
      Assertion delta reconciled to the unit: 227 (this file) + **11** (`NoHardCodedTextTest` asserts
      once per PHP file under `app/`, and this point adds 11) + **2**
      (`EndToEndConnectivityTest::test_no_health_endpoint_has_appeared` asserts once per registered
      route, and this point adds 2) = 240, and 10064 + 240 = 10304.
      **Not covered:** **no write route of any kind** — create, edit, set colour and deactivate are
      all Point 2.2, and no `AuditContract` is wired here because `AUD-01` records writes. No
      `group_by` (that is the catalog's, Point 3.1). No chip — `color_rating` ships as its stored
      code and Design System §6.4's "never colour alone" is Point 4.0's. Nothing in the SPA calls
      either route yet.
- [x] **2.2** `POST /suppliers` + `PATCH /suppliers/{id}` — create, edit, set colour and deactivate,
      all under `catalog.manage`, each with `AUD-01`'s record inside `DB-11`'s transaction.
      **One permission, four verbs, and therefore no action routes.** §3.7's write row is a *single
      cell* — "create · edit · deactivate · set colour ✅" — so `color_rating` and `is_active` are
      ordinary fields on the PATCH. `OpenAPI §7.2` reserves an action suffix for what is "not a
      normal resource update", and neither is. Module 3 needed `/archive` and `/assign` because §3.3
      made each of them a **separate permission with its own grants**; §3.7 does not, so inventing
      `/deactivate` here would have published a route the matrix has no permission for.
      **The negative test has a real role this time.** Point 2.1 had to withdraw a seeded grant to
      find a caller who could not read, because §3.7 grants `view` to everyone. Writing differs: the
      CEO's ✅ is annotated **"read-only"**, which is the absence of the `manage` grant — so the CEO
      is the documented negative case and is used as one, on both verbs.
      **No DELETE route at any permission**, and the absence is asserted rather than assumed (405):
      §3.12 rule 3 forbids hard-deleting a supplier and `catalog.delete` is seeded with an *empty*
      grant array. Deactivation is tested to leave the row and to leave `deleted_at` untouched.
      **No `Idempotency-Key`:** `OpenAPI §9.1` requires one for "deals, quotations, supplier
      quotations, purchase orders, reports, versions" — a supplier is on none of that list, the same
      reading Module 3 applied to a customer.
      **`AUD-02` is enforced, not just satisfied:** the old values are limited to the fields the
      write actually touched, and a test asserts a field the caller did not send is **absent** from
      `old_values`. `D-45` is why this is load-bearing rather than routine — §3.7 opens editing to
      every employee and the documented mitigation *is* the audit log plus a monthly review. A PATCH
      naming no writable field writes **no** audit row, and that is asserted too.
      **`SupplierDraft` holds the closed sets** (`RATINGS`, `TYPES`) so the boundary and the table's
      CHECKs cannot drift; the test round-trips **every** rating through the API rather than trusting
      the list.
      **26 tests · 1657 backend (10462 assertions) · 446 frontend · pint 400 files · PHPStan level 10
      clean · deptrac violations 0 / uncovered 0 on both configs.**
      RED first: **23 failed, 0 passed** — nothing passed for a wrong reason.
      **`AuditEnforcementTest` was run and read, never predicted.** It **does** see `SaveSupplier`
      (`->update(` beside an imported `ConnectionInterface`) and failed with
      `Unlisted: …\SaveSupplier` until the register named it. ⚠️ It does **not** see
      `EloquentSupplierDirectory`, which gained `->save(` in the same point — measured, since the
      test passes with it unlisted. That is the known signal hole: it was eight classes, and this
      makes it **nine**. Still owed its own point.
      **Two deliberate breaks, neither a deletion:** (1) both write routes re-gated on
      `permission:catalog.view` — the plausible copy-paste from the two GET routes above them →
      failed **exactly** the two CEO read-only tests, proving §3.7's "read-only" annotation is
      actually enforced; (2) `changedFrom()` made to return the whole row — the plausible reading of
      "record the old values" → failed exactly the `AUD-02` test. Both restored, confirmed with
      `shasum -a 256 -c`.
      **Problems found:** PHPStan wanted `@return` on both data providers and refused three
      `(string)` casts of `DB::table()->first()` columns, which are `mixed` — narrowed with an
      `assertIsString` helper rather than a cast, per Coding Standards §5; and pint reformatted the
      register entry. Assertion delta reconciled to the unit: **146** (this file, after those three
      added assertions) + **3** (`NoHardCodedTextTest`, one per new `app/` PHP file) + **2**
      (`EndToEndConnectivityTest`, one per registered route) + **2** (`AuditEnforcementTest`'s
      register) = 153, and 10309 + 153 = 10462.
      **Not covered:** no bulk write (`API-07` is still unbuilt). No supplier **prices** — `D-21`
      keeps them on the supplier quotation, Module 6. No chip: `color_rating` still ships as a stored
      code and Design System §6.4's "never colour alone" is Point 4.0's. Nothing in the SPA calls
      either route yet, so a supplier can still only be created with an HTTP client. Deactivation
      does **not** yet hide anything from a selection list — `D-37`/§10.4 is Modules 6/7.
- [x] **3.1** `GET /catalog-items` + `GET /catalog-items/{id}` — §7.3's catalog behind
      `catalog.view`, with §7.3's two tabs as `filter[kind]` and `API-06`'s grouping as `group_by`.
      **The same permission as the suppliers, because §3.7 is one table.** There is no
      `catalog_item.*` resource in the matrix: §3.7 covers "the catalog and its suppliers" in a
      single row pair, so a catalog read is authorised by `catalog.view` — the same grant the
      supplier routes carry. Every grant in it is `Scope::All`, so there is **no row scope**:
      `CatalogItemDirectoryInterface` takes no scope parameter, the controller reads nothing off the
      authorisation decision, and the deptrac ruleset needs no `IdentityContract`. The negative test
      therefore has to **withdraw the seeded grant** again — no role lacks `catalog.view` — which is
      what proves enforcement reads the database matrix (§3.12 rule 5, `SEC-07`) rather than code.
      **The two tabs are a filter, not a second route.** Point 1.2 put §7.3's Product and Service in
      one table behind `kind`, so `filter[kind]=product|service` is the tab. Both build-plan
      outcomes are asserted separately — a product appears alone under Product, a service alone
      under Service — and an unset filter lists both.
      **⚠️ `group_by` needed a response shape no source specifies, and this is that decision.**
      `API-06` requires server-side grouping "by employee / company", §7.3 groups the catalog "by
      company/team name", and `OpenAPI §6.2` requires each resource to declare its allowed groups
      and to answer **400** for any other. What none of them gives is the *shape* of a grouped
      collection — §4.2 shows exactly one collection envelope. **Decision: `group_by` changes the
      ordering, not the envelope.** Every row of a company is adjacent, companies alphabetical, the
      caller's `sort` applied inside each group, `data` still the flat paginated list §4.2
      describes. A nested `{group, items}` body would have made `API-04`'s `per_page` count
      something the caller never asked about, and would have given the SPA two shapes to handle for
      one endpoint. It is still *server-side* grouping: the client names a declared group and can
      never ask for an arbitrary one. **Awaiting a `D-xx`; `docs/` is untouched.**
      A row with no company sorts **last** — `nulls last` is stated in the SQL rather than inherited
      from PostgreSQL's default, because §7.3 leaves `company` optional and the ungrouped rows need
      a defined place instead of an accidental one.
      **`ALLOWED_GROUPS = ['company']`, and `unknown_group` is the first detail code this
      application has published for §6.2's third column.**
      **The declared filters are three, and each is a documented need**: `kind` (§7.3's tabs),
      `category` (the one column §7.3 annotates "(for search)"), `is_active` (§3.7's "deactivate").
      `unit`, `service_type`, `company` and `product_code` are **not** filterable — each would be a
      product decision nobody has recorded, and each is one line when somebody makes it.
      `filter[is_active]` is tri-state and unset lists deactivated items too, for the reason Point
      2.1 gives: §10.4 hides them from **selection lists** (Modules 6/7), not from this management
      screen, and a screen that hid them by default would be one nobody could reactivate from.
      **`SearchIndex::Catalog` searches two columns and pushes one filter inside.** `columns()` is
      `['name', 'category']` — the second is documented rather than guessed, since §7.3 annotates
      that column "(for search)". `filterable()` is `['kind']`, which is a **departure from
      Suppliers' empty list and a deliberate one**: `PostgresSearchDriver::MAX_RESULTS = 500` caps
      before the caller's filters apply, and the tab is not one filter among several — it is which
      screen the person is looking at. A capped search filled up by products and *then* narrowed to
      services would show the wrong rows rather than fewer of the right ones, against the build
      plan's "separate from products". `category` and `is_active` stay outside.
      **No price on the wire**, asserted as a property of the payload's keys rather than of the
      schema — the half a future Module 6 join could break without touching a migration.
      **1695 backend (10780 assertions) · 446 frontend (27 files) · `npm run build` clean · pint 412
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on both configs.**
      RED first: **38 failed, 0 passed** — nothing passed for a wrong reason.
      **Two deliberate breaks, neither a deletion:** (1) `nulls last` → `nulls first`, the plausible
      other reading of where an ungrouped row belongs → failed **exactly** the one grouping test
      that pins it, and no other; (2) the search's `kind` filter made unconditional rather than
      conditional — the plausible simplification — → failed **exactly** the three search tests that
      pass no tab, because the driver reads a null filter value as `is null`. Both restored,
      confirmed with `shasum -a 256 -c`. The **deptrac ruleset was proved load-bearing** the same
      way: reverting `Catalog` to `~` produced **17 violations**, restoring it produced 0.
      **Problems found:** PHPStan level 10 refused `orderByRaw('catalog_items.'.$group.' …')` —
      it requires a `literal-string`, and an allowlisted column concatenated in is still not one.
      Rewritten as a `match` whose one arm is the literal and whose `default` throws; the default is
      unreachable today and exists so that adding a group to `ALLOWED_GROUPS` without an ordering
      fails loudly instead of being silently ignored. That edit landed after a completed gate run,
      so **every backend number above is from the re-run**, not the voided one. Assertion delta
      reconciled to the unit: **305** (this file) + **11** (`NoHardCodedTextTest`, one per new `app/`
      PHP file) + **2** (`EndToEndConnectivityTest`, one per registered route) = 318, and
      10462 + 318 = 10780. `AuditEnforcementTest` was run and read, not predicted: it needed **no**
      new register entry, which is correct for a point that publishes two GET routes and no writer.
      **Not covered:** no write route — `POST`/`PATCH` are Point 3.2, and **no catalog edit is
      audited yet**, so `D-45`'s mitigation is not in place for the catalog. The kind-conditional
      rules (a product needs a `unit`, a service a `service_type`) are **not enforced anywhere yet**:
      Point 1.2 deliberately left them out of the schema and Point 3.2's Form Request is the only
      place they will live. `unit` and `service_type` are still unvalidated against
      `ManagedList::Units` / `ManagedList::ServiceTypes` on read, because nothing writes them.
      Nothing in the SPA calls either route — the catalog screen is Point 4.3. `group_by` is
      declared for **one** group; §7.3's grouping "by company/team name" is served, `API-06`'s
      "by employee" is not, and no catalog column holds an employee. The 500-row search cap is
      unchanged and remains Module 15's to lift.
- [x] **3.2** `POST /catalog-items` + `PATCH /catalog-items/{id}` — §7.3's create and edit behind
      `catalog.manage`, each audited inside its own transaction.
      **One permission, and the CEO is the real negative case.** §3.7's write row is a single cell —
      "create · edit · deactivate · set colour ✅" — so there is no `/deactivate` action route
      (`OpenAPI §7.2` reserves an action suffix for what is "not a normal resource update", and
      `is_active` is a field on the row) and **no DELETE at any permission** (§3.12 rule 3;
      `catalog.delete` is seeded with an empty grant array, and the absence of the route is asserted
      as a 405 rather than assumed). Point 2.1's negative test had to withdraw a seeded grant because
      §3.7 grants `view` to everyone; writing needs no such trick — §3.7 annotates the CEO's ✅
      **"read-only"**, which is the absence of the `manage` grant, so the CEO is the documented
      negative case and is used as one on both verbs.
      **The kind-conditional rules live only in the Form Request**, which is where Point 1.2 said
      they would: a product needs a `name` and a `unit`, a service needs a `service_type`, carried by
      `required_if` so a violation is a 422 naming the field instead of the 500 a cross-field CHECK
      would have produced. A service without a name is accepted — §7.3 identifies it by its type,
      which is why the column is nullable.
      **`kind` is required to create and optional to edit.** A row in neither tab appears on no
      screen §7.3 describes; on an edit, sending `kind` re-triggers the conditional rules against the
      tab it is moving to, so a coherent move is possible and an incoherent one is refused.
      **The price fields are refused, not ignored.** §7.3 opens "descriptive data only — no prices"
      and `D-21` puts price, cost and margin on the supplier quotation. `CatalogItemDraft` would
      filter them out anyway, so `price`, `cost` and `margin` are `prohibited` for the reason
      `OpenAPI §6.2` refuses an unknown query parameter: answering 201 would confirm a wrong idea
      about where a price lives. Asserted on the wire, with a zero-row check after each refusal.
      **`AuditContract` added to Catalog's deptrac ruleset, and proved load-bearing:** removing the
      entry produced **3 violations**, restoring it produced 0 — measured, not argued.
      **⚠️ `unit` and `service_type` are validated for shape, not for membership — owner's decision,
      2026-08-31, awaiting a `D-xx`.** Point 1.2's migration says both are "validated at the
      boundary" against `enum_lists`, which is why neither carries a foreign key (PostgreSQL refuses
      one against that table's partial unique index). **The same promise was made about
      `customers.sector` in Module 3 and was not kept**: `SaveCustomerRequest` validates its length
      and nothing more, and no class outside `app/Modules/Admin` references `ManagedList` anywhere in
      the project (measured by grep). Keeping it here would mean Catalog reaching Admin's
      `ManagedListRepositoryInterface`, which needs an `AdminContract` layer deptrac does not have —
      a `Rule::exists` against `enum_lists` is the cheap alternative and is the direct cross-module
      database access `CLAUDE.md` forbids outright. **Decision: match the existing precedent, record
      the gap, and close both modules in one later point.** Until then the two migration comments
      claim more than the code does, and this line is the record of that.
      **⚠️ `required_if` fires only when `kind` is in the payload.** A `PATCH` sending
      `{"unit": null}` without naming the kind blanks a product's unit, because a partial update has
      no view of the stored row. Closing it means the boundary reading the database, a layer the Form
      Request does not cross. Recorded, not fixed.
      **1732 backend (10977 assertions) · 446 frontend (27 files) · `npm run build` clean · pint 416
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on both configs (modules:
      728 allowed).**
      RED first: **28 failed, 0 passed** — nothing passed for a wrong reason, and the count is 28
      rather than 29 because `CatalogItemDraft::KINDS` did not exist yet, so the `kinds` data
      provider errored as one failure instead of expanding into two tests.
      **One deliberate break, not a deletion:** the update's audit event renamed
      `CATALOG_ITEM_UPDATED` → `CATALOG_ITEM_EDITED`, the plausible other spelling → failed
      **exactly** the one test that pins the acceptance criterion and no other. Restored, confirmed
      with `shasum -a 256 -c`.
      Assertion delta reconciled to the unit: **151** (this file) + **3** (`NoHardCodedTextTest`, one
      per new `app/` PHP file) + **2** (`EndToEndConnectivityTest`, one per registered route) +
      **2** (`AuditEnforcementTest`, which runs `assertFileExists` and the recorder-name check on
      every register entry — read in the source, not inferred) = 158, and 10819 + 158 = 10977.
      **`AuditEnforcementTest` was run and read, not predicted:** it failed on the first green run
      with `SaveCatalogItem` unlisted, and the register now names it AUDITED. ⚠️
      `EloquentCatalogItemDirectory` gained `->save(` in the same point and is **not** listed — the
      diff named only `SaveCatalogItem`, so the scanner does not see a repository writing purely
      through a module-aliased Eloquent model. **The blind spot was nine classes; it is now ten**,
      and it is still owed its own point.
      **Problems found:** restoring `deptrac.modules.yaml` after the deliberate break was done with
      `git checkout <file>`, which discarded the point's own edit along with the break, because the
      file was uncommitted. The baseline `shasum -a 256 -c` caught it immediately and the entry was
      re-applied — which is the whole reason the baseline is taken in its own command before the
      break rather than reconstructed afterwards.
      **Not covered:** the two write routes are unreachable from the SPA — the catalog screen is
      Point 4.3, and `services/catalog.ts` does not exist. Deactivation writes `is_active` and hides
      the item from nothing: §10.4's selection lists are Modules 6 and 7. Nothing prevents a product
      from carrying a `service_type` or a service a `unit` — the conditional rules require the right
      field and do not forbid the wrong one, which no source asks for and which the tabs' own
      `filter[kind]` makes invisible either way. No bulk create, no import, and no `D-35`-style
      duplicate probe: two identical products can be created in a row without a warning, the same
      gap Module 3's CSV import carries. `product_code` is not unique and nothing checks it.

#### Step 4 — screens *(point order approved 2026-08-30)*

- [x] **4.0** `services/suppliers.ts` · `services/catalog.ts` · `SupplierRatingChip.vue` — the API
      catalogue for Step 2 and Step 3's eight routes, and §7.1's chip.
      **The services restate the server's names, never their own.** `OpenAPI §6.2` answers an
      unknown filter with a 400 and both `ALLOWED_FILTERS` sets are closed, so an unset filter is
      **omitted rather than sent empty** — `filter[type]=` asks for suppliers whose type is the
      empty string, a different question from "any type". The three boolean filters are tri-state:
      `false` is a question and absence is not `false`.
      **No deactivate call and no delete call, in either service.** §3.7's write row is one cell, so
      `is_active` and `color_rating` are fields on a `PATCH`; the server publishes no action route
      for either and no `DELETE` at any permission (§3.12 rule 3). A convenience wrapper here would
      have produced a 405 at runtime, so both services assert the absence rather than paper over it.
      **The catalog tab is `filter[kind]`, not a second endpoint**, and `group_by=company` is the
      one group `ALLOWED_GROUPS` declares. `CatalogItemDraft` has no price, cost or margin field
      (§7.3, `D-21`), and the create test pins that the body carries nothing the caller did not name.
      **The chip carries a word, because Design System §6.4 ends its badge table with "never color
      alone"** and names these four "supplier rating chips only". §7.1's meanings are the source —
      🟢 excellent · 🟡 average · 🔴 problematic · ⚪ new / not yet rated — and the label is also the
      `title`, so nothing in the component conveys meaning by hue. Four modifier classes over
      `--color-success` / `--color-warning` / `--color-danger`, and the white chip borrows **no**
      status colour at all: it is the *absence* of a rating, and a white fill on a white surface is
      invisible, so the border carries the shape and muted text carries the word.
      ⚠️ **The Arabic labels are a translation, not a reading.** Every other Arabic string in this
      project came from an Arabic row in the documentation; `docs/` has no Arabic counterpart and
      §7.1's colour table is English only (checked). `ممتاز · متوسط · غير موثوق · غير مُقيَّم` are
      therefore this point's words and an owner may restate any of them without touching code.
      **1710 backend (10827 assertions) · 471 frontend (30 files) · `npm run build` clean · pint 412
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on both configs.** The
      backend and pint numbers are **lower than Point 3.2's** because this branch is cut from `main`
      and Point 3.2 is not merged yet — not a regression.
      RED first: **3 files failed, 0 tests ran** — the imports did not resolve, so nothing passed for
      a wrong reason.
      **Two deliberate breaks, neither a deletion:** (1) `filter[kind]` → `kind`, the plausible
      simplification → failed **exactly** the one tab test and no other; (2) the chip's label taken
      from `props.rating` instead of the dictionary, the plausible shortcut → failed the four
      "colour is never alone" tests **and** the Arabic-script test. ⚠️ It did **not** fail the
      English-uniqueness test, because four raw codes are also four distinct strings — that test
      cannot tell a translation from a code, and the Arabic assertion is what actually catches it.
      Both restored, confirmed with `shasum -a 256 -c`.
      Assertion and test delta reconciled to the unit against a measured baseline
      (`--list-tests` on `main` = **1703**, on this branch = **1710**): `LogicalPropertiesTest`
      generates one test per scanned file through two providers — `styledFiles()` over `vue|css`
      (+1, the chip) and `markupFiles()` over `vue|php|ts` (+6, the chip and five new `.ts` files) —
      which is +7, read in the source rather than inferred. Assertions +8 = those 7, plus 1 from
      `NoHardCodedTextTest`'s per-Vue-file scan loop.
      **Problems found:** `NoHardCodedTextTest`'s Vue inventory is a **count-asserting guard** and
      broke by design on the new component. Updated with the reason written into the test, not
      widened silently — and only after the scan itself had passed on the file.
      **Not covered:** nothing renders any of this — no screen imports either service and no screen
      mounts the chip, so §7.1's "on **every** screen" is unproved until Points 4.1 and 4.3. The
      chip's `rating` prop is a TypeScript union backed by the table's CHECK; a fifth rating added
      server-side would render an empty label, and no runtime fallback exists. The services carry no
      retry, no cache and no request cancellation. `unit` and `service_type` are still plain strings
      on the wire — the `enum_lists` gap recorded at Point 3.2 is unchanged here.
      ⚠️ **This entry and Point 3.2's are inserted at the same anchor in this file**, so PR #49 and
      this one will conflict on `CHECKLIST.md`. The resolution is to keep both, 3.2 first.
      *(Both merged 2026-08-30 with no conflict — git's three-way merge handled the two insertions.)*
- [x] **4.1** `SuppliersView.vue` · the route · the sidebar item — §8's Suppliers screen, Design
      System §5.2's Table/List.
      **Everything is asked of the server, and the tests read the URL to prove it.** §5.2 requires
      "server-side filters/sort/search" and §6.5 that "Every list is server-paginated. Do not create
      a UI that requires loading all records." A client-side filter narrows the 25 rows in hand and
      silently claims to have narrowed all of them, so every assertion about a filter or a sort reads
      the **query string**, never the rendered rows. The surface is `SupplierListCriteria`'s, read
      from the source: `ALLOWED_SORTS = ['name','created_at']`, `DEFAULT_SORT = 'name'`,
      `ALLOWED_FILTERS` four. `OpenAPI §6.2` answers anything undeclared with a 400.
      **No scope story, and that is §3.7.** `CustomersView` explains which of five row scopes a
      caller holds; §3.7 grants `Scope::All` to every role in both its columns, so an empty list here
      means the table is empty — not that a scope reached nothing.
      **`filter[is_active]` has three positions, not two.** §10.4 hides a deactivated supplier from
      **selection lists** (Modules 6/7), not from this management screen, so the default asks nothing
      about the column. A screen that hid them by default would be one nobody could reactivate from.
      **The sidebar item and the route both carry `catalog.view` — the owner's ruling of 2026-08-31,
      already recorded above.** §8 gives the CEO no Suppliers screen while §3.7 grants them
      `catalog.view`; keying the menu on §8's list would leave a screen a person may open with no way
      to reach it. `navigation.spec.ts` pins the item's permission equal to the route's, so the menu
      and the guard cannot describe different products. `SEC-09` unaffected: the API is the gate.
      **§7.1's chip is on a screen for the first time** — "beside the supplier name on **every**
      screen" — carrying a word, per Design System §6.4.
      **A 403 is drawn as a refusal, never as an empty list**, which would read as "you have no
      suppliers" when the truth is that the screen and the API disagree.
      **1742 backend (10990 assertions) · 490 frontend (31 files) · `npm run build` clean · pint 416
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on both configs.**
      RED first: **1 file failed, 0 tests ran** — the component did not exist, so nothing passed for
      a wrong reason.
      **Three deliberate breaks, one per new surface, none a deletion:** (1) `applyFilters` no longer
      resetting `page` — the plausible omission → failed **exactly** the page-reset test; (2) the
      tri-state select read as a boolean, `activeFilter === 'active'` — the plausible copy of a
      two-position filter → failed **exactly** the `is_active` default test, because it sends `false`
      on first load; (3) the nav item keyed on `catalog.manage` — the over-restriction the owner's
      ruling rejected → failed **exactly** `navigation.spec.ts`'s permission-equality test. Three
      breaks, three failures, no collateral. All restored, confirmed with `shasum -a 256 -c`.
      Delta reconciled to the unit against a **measured** baseline, not a hand count. Frontend
      471 → 490 = **16** (this spec) + **3** (`navigation.spec.ts`'s three `it.each` families, one row
      each for the new item). Backend 1739 → 1742 tests and 10985 → 10990 assertions, and both guards
      were measured on `main` and on the branch to prove nothing else moved:
      `LogicalPropertiesTest` 111 → 114 tests and 140 → 144 assertions — three provider rows
      (`styledFiles` over `vue|css` gains the component; `markupFiles` over `vue|php|ts` gains the
      component and its spec) **plus one** inside
      `test_every_navigation_item_names_a_registered_route`, which loops over `navigation.ts`;
      `NoHardCodedTextTest` 370 → 371, one per scanned Vue file. 4 + 1 = 5.
      **Problems found:** two. (1) `NoHardCodedTextTest`'s Vue inventory is a count-asserting guard
      and broke by design on the new screen; updated with the reason written into the test, after the
      scan itself had passed on the file. (2) The assertion delta was one more than predicted. It was
      **not** waved through: the two guards were re-measured on `main` and on the branch, and the
      missing assertion was found by opening `LogicalPropertiesTest` and reading the fourth test.
      **Not covered:** **no write controls at all** — §3.7's `catalog.manage` covers create, edit,
      deactivate and set colour, and all four arrive with Point 4.2's form modal, so a button here now
      would open nothing. **No `filter[has_open_account]` control**, though the server declares it:
      no source asks the screen for one, and it is one `select` when somebody does. No detail view and
      no row link — §8 lists *Suppliers*, not a supplier record, and `navigation.ts`'s rule keeps the
      name plain text until a route exists. No `linked_quotations` column: §7.1 marks it Automatic and
      Module 6 derives it. The 500-row search cap is the driver's and unchanged.
- [x] **4.2** `SupplierFormModal.vue` · the create button · the row Edit button — §7.1's supplier
      add/edit form, Design System §5.2's Detail/Form.
      **One permission draws all four verbs.** §3.7's write row is a single cell — "create · edit ·
      deactivate · set colour" — so `canManage = auth.hasPermission('catalog.manage')` draws every
      write control on the screen and there is no second permission to ask about. There is also no
      action route: the server publishes neither `/deactivate` nor `/color`, so `is_active` and
      `color_rating` travel as ordinary fields on the same PATCH, and the spec reads the request
      **method and URL** to prove the screen agrees rather than trusting the comment that says so.
      **The CEO is the documented negative case**, not an invented one. §3.7 grants them
      `catalog.view` and annotates the write column "read-only", so they are the role the
      documentation itself nominates for "reaches the screen, writes nothing". Both SEC-09 tests
      assert **both halves** — drawn for Procurement, absent for the CEO — because a one-sided
      assertion passes against a screen that draws nothing at all. `SupplierWriteEndpointTest`
      remains the gate; these buttons are the menu.
      **Seven fields, and deliberately not the eighth.** §7.1 marks `linked_quotations` "Automatic"
      and `SaveSupplierRequest` answers it with `prohibited` — a 422, not a silent drop — so a
      control for it would be one that can never save. The spec asserts its **absence**.
      **The create sends the table's own defaults explicitly** — `color_rating: 'white'` (§7.1's
      "new / not yet rated"), `has_open_account: false`, `is_active: true` — read from the Point 1.1
      migration rather than recalled. An empty box is sent as `null` and not `""`: the columns are
      nullable and "" is a value no filter or export expects.
      **`name` is the only required field**, and the client check is a courtesy, not the rule.
      `SaveSupplierRequest` carries `regex:/\S/` beside `required` because `required` accepts "   "
      and the table's `CHECK (btrim(name) <> '')` would answer a blank one with a 500. The screen
      refuses first to save a round trip; `D-67` keeps the server the authority.
      **Standing debt 17 is settled for this form by following `UserFormModal`, not by inventing a
      third way.** A field-level refusal shows the server's own sentence — the server said exactly
      what was wrong, and copying that rule into the screen would make a second copy of it. A
      form-level refusal is a **lang key** (`suppliers.form.forbidden` / `rejected` / `unreachable`),
      because a server sentence was localised once, when the request was answered, and a banner
      holding one stops re-translating when the reader switches AR/EN. The remaining forms still map
      errors their own way; that spread is unchanged and is not this point's to close.
      **The saved row is not patched in place.** A rename moves it under `sort=name` and a
      deactivation drops it out of `filter[is_active]`, so the dialog closes and the list is asked
      again (§5.2, §6.5).
      **1745 backend (10994 assertions) · 507 frontend (32 files) · `npm run build` clean
      (`vue-tsc --noEmit && vite build`) · pint 416 files · PHPStan level 10 clean · deptrac
      violations 0 / uncovered 0 on both configs (1404 and 728 allowed).**
      RED first: **1 file failed, 0 tests ran** — the component did not exist, so nothing passed for
      a wrong reason.
      **Two deliberate breaks, neither a deletion:** (1) `canManage` keyed on `catalog.view` instead
      of `catalog.manage` — the plausible copy of the *view* key the sidebar legitimately uses →
      failed **exactly** the two SEC-09 tests, by name, and no other of the twenty; (2) the
      form-level refusal taken from `error.message` instead of a key — the plausible shortcut, and
      the one this point argued against → failed **exactly** the 403 test. Both restored with the
      inverse `sed` and confirmed against a checksum baseline taken **before** the first break
      (`shasum -a 256 -c` → OK on both files).
      Delta reconciled to the unit against the measured `main` baseline (1742 / 10990 backend, 490 /
      31 frontend). Backend **+3 tests** — `LogicalPropertiesTest` generates one test per scanned
      file through two providers: `styledFiles()` over `vue|css` gains the component, `markupFiles()`
      over `vue|php|ts` gains the component **and** its spec. Backend **+4 assertions** = those three
      provider rows plus one from `NoHardCodedTextTest`'s per-Vue-file scan loop (measured 371 → 372).
      No new nav item, so the fourth guard that surprised Point 4.1 does not move here. Frontend
      490 → 507 = **13** (the new spec) + **4** (the write-control tests added to
      `SuppliersView.spec.ts`). Predicted before the run and matched exactly.
      **Problems found:** three. (1) `NoHardCodedTextTest`'s Vue inventory is a count-asserting guard
      and broke by design; updated with the reason written into the test, after the scan itself had
      passed on the file (48 passed / 372 assertions). (2) **Two `artisan test` runs were alive at
      once** and the suite came back `48 failed` with `DeadlockException`. The cause was an operating
      error, not the code: a suite backgrounded with a shell `&` was assumed dead because its log had
      no summary, and a second was started over it. `ps` on the host showed both. (3) Killing the
      host-side `docker compose exec` does **not** immediately kill the PHP process inside the
      container, so the first re-run still contended. The container has **no `pgrep`**, and a
      `pgrep || echo gone` probe therefore reported "gone" from the *error* path — a false all-clear.
      Read `/proc/*/cmdline` instead. Clean re-run: 1745 passed, 0 failed.
      **Not covered:** no focus trap and no focus return — the dialog is `role="dialog"`
      `aria-modal="true"` with Escape and scrim handled, but focus is not moved into it on open nor
      restored to the opener on close; `ConfirmDialog.vue` has the same gap (debt 15) and both want
      one shared fix rather than two. No optimistic locking — suppliers have no version column, so
      two people editing one supplier is last-write-wins (`DB-12` scopes `If-Match` to quotations).
      No delete and no archive control: `catalog.delete` is an **empty grant array** (§3.12 rule 3)
      and there is no route at any permission. Still **no `filter[has_open_account]` control**
      (debt 26) and still no supplier detail view. The dialog does not warn that deactivating a
      supplier hides them from Modules 6/7 selection lists, because those lists do not exist yet.
- [x] **4.3** `CatalogView.vue` · the route · the sidebar item — §8's Catalog screen, §7.3's two
      tabs and its grouping.
      **§7.3 read from the source, line 665:** "Descriptive data only — **no prices**. Two tabs:
      Product · Service, grouped by company/team name." All three clauses are decisions here and all
      three are asserted. **No prices:** `D-21` puts price, cost and margin on the supplier
      quotation, `CatalogItemPayload` carries none of them, and a test greps the rendered screen for
      `/price|cost|margin/i` so a future label cannot reintroduce one. **Two tabs and no third:**
      Point 1.2 put both in one table behind `kind`, so a tab is `filter[kind]` against one endpoint
      and one of the two is always active — there is no "everything" position, because §7.3 names
      none. **Grouped by company:** `group_by=company` is sent on **every** load rather than offered
      as a toggle, because §7.3 states it as how the catalog is read.
      **The grouping is the server's order; the screen only notices it.** `group_by` changes the
      ordering, not the envelope — `data` is still the flat paginated list, companies adjacent and
      alphabetical, nulls last. So a heading is drawn where the value *changes*. There is no
      client-side `sort()` or `reduce()` into buckets, which would be §6.5's forbidden "UI that
      requires loading all records" wearing a different hat.
      **The columns follow the tab**, because §7.3's two field lists are not the same list: the
      Product tab names product code, category and unit; the Service tab names service type and
      notes. One union with half the cells empty would misdescribe both.
      **The sidebar item and the route carry `catalog.view`** — the owner's ruling of 2026-08-31,
      applied for the second time and now consistent across both Module 4 screens. §3.7 is one row
      pair covering the catalog *and* its suppliers, so there is no `catalog_item.*` resource to
      name. `navigation.spec.ts` pins the item's permission equal to the route's.
      **Category is free text on the search form, not a select.** §7.3 calls it "for search" and no
      endpoint enumerates the values, so there is nothing to populate a dropdown from — and firing a
      request per keystroke is what §5.2's search button exists to avoid.
      **1748 backend (10999 assertions) · 529 frontend (33 files) · `npm run build` clean · pint 416
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on both configs.**
      RED first: **1 file failed, 0 tests ran** — the component did not exist, so nothing passed for
      a wrong reason.
      **Three deliberate breaks, one per new surface, none a deletion:** (1) `groupBy` sent as `null`
      — the plausible omission, since the list still renders without it → failed **exactly** the
      `group_by=company` test; (2) an **off-by-one** in the group boundary, comparing `index + 1`
      instead of `index - 1` — the plausible slip, and one that still draws headings, just the wrong
      ones → failed **exactly** the heading-order test; (3) the nav item keyed on `catalog.manage` —
      the over-restriction the owner's ruling rejected → failed **exactly**
      `navigation.spec.ts`'s permission-equality test. Three breaks, three failures, no collateral.
      All restored with inverse edits and confirmed with `shasum -a 256 -c`.
      Delta predicted before the run and matched to the unit. Backend 1745 → 1748 = three
      `LogicalPropertiesTest` provider rows (`styledFiles` over `vue|css` gains the component;
      `markupFiles` over `vue|php|ts` gains the component and its spec). Assertions 10994 → 10999 =
      those three, **plus one** from `test_every_navigation_item_names_a_registered_route` for the
      new item, **plus one** from `NoHardCodedTextTest`'s per-Vue-file scan loop (measured 372 → 373).
      Frontend 507 → 529 = **19** (the new spec) + **3** (`navigation.spec.ts`'s three `it.each`
      families, one row each for the new item).
      **Problems found:** one. `NoHardCodedTextTest`'s Vue inventory broke by design again; updated
      with the reason written into the test, after the scan itself had passed on the file (48 passed
      / 373 assertions). Nothing else went wrong — the two operating errors of Point 4.2 did not
      recur, because the suite was checked for a live run before starting and was run alone.
      **Not covered:** **no write controls at all** — §3.7's `catalog.manage` covers create, edit and
      deactivate for items too, and all three arrive with Point 4.4's form modal. **A group that
      spans a page boundary is given its heading again on the next page**, because the screen keeps
      no memory of the previous page's last row; the alternative is state this screen does not hold
      and `API-06` does not report. No detail view and no row link. No `product_code` or `company`
      filter — `ALLOWED_FILTERS` is `kind · category · is_active` and inventing one would be a 400.
      Sorting is `name` and `created_at` only, so the Service tab sorts by a `name` that may be null.
      Deactivation still hides nothing from a selection list (`D-37`/§10.4 → Modules 6/7).
- [x] **4.4** `CatalogItemFormModal.vue` · the create button · the row Edit button — §7.3's catalog
      add/edit form. **Last point of Module 4.**
      **Two tabs are two field sets, not one union.** §7.3 lists "Product code · Product name ·
      Category · Unit · Description" against "Service type · Service description · Providing
      team/company · Active · Notes", and `SaveCatalogItemRequest` turns that into three
      `required_if` rules — read from the source at lines 85, 92 and 95. A single union form would
      ask for what the server refuses and hide what it demands, so the field list, the required
      markers and the payload all follow the kind.
      **`kind` is named on every write, and that is this point's answer to debt 24.**
      `required_if:kind,product` fires **only when `kind` is in the payload**; the boundary says so
      in its own docblock, and a `PATCH` of `{"unit": null}` alone therefore blanks a product's unit
      because a partial update has no view of the stored row. Closing it there would mean the
      boundary reading the database — a layer it does not cross. This form names the kind on create
      **and** edit so the conditional rules always have something to fire on, and a test guards that
      promise. ⚠️ **The server-side gap is unchanged for every other caller** and debt 24 stays open;
      what changed is that this screen can no longer walk into it.
      **The kind is not a control.** On a create it is the tab the person is standing on; on an edit
      it is the row's own — asserted by opening a *service* record while the *product* tab is
      active and watching the form follow the record. The server does allow a PATCH to move a row
      between tabs and re-checks the whole row when it does, but no source asks for that, and a
      selector that silently retypes a catalog item is a bigger claim than §7.3 makes. **Recorded as
      a narrowing, not a decision.**
      **No price, cost or margin control, and not because they are hidden.** All three are
      `prohibited` on the server (§7.3, `D-21`), so a control for one could never save. Asserted
      twice: no such testid exists, and the dialog's rendered text matches none of the three words.
      **Server errors follow `UserFormModal`, as `SupplierFormModal` does** — a field refusal shows
      the server's own sentence, a form-level refusal is a lang key. Two of the four forms in this
      SPA now share one approach deliberately; debt 17 covers the rest.
      **1751 backend (11003 assertions) · 553 frontend (34 files) · `npm run build` clean · pint 416
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on both configs.**
      RED first: **1 file failed, 0 tests ran** — the component did not exist.
      **Three deliberate breaks, one per new surface, none a deletion:** (1) `kind` dropped from the
      payload — **the exact defect debt 24 describes**, and the plausible one, since the write still
      succeeds → failed **exactly** the three payload tests; (2) the service's required field copied
      from the product's (`['name']` for `['service_type']`) — the plausible copy-paste → failed five
      tests, and that spread is itself the finding: a wrong required rule blocks every service save,
      so the two refusal tests fail as collateral rather than by coincidence; (3) `canManage` keyed
      on `catalog.view` → failed **exactly** the two `SEC-09` tests. All restored with inverse `sed`,
      confirmed with `shasum -a 256 -c`.
      Delta predicted before the run and matched to the unit. Backend 1748 → 1751 = three
      `LogicalPropertiesTest` provider rows; assertions 10999 → 11003 = those three plus one from
      `NoHardCodedTextTest`'s scan loop (measured 373 → 374). No new route and no new nav item, so
      neither of the two guards that move for those does. Frontend 529 → 553 = **19** (the new spec)
      + **5** (the write-control tests added to `CatalogView.spec.ts`).
      **Problems found:** two. (1) `NoHardCodedTextTest`'s Vue inventory broke by design again;
      updated with the reason written into the test, after the scan passed on the file (48 passed /
      374 assertions). (2) ⚠️ **A wrinkle Point 4.3 introduced and this point exposes:** the Catalog
      screen draws a sortable **Name** column on *both* tabs, but §7.3 gives a service no name and
      this form therefore offers none — so the Name column on the Service tab shows `—` for every
      row. The two allowed sorts are `name` and `created_at`, so removing the column would leave the
      Service tab with one sort. **Not fixed here, because both repairs are a judgement about §7.3
      rather than a defect:** either a service gets an optional name, or the Service tab's first
      column becomes Service type and loses a sort. **Owner's ruling wanted.**
      **Not covered:** no focus trap and no focus return on close — the same gap as
      `SupplierFormModal` and `ConfirmDialog` (debt 15), and all three want one shared fix. No
      optimistic locking: catalog items have no version column, so concurrent edits are
      last-write-wins. No delete and no archive — `catalog.delete` is an empty grant array (§3.12
      rule 3) and there is no route at any permission. `unit` and `service_type` are still free text
      validated for shape and not membership against `enum_lists` (debt 23, owner's decision of
      2026-08-31), so the form offers no dropdown for either and a typo is accepted. No bulk create
      and no import. Deactivation still hides nothing from a selection list (Modules 6/7).
- [x] **5.0** `SupplierRatingChip.vue` — §6.4's icon half, which Point 4.0 left out. **A defect the
      owner found on the running screen, not a new feature.**
      **What was wrong.** Design System §6.4 line 216 reads "Success … **Green icon + text/chip**;
      never color alone", and line 218 "Danger … **Red icon** + label". Point 4.0 read the second
      half of that sentence, shipped the word alone, and cited §6.4 as satisfied — in this file, at
      the criterion below. It was not: the chip carried no icon, and its fill was
      `color-mix(var(--color-success) 14%, transparent)`, which over `#FFFFFF` computes to
      **`#DEE9E3`** — a grey. The red chip computed to `#F6E4E3`. The dark theme was no better:
      `#5DDB90` at 14% over `#111827` is `#1C3336`. And `SuppliersView`'s neutral status chip uses
      the **same 14% recipe**, so the rating chip and the status chip beside it had identical visual
      weight. §7.1 says "The colour **appears** as a chip"; on screen it did not.
      **Why the suite did not catch it, which is the part worth keeping.** Every assertion in
      `SupplierRatingChip.spec.ts` asked about **text** or about **class names** — "carries a
      localised word", "gives each rating its own class". Both remained true of a grey chip. `jsdom`
      does not apply an SFC's scoped `<style>`, so no `getComputedStyle` assertion could have helped
      either. **The tests were not weak by accident: nothing in reach of this suite can see a
      colour.** Recorded rather than papered over.
      **The repair.** An inline `<svg>` circle, `aria-hidden` and `focusable="false"`, filled with
      `currentColor` so it takes the chip's own token and cannot drift from the word beside it —
      §6.4's icon, and §7.1's own 🟢🟡🔴⚪ presentation. The fills rise to 18–22% and each rating
      gains a `border-color` at 45% of its token, so the chip reads as coloured in a row of neutral
      ones. Owner chose the coloured circle over meaning-glyphs (✓ ! ✕) on 2026-08-31, because the
      circle is what §7.1 itself draws and the glyphs would be invented.
      **The new guard, and its honest limit.** Three families were added: the circle exists for each
      of the four ratings; it is `aria-hidden` so the word stays the whole accessible name; and each
      rating's `border-color` is not `transparent` — the last read from the component's **source
      text**, because `jsdom` cannot report the computed value. ⚠️ **The degree of tint is still not
      machine-checkable.** A future change that halves the percentages would pass every test here.
      Said plainly rather than claimed closed.
      **1907 backend (11596 assertions) · 541 frontend (33 files) · `npm run build` clean · pint 450
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 (1583 and 796 allowed).**
      ⚠️ These baselines are **higher than Point 4.4's** because this branch is cut from a `main`
      that now carries PR #54, the second developer's Module 5 (Deals) work — not a regression and
      not this point's doing. Verified as unrelated: `git diff origin/main~1 origin/main` touches no
      supplier, catalog, locale, navigation or router file.
      RED first: **11 failed, 9 passed** — and the 9 are the point. Every pre-existing assertion went
      on passing against the defective chip while the new ones failed, which is the finding stated
      above, demonstrated rather than asserted. (The white chip's border test passed from the start:
      white already carried `--color-border-strong`, because it is the *absence* of a rating and a
      white fill on a white surface would be invisible. Only the three coloured ones were bare.)
      Delta measured, not predicted: frontend `main` 529 → branch 541 = **+12**, the twelve new
      `it.each` rows (4 ratings × 3 families), **with no new file**. And because no file was added,
      the two count-asserting backend guards do not move at all — proved by running
      `LogicalPropertiesTest|NoHardCodedTextTest` with the change and then again under `git stash`:
      **168 tests / 548 assertions both times.**
      **Deliberate breaks — three, each restored:** (1) green's `border-color` set back to
      `transparent`, which is the original defect re-created → failed **exactly** the green border
      test; (2) `aria-hidden="false"` → failed **exactly** the four decorative tests; (3) the `<svg>`
      removed entirely, which is Point 4.0's literal shape → failed **exactly** the eight icon tests
      and nothing else.
      **Problems found:** three, all mine and all caught by a check rather than by luck.
      (1) The first version of the border assertion searched the whole CSS rule for the word
      `transparent` and so failed against a **correct** `color-mix(..., transparent)` border. The
      assertion was wrong, not the code; it now reads the `border-color` **value**. A verifier that
      fails for the wrong reason is not a verifier.
      (2) Restoring break 3 by re-inserting the markup left the HTML comment **duplicated**, because
      the break had removed the `<svg>` by slicing lines rather than by an inverse edit. `shasum -c`
      reported FAILED and the duplicate was found and removed; the file is byte-identical to the
      pre-break baseline. This is the second time in this project that a non-`sed` restoration was
      wrong, and the checksum is the only reason it did not ship.
      (3) `vue-tsc` refused `declaration?.[1].trim()` — `TS2532`, the capture group is possibly
      undefined — **after `npm run test:unit` had already passed 541 green**. The fix landed while
      the backend suite was running, and `LogicalPropertiesTest::markupFiles()` scans `ts`, so that
      run was **voided and re-run clean**; both runs happened to agree at 1907/11596, which the guard
      measurement above independently predicts. The reported figure is the clean run's.
      **Not covered:** the chip's **placement** is unchanged — it sits in its own `التقييم` column,
      adjacent to the name column but not inside the name cell. §7.1's "beside the supplier name" is
      read as satisfied by adjacency; if the owner meant *inside* the name cell, that is a separate
      change to `SuppliersView`. Contrast ratios were computed by hand from the token values, not
      measured with a tool against the rendered page. The neutral status chip still uses the old 14%
      recipe — deliberately, since it is a status and not a rating, but the two now differ in weight
      by design rather than by accident. Nothing was changed on any other screen, because §7.1's
      "every screen" still has only this one.
      ⚠️ **This entry and Point 4.4's are inserted at the same anchor**, so PR #57 and this one will
      both touch `CHECKLIST.md` here. Keep both, 4.4 first.
- [x] **5.1** `ManagedList::Companies` — `DB-05`'s fifth list, and the migration it turned out to
      need. **Owner's ruling of 2026-08-31, recorded here awaiting a `D-xx`:** `DB-05` names four
      lists — "sectors · units · service types · delivery terms" — and does not name companies.
      **Why a list and not the free text it was.** §7.3 groups the catalog "by company/team name",
      and the owner asked to filter by it as well so the same product can be compared across
      companies. A value that is both **grouped and filtered** cannot stay free text without
      fragmenting: "Acme", "acme" and "Acme Ltd" become three companies on one screen. That is
      `R-03`'s recorded free-text risk — logged there about regions — arriving a second time, and
      the register already prescribes "convert to a managed list later" as its answer.
      **Seeded empty, for `delivery_terms`' reason applied to a different list.** No document in this
      project names a single company; they are the owner's own trading partners and brands. A
      plausible-sounding "Acme" seeded here would become a heading real products are grouped under
      and a value real users filter by — business content nobody wrote. ⚠️ **The consequence belongs
      to Point 5.2 and is deliberate:** once `company` becomes `required`, an empty list means **no
      catalog item can be created until the Super Admin adds a company**, because `POST
      /managed-lists/{list}` carries `admin.system_settings`. The owner has been asked whether to
      seed real names, accept it as a setup step, or relax that permission; **5.2 does not start
      until that is answered.**
      **⚠️ This point edits Module 2's `Admin` module while Module 4 is the module in hand**, which
      `CLAUDE.md`'s module-isolation rule would normally refuse. It is deliberate: `DB-05`'s lists
      are one shared mechanism with one table, one endpoint and one admin screen, and the
      alternative — Catalog keeping its own list of companies — is a second implementation of the
      thing `DB-05` exists to prevent. No Catalog code is touched here at all.
      **1915 backend (11618 assertions) · 553 frontend (34 files) · `npm run build` clean · pint 451
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 (1583 and 796 allowed).**
      RED first, in two halves. Backend: **5 failed, 47 passed** — the five new or narrowed
      expectations and nothing else. Frontend: **1 failed, 13 passed** — `ManagedListsView`'s
      "offers exactly the four lists" guard, updated to five with the reason written into it.
      Delta **measured, not predicted**, by running every guard that could move
      (`ManagedList|UserSchemaMigrationTest|LogicalPropertiesTest|NoHardCodedTextTest|EndToEndConnectivityTest`)
      with the change and again under `git stash`: **292 tests / 1150 assertions → 287 / 1132**, so
      **+5 tests and +18 assertions**, which is the whole of 1910/11600 → 1915/11618. Frontend
      **+0**, because the list guard was *edited* rather than added — confirmed twice, since Point
      5.0 measured this same `main` at 553/34 from the other side.
      **Deliberate breaks — three, all restored and `shasum -c` confirmed:** (1) the case value
      written singular, `'company'` — the plausible slip → failed **exactly** the enum guard and both
      endpoint tests, no collateral; (2) a plausible company seeded (`acme / Acme / أكمي`) — the
      exact thing the comment forbids → failed **three independent** guards: the empty-by-design
      assertion, the documented-count provider, and the registry-size assertion that pins 13 entries;
      (3) `'companies'` dropped from the SPA's `MANAGED_LISTS` → failed **exactly** the view guard.
      **Problems found:** two, and the first is the important one.
      (1) ⚠️ **A claim I wrote into the code was wrong, and the full suite caught it.** Both
      `ManagedList` and `create_enum_lists` argue that "adding a fifth list means adding the column
      that points at it, which is a migration either way", and I extended that to say `companies`
      needed **no** migration because `catalog_items.company` already existed. It does not need a
      *column* — but `create_enum_lists` CHECKs `list` against four **literals**, so the first
      `companies` row answered `SQLSTATE[23514]: violates check constraint "enum_lists_known_list"`.
      I had run only `--filter=ManagedListsDataTest|ManagedListEndpointTest` and missed
      `ManagedListSchemaMigrationTest` entirely; the unfiltered suite is what found it. The claim is
      now corrected **in the docblock as well as in the code**, and
      `2026_08_31_020000_extend_enum_lists_with_companies` drops and re-adds the constraint with five
      names — the original migration untouched, per `DEV-03` and `CLAUDE.md`. `down()` restores the
      four and was **proved, not assumed**: `migrate:rollback --step=1` then `migrate`, reading
      `pg_get_constraintdef` before and after each. The 23514 failure is also this migration's
      verifier — it failed on its own, before the fix existed, for exactly the right reason.
      (2) `ManagedListSchemaMigrationTest`'s drift guard asserted `assertCount(4, ManagedList::cases())`
      against the `DB-05` line in the master documentation. It was **narrowed rather than widened**:
      the four documented names must each still be present, and the set difference must be exactly
      `['companies']`. A bare `assertCount(5)` would have stopped catching a removal.
      **Not covered:** nothing yet **uses** the list. `company` is still free text on the wire and
      still optional — the `required` rule and the `filter[company]` are Point 5.2's, and the
      dropdowns are 5.3's. The list is empty, so the admin screen shows its empty state for it. There
      is no migration of existing `catalog_items.company` values into `enum_lists`, and none is
      possible before the owner says which of the strings already there are real companies — every
      existing row keeps its free-text value and will fail 5.2's validation on its next save until
      somebody picks a listed company. `DELETE`/`PATCH` on a list entry still do not exist
      (`DB-01`), so a company added by mistake can only be archived.
      ⚠️ **This entry and Point 5.0's are inserted at the same anchor**, so PR #58 and this one will
      both touch `CHECKLIST.md` here. Keep both, 5.0 first.
- [x] **6.5** The company filter control on the Catalog screen — the caller `filter[company]` has
      been waiting for since Point 5.2. **Step 6 is complete.**
      **Why the parameter existed with nobody calling it.** 5.2 added `company` to
      `CatalogItemListCriteria::ALLOWED_FILTERS` and said so in as many words; `§6.2` answers an
      undeclared parameter with a **400**, so the URL is the whole assertion and the test reads it
      rather than the rendered rows. `group_by=company` only **orders** the list (Point 3.1 kept
      §4.2's flat envelope), so a screen that groups by a column has to be able to ask for one group
      of it — that sentence is 5.2's, and this is the control it was written for.
      **A `<select>`, not a text box.** After Point 6.3 the column stores a **code**, and
      `EloquentCatalogItemDirectory` matches it with `where` — an exact match against a value nobody
      can spell from memory (`alpha_co`, `c_3m`). The options are the list Point 6.4 already loads
      for the form, so this point adds **no request**: `companies` was already in hand.
      `entryLabel(entry, locale)` is the one 6.4 moved into `services/admin.ts`, reused rather than
      copied — the check that helper was extracted for, one point later.
      **`filtering` gains the control too**, so an empty result under a chosen company reads "nothing
      matched" and not "there are none". Those are different sentences and only one is ever true.
      **Checks: 1981 backend (11958 assertions) · 584 frontend (34 files) · `npm run build` clean ·
      pint PASS 471 files · PHPStan level 10 `[OK] No errors` · deptrac 0 / 0 (1682 and 1041
      allowed).** No backend file changed, so the backend figure is `main`'s. Frontend delta
      **measured, not remembered** — `git stash` then the suite: **582 → 584**, the two tests here.
      **RED first:** both new tests failed and the 25 existing ones passed.
      **Deliberate break — one, restored and `shasum -c` confirmed.** Deleting
      `['filter[company]', query.company]` from `listCatalogItems` failed **exactly one** test —
      `expected '/api/v1/catalog-items?page=1&sort=nam…' to contain 'filter%5Bcompany%5D=acme'` —
      and nothing else, which is the point: the control can be wired to a `ref` that reaches no URL,
      and that is the failure a rendered-rows assertion would have missed. Restored with the inverse
      edit, never by re-inserting text.
      **Problems found: one, and it was procedural.** `main` moved underneath this point while it was
      being written — PR #71 landed the whole six-PR catalog/admin stack and #72 landed the deals
      nightly recompute — and the working tree was left **on `main`**, which is §2.6's documented
      trap. `git branch --show-current` before the commit caught it, exactly as the rule says it
      should, and the work was branched before anything was committed. Every figure above was
      re-measured on the new `main` (`862c0c0`); the backend total moved 1957 → **1981** and pint
      451 → **471 files** because of #72, not because of this point.
      **Waste audit.** *Dead code:* `companyFilter`, `companyAll`, `catalog-filter-company`,
      `filter[company]` and `query.company` each grep to at least one **use** beyond their
      declaration over `app/` and `resources/`. *Duplicate logic:* none created — the label helper
      is imported and the `<select>` follows `activeFilter`'s existing markup rather than inventing
      a second shape for the same control. *Unused components:* no `.vue`, route or guard moved;
      one lang key added and rendered. *Unnecessary complexity:* the filter needs no
      stored-value fallback the way 6.4's selects did — a filter has no stored value to preserve,
      only a question to ask.
      **Not covered:** **legacy free-text `company` values cannot be filtered for.** They are in no
      list, so they are in no option, and this is §1.2's open decision surfacing as a visible symptom
      rather than a new defect — a row saved as `Alpha Co` before 6.3 is unreachable from this
      control until it is either migrated or saved again. Still no `filter[has_open_account]` control
      on the Suppliers screen (debt register, unchanged). The control is a single select, so there is
      **no multi-company filter** and none is asked for. Nothing here touches the server: the filter,
      its `where` and its 400-on-undeclared were all shipped in 5.2.
- [x] **6.6** The `code` box on the Managed Lists screen fills itself from the English label.
      **Owner's request, 2026-09-01**, and the two answers it was given before any code was written:
      the field is **filled and stays editable** (not locked, not removed), and it derives from the
      **English label only**.
      **Why the English label and not the Arabic one.** `AddListEntryRequest` requires
      `^[a-z][a-z0-9_]*$` — the boundary's own rule is Latin. The transliteration `Str::slug`
      applies to Arabic (`شركة ألفا → shrk_alfa`, measured at Point 6.3) is a PHP table the browser
      does not have, so deriving from the Arabic box in the SPA would give the same company a
      **different code** from the one Point 6.3's auto-registration gives it. That divergence was
      put to the owner as the cost of the alternative, and the alternative was declined.
      **Why "editable" needed a second mechanism.** A box that fills itself and then silently
      reverts on the next keystroke in the label is a read-only box wearing a text box's clothes.
      `codeEdited` latches on the code field's own `input` event — which a programmatic `v-model`
      write does not fire — so the derivation stops the moment a person touches it, and `resetForm()`
      clears the latch with the rest of the form.
      **What the derivation is, and its stated ceiling.** Accent fold (`NFD` + combining-mark strip),
      lowercase, non-alphanumeric runs to `_`, trimmed, and the `c_` prefix Point 6.3 already uses on
      the server when the result starts with a digit. It is **not** `Str::slug`: it covers the Latin-1
      names that actually appear and anything it cannot reduce falls out as an empty code, which is
      the case the person types by hand. Marked `ponytail:` in the source with that ceiling named.
      **This is a suggestion, not a rule (`D-67`).** The server still validates `code` exactly as
      before; nothing about who may write, or what is accepted, moved into the browser.
      **Checks: 1981 backend (11958 assertions) · 585 frontend (34 files) · `npm run build` clean ·
      pint PASS 471 files · PHPStan level 10 `[OK] No errors` · deptrac 0 / 0.** No backend file
      changed. Frontend **582 → 585**, the three tests here, measured on this branch's own base.
      **RED first, and read rather than counted: only *two* of the three failed.** The third — "stops
      deriving once the code has been edited by hand" — passed vacuously, because nothing derived at
      all yet. That is a verifier proving nothing, and it is why the deliberate break below targets
      exactly it rather than the derivation.
      **Deliberate break — one, aimed at the vacuous test.** Removing the `if (!codeEdited.value)`
      guard failed **exactly that test** — `expected 'alpha_company' to be 'alpha'` — and nothing
      else. Restored with the inverse edit, `shasum -a 256 -c` OK.
      **Problems found: two, and the second was found by the owner on the running app.**
      (1) The vacuous RED above.
      (2) ⚠️ **The first version did not fill anything for the owner, and the tests could not have
      caught it.** The code box was the **first field on the form**, so a person filling it top to
      bottom lands in it before any label exists to derive from — and the latch fired on *any*
      `input`, so one stray character typed and deleted killed the derivation for the rest of the
      form's life. Diagnosed by measurement rather than by theory: the served bundle was grepped and
      **did** contain `codeFrom` and the new hint (`normalize(\`NFD\`).replace(/[\u0300-\u036f]/g…`),
      and `curl` confirmed the served page referenced that exact asset — so it was never a stale
      build. The fix is the root cause, not the symptom: the code box **moves below the two labels**
      it is derived from, and emptying it **un-latches** the derivation, because clearing a box is
      asking for the suggestion back rather than editing it. Two tests added for both halves; both
      failed first.
      **Waste audit.** *Dead code:* `codeFrom` and `codeEdited` grep to 2 and 4 hits over `app/` and
      `resources/`. *Duplicate logic:* searched before writing — `grep -rn "normalize('NFD')"` over
      `resources/js` returns **this file only**, so there was no existing slug helper to reuse and
      there is now exactly one. *Unused components:* no file, route or guard added; the one lang
      string changed is the hint that told people to compose the code themselves and would now be
      false. *Unnecessary complexity:* the latch is a `ref` and an `@input`, not a watcher on the
      code field — a watcher cannot tell a person's edit from the derivation's own write.
      **Not covered:** the hint is **prose, and prose is not a test** — nothing asserts it stopped
      contradicting the screen. There is still **no label editing** on a list entry, so a code
      derived from a typo is corrected by archiving and re-adding, not in place. The derivation runs
      only on the **add** form; it has nothing to attach to on an edit, because there is no edit.
      Two labels that reduce to one code still collide — the server answers that, as it did before.
      ⚠️ **This entry and Point 6.5's are inserted at the same anchor**, so PR #73 and this one will
      both touch `CHECKLIST.md` here. Keep both, 6.5 first.
- [x] **6.4** The catalog form stops asking a person to retype `DB-05`. `unit` and `service_type`
      become `<select>`s over their lists, `company` becomes an `<input list>` over a `<datalist>`,
      and a service gains an optional `name`.
      **Why the three are not drawn the same way.** The boundary does not treat them the same, and
      the form follows the boundary. `SaveCatalogItemRequest` says in its own docblock that `unit`
      and `service_type` are checked "for shape, not for membership", while
      `SaveCatalogItem::withListedCompany()` (Point 6.3) **registers** an unknown company rather
      than refusing it. So the two the server would have to refuse are closed sets — the owner's
      ruling (ج) recorded at 6.3 — and the one it adopts stays open, exactly as the owner asked for
      it: "optional droplist and the user can write it". `company` remains **required** (5.2's
      ruling), because open is not the same as optional.
      **`<datalist>` and not a combobox component.** It is the native control for "suggest, do not
      restrict", it is keyboard- and screen-reader-handled by the browser, and it needs no library
      and no focus management — which is the one place `CatalogItemFormModal` is already weak (debt
      28). Its `<option>` carries the **code** as its value and the locale label as its `label`,
      because the code is what the column stores after 6.3 and what `filter[company]` will match in
      6.5. `Str::slug` is idempotent on an already-derived code — **measured, not assumed**:
      `alpha_co → alpha_co`, `c_3m → c_3m` — so a picked suggestion round-trips to the same entry
      rather than registering a second one.
      **A stored value the list does not carry stays selectable.** `unit` was free text before this
      point, so rows exist whose value is in no list; a `<select>` cannot hold an option it was not
      given and the browser would report its **first** option instead — a silent rewrite of a column
      nobody touched. `selectOptions()` appends the stored value when the list has lost it. That is
      data-loss prevention, not a convenience, and it is the one test in this point that would fail
      silently in production rather than loudly.
      **Why a service gains a name.** `name` is `required_if:kind,product` **and** `nullable`, so a
      service may carry one and is never asked for one — no backend change was needed or made. Until
      now the Service tab's Name column had nothing to print but a dash on every row, because no
      control existed to fill it. Drawn with no required marker, since the server does not require it.
      **The lists load beside the page, not before it.** `CustomersView::loadSectors` already
      establishes the shape and the reason: `GET /managed-lists/{list}` names no permission, and a
      dropdown that could not be filled is one control short rather than a broken screen, so the
      failure is swallowed and the catalog still renders. Page 1 only, as the sectors are.
      **Checks: 1957 backend (11847 assertions) · 582 frontend (34 files) · `npm run build` clean ·
      pint PASS 459 files · PHPStan level 10 `[OK] No errors` · deptrac 0 / 0 on both configs.**
      No backend file changed, so the backend figure is 6.3's, unmoved. Frontend 577 → **582**, the
      five tests added here.
      **RED first, and read rather than counted:** the five new tests failed and the 43 existing ones
      passed, so the new assertions were the only thing failing. Two existing refusal tests then
      broke on GREEN — `setValue('repair')` on what was now a closed `<select>` cannot set a code the
      options do not carry, so validation blocked the submit and the server refusal under test never
      happened. That is the closed set doing its job, and the fixture list gained `repair` rather
      than the tests being rewritten.
      **Problems found: two.** (1) The two refusal tests above. (2) `CatalogView.spec.ts` indexes its
      `fetch` calls (`urlOf(mock, 1)`, `(…, 2)`) and mount now fires three more requests, which would
      have moved every indexed assertion. The helper was narrowed to the `/catalog-items` calls
      instead, so the index means what it says and does not move again when a fourth list is added.
      **Waste audit.** *Dead code:* every symbol added — `isSelect`, `selectOptions`, `entryLabel`,
      `loadLists`, `serviceTypes`, `catalogCalls`, the `noSelection` key and the
      `catalog-form-company-options` id — greps to **two or more** hits over `app/` and
      `resources/`, so none is a definition standing alone. *Duplicate logic:* found, and it was
      **this point's own**. `locale.startsWith('ar') ? entry.label_ar : entry.label_en` already
      exists three times under `pages/customers/`, and the first draft of this point wrote a fourth.
      It was removed inside the point: `entryLabel(entry, locale)` now lives beside `ListEntry` in
      `services/admin.ts` and the form imports it. The **three pre-existing copies are revealed, not
      created**, and go to the debt register rather than being migrated in an unrelated point.
      *Unused components:* no `.vue`, route or lang key was added without a caller; no guard moved,
      because no file was added. *Unnecessary complexity:* `selectOptions`'s fallback entry is the
      data-loss guard above and not a speculative option; `isSelect` is a type predicate that exists
      so `selectOptions` can be typed to the two fields it serves.
      **Not covered:** the **company filter control is still 6.5's** — `filter[company]` has had no
      caller since 5.2. `unit` and `service_type` are still **unvalidated against `enum_lists` on the
      server** (debt 23); this point closes the screen's half and not the boundary's, so another
      client may still send an unlisted unit. `company` is required on the server but **not in this
      form's `REQUIRED` mirror**, so a blank one still costs a round trip and comes back as the
      server's own sentence rather than as a required marker — reported on the debt register, not
      changed, because the approved point list does not name it. Nothing migrates the **old free-text
      `company` values** (§1.2's open decision, unchanged). No focus trap or focus return in the
      modal (debt 28), and the `<datalist>` inherits that. The stored-value fallback covers `unit`
      and `service_type` only — `company` needs none, being an open field.
- [x] **6.3** A company typed into the catalog form joins `DB-05`'s companies list, and the item
      stores its **code**. The owner ordered this started without answering the three questions
      6.2's plan raised, so it proceeds under the recommended answers, **each recorded here awaiting
      a `D-xx`**: (أ) the registration is a **system consequence of a permitted action**, not the
      actor exercising `admin.system_settings`, and the audit names the actor; (ب) `code` is derived
      from the typed text, **both labels take that text**, `position` goes last; (ج) `unit` and
      `service_type` stay closed sets.
      **Why the module crosses into Admin.** Point 5.1 made `company` a managed list so that "Acme",
      "acme" and "Acme Ltd" stop being three companies on one screen — `R-03`'s free-text risk, for a
      value §7.3 **groups** by and 5.2 made **filterable**. 5.2 then made it **required**, which left
      every catalog edit waiting on a Super Admin. This closes that: the list fills from use. The
      alternative — Catalog keeping its own idea of a company — is the exact duplication `DB-05`
      exists to prevent.
      **The `AdminContract` layer, on the precedent of three others.** `deptrac.modules.yaml` had one
      undivided `Admin` layer; it is now split `AdminContract` (Domain|Application) / `AdminDriver`
      (Infrastructure|Presentation), the split Audit, Storage and Identity already have and for the
      stated reason. **Nothing depended on `Admin` before this point**, so no ruleset moved except
      Catalog's, which gains `AdminContract` and points at nothing in `AdminDriver`. Allowed edges
      796 → **1007**.
      **`Str::slug` was measured before anything was built on it**, and the measurement changed the
      design twice: `شركة ألفا` → `shrk_alfa` (transliterated — ugly, stable, and the code is
      internal while both labels carry the real text), but **`3M` → `3m` fails `^[a-z][a-z0-9_]*$`**
      and a name of nothing but punctuation slugs to the **empty string** — which `required` +
      `regex:/\S/` lets through. Both are handled: a leading non-letter takes a `c_` prefix, and an
      empty slug becomes `c_` plus eight hex of the name's digest, so unrelated unsluggable names
      stay apart and the same name always maps to the same code.
      **Normalising is the feature.** Three spellings collapse to one code and therefore one entry —
      asserted directly. **Stated ceiling:** two genuinely different companies whose names reduce to
      one code share an entry, and two names over 64 characters agreeing on their first 64 share a
      code (`code` is `max:64`, the column's own limit).
      **`AddListEntry` is reused rather than the repository written to directly**, so the list write
      carries `LIST_ENTRY_ADDED` against the acting user for free — the audit is what makes ruling
      (أ) visible rather than silent. Its `ValidationException` on a duplicate is caught and ignored:
      reaching it means somebody added the same company between the read and the write, which is the
      state this wanted anyway.
      **1957 backend (11847 assertions) · 577 frontend (34 files) · `npm run build` clean · pint 459
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 (1638 and 1007 allowed).**
      **+11 tests / +49 assertions** against 6.2c's 1946/11798. Frontend **+0**: no screen changes —
      the form still sends free text and the server normalises it.
      RED first: **11 failed** — the eight provider rows and three named tests, and nothing else.
      **Deliberate break — one, and it caught a second problem on the way back.** The `c_` prefix for
      a leading non-letter was removed: **exactly two tests failed**, the `3M` provider row in both
      the list-membership and the stored-code assertions, 40 others passing.
      **Problems found: three.**
      (1) ⚠️ **The restore from that break did not reproduce the file.** `shasum -c` reported
      **FAILED** — re-inserting text left **two surplus blank lines** where the guard had been. This
      is the trap `CLAUDE.md` records verbatim ("restore with the inverse `sed`, never by
      re-inserting text"), hit by doing exactly what it forbids. **Nothing but the checksum would
      have caught it:** the tests passed, Pint passed, PHPStan passed. Removed and re-verified `OK`.
      (2) Pint refused the new import ordering (`class_attributes_separation`); fixed by running Pint
      on the file rather than by hand.
      (3) **A claim this point falsified**, found by the waste audit:
      `SaveCatalogItemRequest`'s docblock said keeping the membership promise "needs an
      `AdminContract` layer that deptrac does not have". It has one now. The comment is rewritten to
      say something more useful than its own obsolescence: **the obstacle is gone but the gap is
      deliberate**, because `company` is not *validated* against the list — it is *registered into*
      it — while an unknown `unit` would have to be **refused**, since §7.3 fixes the unit vocabulary
      in a way it does not fix the set of companies a business trades with. They are two problems,
      not one wearing a single name.
      **Waste audit.** *Dead code:* every added symbol grepped; nothing at one hit. *Duplicate
      logic:* `grep -rn "Str::slug" app/` returns **only this file**, so the code derivation exists
      once. *Unused components:* the falsified claim above — corrected here. *Unnecessary
      complexity:* no new class. `withListedCompany`, `codeFor` and `nextPosition` are private to the
      use case that needs them; a `CompanyRegistry` service with one caller would be the interface
      with one implementation the rules forbid.
      **Not covered:** **`unit`, `service_type` and `customers.sector` are still unchecked against
      `enum_lists`** — debt 23 stands, with its *reason* now corrected. **The form is unchanged**:
      it still sends free text, there is no drop-down, and a person typing "Alpha Co" gets an entry
      whose **Arabic label reads "Alpha Co"** until a Super Admin corrects it on the lists screen —
      accepted, and it belongs in the manual test list. **Existing rows keep their free-text
      company** and no migration normalises them, so a row saved before this point holds "Alpha Co"
      while a row saved after holds `alpha_co`, and `filter[company]` will not match both. That is
      the largest open consequence of this point and it wants a decision of its own.
- [x] **6.2c** The archived view and the restore control — 6.2b's endpoints, made reachable, and
      the point that closes the withdraw → restore loop on screen.
      **A view chooser, not a filter, because that is what the server offers.** `listEntries()` gains
      a third argument that picks the address rather than adding a query parameter: `ListingQuery` is
      shared with the FX-rate history and refuses `filter[...]` outright (Point 6.2b), so §6.2's
      "each resource declares its own filters" is answered by a second collection.
      **The chooser is drawn for a writer only, and the table for everyone** — the asymmetry is the
      whole reasoning. `GET .../archived` carries `admin.system_settings` while the live list carries
      none, because §8 puts Customers on six roles' screens and Catalog on five and not one renders
      without a sector, while **nothing renders from the archived set at all**. Offering a reader the
      chooser would offer a view whose every request is a 403.
      **No confirmation on the restore, and that is cited rather than assumed.** §6.6 lists what must
      be confirmed — archive, deactivate, rejection, return, approval — and a single restore is none
      of them. `CustomersView` already reads §6.6 the same way for the same act, and its comment says
      so. So the archive keeps its dialog and the restore does not get one.
      **The 409 is explained, not retried.** `OpenAPI §5.1`'s `state_transition_invalid` means the
      code this entry wants back was taken while it was away, which no amount of retrying fixes. The
      handler reads `ApiError.code` and shows a sentence naming the two ways out — withdraw the entry
      holding the code, or add this one under a different code — instead of the generic "try again"
      every other failure gets.
      **The add form is hidden in the archived view**, because a row added while looking at the
      archived set lands in the live list, out of sight of the person who just added it.
      **577 frontend (34 files, +6) · `npm run build` clean · 1946 backend (11798 assertions) · pint
      459 files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 (1631 and 877
      allowed).** Backend **+0**, as expected: no `.php` and no new `.vue` file.
      RED first: **5 of 6 failed**. The sixth — "draws no archived view for a reader who cannot
      write" — passed before any chooser existed, the same wrong reason 6.1's 404s and 6.2's
      permission test passed. **Third occurrence of that pattern**, and it is why the deliberate
      break matters more than the RED on this screen.
      **Deliberate break — one, restored by inverse replacement, `shasum -c` confirmed.** The 409
      branch was flattened to the generic message — the plausible "simplification", and one no RED
      could catch since both paths set an error and both render. **Exactly the 409 test failed**, 25
      others passed.
      **A side effect worth naming:** the restore button reuses `.chip`, which had a `transition`
      declared and no `:hover` to use it. Adding `.chip:hover` gives the **pagination buttons** one
      too — they had none. Source order was checked rather than assumed: `.chip:hover` sits at 648
      and `.chip-idle:hover` / `.chip-selected:hover` at 652 and 657, so the chooser's own hovers
      still win at equal specificity.
      **Waste audit.**
      *Dead code:* every symbol grepped, and all six new lang keys resolved **through both locale
      files and the source** rather than by a literal grep — the check Point 6.2 had to invent after
      a naive grep produced a false positive. Nothing at one hit; nothing dead.
      *Duplicate logic:* **one considered and deliberately not extracted.** `CustomersView` has
      `archivedFilter`/`archivedOnly`, the same *shape* as this screen's `view`/`showingArchived`.
      The mechanism differs — that screen sends `filter[is_archived]`, this one changes address — and
      what is common is a two-option `<select>`, which is markup rather than logic. **Stated ceiling:
      a third screen needing an archived toggle is when this becomes a component**, not before.
      *Unused components:* **none, and that is itself the finding.** The three previous points each
      turned up a stale copy of "there is no `PATCH`/`DELETE`"; this point falsifies no standing
      claim, and the sweep is clean.
      *Unnecessary complexity:* `switchView()` has one caller and four lines, and exists because the
      page number does not carry between two collections of different lengths — the same reason
      `choose()` resets it, stated in both places.
      **Problems found: none.** No gate failed on a first run, nothing was voided, and the deliberate
      break behaved exactly as predicted. Said explicitly rather than by omitting the row.
      **Not covered:** **there is no "restore all"** though `API-07` asks for "bulk operations for
      archive and restore" — `CustomersView` has select-all for exactly this and this screen does
      not; it stays on the register. **The archived view has no pagination test**, and its pager is
      the live one's code path with a different total, so a defect there would be invisible.
      **Nothing shows *when* an entry was withdrawn or by whom** — the audit log holds both and no
      screen renders it, which is the same gap Module 4 recorded. **Still no label editing.**
- [x] **6.2b** The way back out of the archive — `PATCH /managed-lists/{list}/{code}/restore` and
      `GET /managed-lists/{list}/archived`. Owner's ruling of 2026-08-31, after asking whether a
      withdrawal could be undone. **It could not.**
      **This half was documented before it was built, which is why it is a gap and not a feature.**
      §3.3 line 223 writes the permission row as a single merged **`archive / restore`**; §7's Flow 7
      gives archiving a **Restore** column; and §3.12 rule 4 names **"restore from archive"** among
      the mandatory audit entries — a rule that cannot apply to an action nobody can perform. Point
      6.1 shipped the withdrawal alone and left the row withdrawn permanently: the *code* could be
      re-used (the partial index frees it) but that creates a **new row with a new id**, and the
      audit then reads "archived" then "added", never "restored".
      **One permission for both directions**, so no matrix row was invented: `admin.system_settings`
      guards the restore exactly as it guards the withdrawal — the reading `routes/api.php` already
      applies to `PATCH /customers/{customer}/restore`, which carries `customer.archive` and has no
      `customer.restore` beside it.
      **The archived set is a route, not a `filter[archived]`.** `ListingQuery` is **shared with the
      FX-rate history** and refuses `filter[...]` outright, because §6.2 has each resource declare
      its own allowed filters and these two declare none. Teaching the shared parser one filter that
      only one of its resources may use is worse than a second collection, and §6.2 constrains query
      parameters rather than how many collections a resource has.
      **The archived collection carries the write permission, unlike the live list** — and the
      contrast is the reasoning. The live read is open because §8 puts Customers on six roles'
      screens and Catalog on five and not one renders without a sector or a unit; **nothing renders
      from the archived set at all.** It exists to serve the restore, so it answers to the same
      authority.
      **What the audit records, and what it deliberately does not.** `old_values` null, `new_values`
      `{list, code}` — the act, not the entry. A restore does not choose the labels; it clears
      `deleted_at`, and the labels it uncovers are the ones the archive already recorded on the way
      out, against the same `entity_id`. Copying them here would describe the row rather than what
      was done to it.
      **A defect this point found in its own work, measured and then fixed.** The entry first read
      "a restore can still fail on a collision … surfaces as a 500" — written from reasoning, not
      from a run. The rule against "should" applies to one's own report, so it was **probed**: the
      partial index is `(list, code) WHERE deleted_at IS NULL`, so restoring `banks` after somebody
      added a fresh `banks` answered **`PROBE STATUS: 500`**. Fixed inside the point rather than
      recorded as a gap: `restore()` reads the database's own `23505` and raises the
      `ListEntryAlreadyExists` the module already had — the exact shape `add()` uses, and for the
      same reason, since a read-then-write check is a race. The controller answers
      **`409 state_transition_invalid`** (`OpenAPI §5.1`), *not* a 422: the request is well-formed
      and names a real archived entry, and there is no field the caller sent that is wrong. The
      probe became the regression test, which also asserts the transaction rolled back and left
      **no** `LIST_ENTRY_RESTORED` row behind.
      **1946 backend (11798 assertions) · 571 frontend (34 files) · `npm run build` clean · pint 459
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 (1631 and 877 allowed).**
      **+11 tests / +75 assertions** against 6.2's 1935/11723. Pint 458 → 459 and the deptrac counts
      move because the point adds exactly one file.
      RED first: **8 of 10 failed.** The two that passed are the pair this file keeps producing —
      the "not found" cases, which Laravel already answers with a 404 when no route is registered.
      They were passing for the wrong reason and only became meaningful once the routes existed.
      **Deliberate break — one, restored by inverse `sed`, `shasum -c` confirmed.** `onlyTrashed()`
      was swapped for `withTrashed()` in the archived scope — the plausible slip, and the one no RED
      could have caught, since both spellings return rows and only one returns the *right* rows.
      **Exactly two tests failed:** the archived collection listing "the withdrawn and nothing else",
      and the withdraw → restore → withdraw round trip. 42 others passed.
      **Waste audit.**
      *Dead code:* every added symbol grepped — `RestoreListEntry` 3, `->restore(` 3,
      `LIST_ENTRY_RESTORED` 2, `scope` 19. **`function archived` reads as 1 hit and is not dead:** a
      controller action is reached through a route **string**, so the definition is the only textual
      match; `'archived'` is at 10 and its endpoint test passes, which is the evidence rather than
      the grep.
      *Duplicate logic:* none created. `scope()` **removed** duplication rather than adding it — the
      `where('list', …)` had to be built twice per page, once for the rows and once for the total,
      because an Eloquent builder is stateful and `count()` on the one already carrying
      `offset`/`limit` counts the page instead of the list.
      *Unused components:* **three found, all corrected here, and all the same defect recurring.**
      "There is still no `PATCH`" was written into `ManagedListsView.vue`, `services/admin.ts` and
      `routes/api.php`, and this point made all three false. ⚠️ **This is the third consecutive point
      to find a stale copy of the same claim** — 6.1 corrected two, 6.2 found a third, and 6.2b found
      three more. The claim keeps being restated in prose in several files at once, which is what
      makes it keep going stale. A fourth occurrence in `ArchiveListEntry` is a **quotation of what
      `routes/api.php` used to say** and is still accurate as history — left alone. **There is no
      SPA caller for either new endpoint**, which is Point 6.2c.
      *Unnecessary complexity:* the `bool $archived = false` parameter has two callers passing two
      different values, so it is not a parameter every caller passes the same value for.
      **Problems found: three.** (1) ⚠️ **A `python3` anchored replacement split `entry()` from its
      docblock.** The anchor was the function signature and the insertion landed between the two, so
      `/** @return array<string, mixed> */` came to sit above the *new* method — **PHPStan level 10
      caught it** (`missingType.iterableValue`) and nothing else would have. Anchoring on a signature
      without its docblock is the lesson. (2) Pint refused the interface's `@param` continuation
      alignment; fixed by running Pint on that file rather than by hand. (3) Both were **found by
      running the gates and reading them** — the point was not reported until they were green.
      **Not covered:** **no screen** — no "show archived" toggle, no restore button, no
      `restoreListEntry()` in `services/admin.ts`. That is **Point 6.2c**, and a service function
      added now would be a caller-less export, which is the waste this rule exists to prevent.
      **The collision answers 409 but no screen explains it** — a person who hits it will see
      whatever the SPA does with an unmapped 409, because there is no caller yet. **Still no label
      editing** — the only `PATCH` here is the restore. **Nothing bulk-restores**, though `API-07`
      asks for "bulk operations for archive and restore"; that is a documented requirement this
      point does not meet and is left on the register.
- [x] **6.2** The archive control on the Managed Lists screen — 6.1's endpoint, made reachable.
      **Branched from 6.1, not from `main`.** The endpoint it calls is in PR #64, so this is a
      stacked branch and **#64 has to merge first**; merging this alone would ship a button whose
      route does not exist.
      **`ConfirmDialog` is reused, not rebuilt.** It already takes `titleKey`/`messageKey`/
      `confirmKey`/`subject`/`busy`/`danger`, already traps `Escape` and already returns focus. Its
      `data-testid`s were **read before the tests were written**, not guessed, which is why the RED
      run failed on behaviour rather than on selectors.
      **§6.2's Danger variant, split across the two controls.** The documented row is "Archive,
      deactivate, reject", and the row button is drawn as an **outline** with danger-coloured text
      rather than a filled red button: it repeats on every row, and a table of filled danger buttons
      reads as a warning about the table. The filled danger answer is the dialog's, which is where
      the decision is actually made (`:danger` on `ConfirmDialog`). The new button carries
      `:hover`/`:active` and is added to the `prefers-reduced-motion` block — §6.2's "all button
      variants have … hover" applied to a button written *after* Point 6.0 rather than before it.
      **`SEC-09`'s presentation half**: both the column and the cell are `v-if="canEdit"`, so a
      reader who cannot write is not offered a control whose every use would be a 403 — the same
      reasoning this screen already applies to the add form, and the API refuses either way.
      **An edge this point had to answer:** archiving the last row of the last page leaves an empty
      page with a pager still offering the number it stands on. `choose()` solves the same problem by
      resetting to page 1; this steps back one instead, which keeps the person nearer to where they
      were.
      **571 frontend (34 files) · `npm run build` clean · 1935 backend (11723 assertions) · pint 458
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 (1605 and 862 allowed).**
      Frontend **+6 tests** (565 → 571). **Backend moved by 0**, and that is the expected result
      rather than a missing check: no `.vue`, `.css` or `.php` file was **added**, so no per-file
      provider moved — `ConfirmDialog` was reused instead of a new component being written, which is
      exactly why `NoHardCodedTextTest`'s inventory did not need an entry.
      RED first: **5 of 6 failed**, and the sixth is worth naming — "draws no archive control for a
      reader who cannot write" **passed before any control existed**, for the same wrong reason
      6.1's two 404 tests passed. It is the test the deliberate break below was aimed at.
      **Deliberate break — one, restored by inverse edit, `shasum -c` confirmed.** `v-if="canEdit"`
      was removed from the action cell — the plausible slip, and the one the RED could not prove
      against. **Exactly that test failed**, 19 others passed.
      **Waste audit.**
      *Dead code:* **one found, and removed inside the point.** A naive `grep` put `error.archive` at
      one hit, which the rule reads as dead; the grep was wrong (JSON nests the key, so the literal
      never appears), so every new key was instead resolved through both locale files **and** the
      source. That check found the real one: **`lists.archive.archived` was defined in both languages
      and rendered nowhere.** It was **deleted rather than given a home** — the row leaving the table
      already is the feedback, which is why the add form needs an "added" message and this does not.
      *Duplicate logic:* none. `ConfirmDialog` was searched for before anything was written and is
      reused by five screens now; no second dialog was introduced.
      *Unused components:* **one found — the third stale copy of a claim 6.1 falsified.** This file's
      own class docblock still said "There is no `PATCH` and no `DELETE` behind this screen". 6.1
      corrected `routes/api.php` and `services/admin.ts`; this one was missed and is corrected here.
      A sweep for further copies found three more mentions, all about **catalog items**, where
      "no DELETE at any permission" is still true (§3.12 rule 3) — left alone.
      *Unnecessary complexity:* none. No new component, no new service abstraction, no props added to
      `ConfirmDialog`. The page-step-back is four lines against a real empty-page bug, not a general
      pagination rework.
      **Problems found: three.** (1) The dead lang key above. (2) ⚠️ **`npm run test:unit` passing is
      not the frontend gate, again.** 571 tests were green and then `vue-tsc` failed with `TS2493`:
      two mocks written as `vi.fn(async () => …)` type `mock.calls` as an **empty tuple**, so
      destructuring `init` off them cannot compile. Fixed by giving those mocks the signature their
      own assertions read. (3) ⚠️ **A backend run was voided and re-run** — the locale edit landed
      mid-run and `tests/Feature/Localisation/SpaShellTest.php` reads the locale files. Both reads
      were 1935/11723; the reported figure is the re-run.
      **Not covered:** **nothing un-archives from this screen** — 6.1 clears `deleted_at` nowhere, so
      a withdrawal made by mistake is undone by adding the code again, not by restoring the row.
      **There is still no `PATCH`**, so a mistyped *label* cannot be corrected in place. The dialog's
      subject is the entry's **`code`**, not its label, because that is the value the endpoint
      addresses and the one that is unambiguous in both languages — a person who thinks in labels
      sees an internal name in the question. **No test asserts the button's colour or its hover**,
      for `jsdom`'s reason (debt 29); the outline-vs-filled decision above is unverified by machine.
- [x] **5.2** `company` required at the write boundary, and `filter[company]` on the list. The
      server half of the owner's ruling; the dropdowns are 5.3's and the filter control is 5.4's.
      **Why required, when §7.3 does not say so.** §7.3 lists "Providing team / company" in the
      **Service** column only and marks nothing required. **Owner's ruling of 2026-08-31, recorded
      here awaiting a `D-xx`:** it is required for **both** tabs, because §7.3 also opens "Two tabs:
      Product · Service, **grouped by company/team name**" — a row with no company falls out of the
      only grouping the screen has, and `EloquentCatalogItemDirectory` already has to invent a place
      for it (`nulls last`, Point 3.1). The **column stays nullable**: a `NOT NULL` migration would
      fail on the rows that already have none, so the rule lives at the boundary, the same shape
      `name` uses for `required_if:kind,product`.
      **`required` to create, `sometimes|required` to edit** — the shape `kind` already uses in this
      class. A `PATCH` that does not name the column is not asking to blank it; one that *does* name
      it must give a real value. `regex:/\S/` beside it for `name`'s reason: `required` accepts
      `"   "`.
      **Why `company` becomes filterable.** `CatalogItemListCriteria` said in as many words that it
      was *not* filterable and that "each is one line here when somebody makes it" — this is that
      line. `group_by=company` only **orders** the whole list (Point 3.1 deliberately kept §4.2's
      flat envelope), so a screen that groups by a column has to be able to ask for one group of it.
      Matched as written with `where`, exactly like `category`: both are the name of a thing rather
      than a code, so neither goes through `code()`. Left **outside** the `q` search intersection for
      the reason the class comment already gives about `category` — it does not split the screen in
      two the way `filter[kind]` does.
      **⚠️ The owner accepted the empty-companies bootstrap as a setup step (option ب, 2026-08-31).**
      `ManagedList::Companies` is seeded empty and `POST /managed-lists/{list}` carries
      `admin.system_settings`, so **on a fresh install the Super Admin must add a company before any
      catalog item can be created**. That is deliberate, not a defect, and **it belongs in the manual
      test list as a named first step**.
      **1918 backend (11633 assertions) · 553 frontend (34 files) · `npm run build` clean · pint 451
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 (1583 and 796 allowed).**
      RED first, and **both halves failed for the documented reason rather than merely failing**:
      the write test answered **201, not 422** (the rule did not exist yet), and the list test
      answered **400, not 200** — which is the exact "undeclared filter" refusal `OpenAPI §6.2`
      requires and the before-state this point removes.
      Delta **measured, not predicted**, by running `tests/Feature/Catalog` with the change and again
      under `git stash`: **89 tests / 571 assertions → 86 / 556**, so **+3 tests and +15
      assertions** — the whole of 1915/11618 → 1918/11633. No guard moved, because no file was added.
      **Deliberate break — one, restored and `shasum -c` confirmed.** The subtle half of this change
      is `sometimes`, not `required`: a plain `required` on the `PATCH` branch is the plausible slip,
      and it is invisible to every test that only creates. Dropping `sometimes` failed **exactly the
      five `PATCH` tests** — edit-changes-only-what-it-names, deactivation-is-an-edit, the edit audit
      pair, and editing-an-unknown-item — and nothing else. Restored with the inverse `sed`, never by
      re-inserting text.
      **Problems found: two.**
      (1) The first draft wrote `company` as a flat `['required', ...]` for both verbs. That is the
      defect the break above hunts, written by hand: it would have made **every** `PATCH` resend the
      company, including the deactivate toggle. Caught by reading `kind`'s own two-branch rule three
      lines above it, before any test ran.
      (2) Five existing write tests built payloads with no company. Two are the `product()` /
      `service()` helpers — one line each — but three build a payload inline to test something else
      (`no kind`, `unknown kind`, `blank name`). Those already asserted 422 and would have kept
      passing **for the wrong reason**, so `company` was added to each so they still fail only for
      the thing they name. Anchors were replaced by an asserted script, one match each, 5/5.
      **Not covered:** the server still validates `company` for **shape, not membership** — nothing
      checks it against `enum_lists`, so any 255-character string is accepted and debt entry 23
      stands unchanged. The screen is **not** touched here: `CatalogItemFormModal` already renders a
      company input on both tabs and will now receive a 422 naming the field, drawn by the shared
      `error.messageFor()` mapping — but the field carries **no required marker**, so the refusal
      arrives on submit rather than before it. That, the dropdown, and the service's optional `name`
      are Point 5.3; the filter control is 5.4. **Every existing `catalog_items` row keeps its
      free-text company** and there is no data migration — a row whose company is not a listed one
      still saves, because membership is unchecked.
- [x] **6.0** The undefined colour token, the missing hover states, and the submit button's place.
      Owner-ordered after testing the running app; the point list for Step 6 was published and
      approved first, and **the owner approved crossing into Module 2's `Admin` screens** the same
      way Point 5.1 did.
      **The complaint was "the text in the coloured box is dark". The cause was not a colour
      choice.** Three screens wrote `var(--color-on-primary)`, and **this project has never defined
      that token anywhere** — `Design_System_EN.md` §3.2 and `resources/css/tokens.css` both name it
      `--color-primary-text`. A `var()` with no fallback and no definition makes the whole
      declaration *invalid at computed-value time*, so `color` is dropped and the element inherits
      the parent's — the dark `--color-text`. Nothing failed, because nothing was looking.
      Fixed in **`ManagedListsView` (×2), `SystemSettingsView:447` and `SystemLimitsView:287`** — the
      owner reported one screen; the defect was on three, and fixing only the reported one would
      have left the other two wrong.
      **`LogicalPropertiesTest::test_that_every_colour_token_a_component_names_is_defined`** is the
      new guard, added to the scanner that already reads styling as source text rather than to a new
      file. It reads every `--color-*` declaration out of `tokens.css`, then every `var(--color-*)`
      reference out of every `.vue` and `.css` under `resources/`, and fails naming each reference
      with no definition. Scoped to `--color-*` deliberately: that is the family §3.3 tabulates and
      the one whose failure mode is silent inheritance. A missing `--shadow-*` is visible.
      **Hover, cited rather than invented.** §6.2: "All button variants have default, **hover**,
      active, focus, disabled, and loading states." `ManagedListsView` had **zero** `hover` in it
      (`grep -c hover` → 0) while its `<style scoped>` already declared
      `transition-property: background-color, color, border-color` — a transition wired to nothing.
      The shape used is `LoginView.vue:216`'s, unchanged: `:hover:not(:disabled)` →
      `--color-primary-hover`, `:active:not(:disabled)` → `--color-primary-active`. The unselected
      chip has no fill at rest, so its hover supplies one (`--color-surface-muted` + a primary
      border); the selected chip darkens through the same pair as the button, so the Web Interface
      Guidelines' "interactive states increase contrast" holds in **both** chip states, not just the
      selected one. `prefers-reduced-motion` was already honoured and is untouched.
      **The submit button** was a flex sibling of the four fields inside `flex-wrap`, so it settled
      beside Order. It is now wrapped in a `w-full` div — **the line-break mechanism the two status
      messages under it already used**, so no field needed re-indenting and no `data-testid` moved.
      `self-start` was dropped with it: inside a block wrapper it addressed nothing.
      **1916 backend (11620 assertions) · 553 frontend (34 files) · `npm run build` clean · pint 451
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 (1583 and 796 allowed).**
      ⚠️ **This branch is cut from `main` (`d8ea2d2`), not from Point 5.2's branch**, so its baseline
      is 1915/11618 and the delta is **+1 test / +2 assertions** — the one new scan and its two
      assertions. Predicted before the run and matched it exactly. No per-file provider moved,
      because no file was added.
      RED first: the guard named **exactly three files and no others**, which is also how the blast
      radius was established as fact rather than estimate.
      **Deliberate break — one, restored by inverse `sed`, `shasum -c` confirmed.** The RED above
      proves the guard catches the defect it was written for; it does not prove it catches the
      *next* one. So `--color-primary-hover` was mistyped `--color-primary-hovr` — the plausible
      future slip — and the guard named that file and that token alone.
      **Problems found: two.**
      (1) ⚠️ **The edits were made while still checked out on `main`.** The previous point ends by
      rebuilding the served bundle from `main` (§2.2 of the handoff), and the branch was never cut
      again afterwards. Caught by `git branch --show-current` **before any commit**, and the work was
      moved with `git checkout -b`; nothing reached `main`. This is the exact trap the handoff
      records, and it fired at the exact seam the handoff predicts.
      (2) `grep -rln ":hover" resources/js` returns **7 files**, and only **one** of them
      (`LoginView`) styles a *button* hover — the rest are table rows. So the gap this point closes
      on three screens is open across most of the application. Recorded as debt rather than fixed
      here, because widening the point past the approved list is not this point's call.
      **Not covered:** **no test asserts that a hover rule exists or what colour it produces.** A
      `jsdom` suite does not apply `<style scoped>` and cannot see a colour — debt entry 29 already
      says so — and the new guard checks only that a token is *defined*, never that it is the *right*
      one or that the contrast it yields passes 4.5:1. **The appearance is unverified by machine and
      needs the owner's eye.** It could not be verified in a browser either: the screen is behind
      authentication and entering credentials is not something I do. Nothing here touches the delete
      or archive of a list entry — that is Points 6.1 and 6.2, and the repository still exposes only
      `entriesFor`, `page` and `add`.
- [x] **6.1** `DELETE /managed-lists/{list}/{code}` — the archive. Owner's ruling of 2026-08-31,
      recorded here awaiting a `D-xx`: **option (أ)** — nothing cascades — and the word is
      **archive**, not delete.
      **This route was previously refused in writing, and that refusal is rewritten rather than
      ignored.** `routes/api.php` carried a comment saying "**No `PATCH` and no `DELETE`.** `DB-01`
      forbids physical deletion, and withdrawing a sector customers are already filed under is a
      decision with consequences". Its first half was always about a **hard** delete, which this is
      not — this soft-deletes, so `DB-01` holds. Its second half was a **decision**, and the owner
      has now made the other one. Point 5.1 is what forced the question: `companies` is seeded empty
      and filled by hand, so a mistyped company name was permanent until this existed. The comment
      is replaced in the same commit, and so is `services/admin.ts`'s copy of the same claim.
      **The read half was already true and already documented.** `EnumListEntry` has used
      `SoftDeletes` since Point 1.3, and `ManagedListRepositoryInterface`'s docblock has said since
      Point 2.2 that "archived rows do not come back at all (`DB-01`, `D-34`)". So this point adds a
      write and nothing else; `test_that_an_archived_entry_is_not_offered` proved the read side
      before by stamping `deleted_at` by hand, and the new test reaches the same state through the
      API.
      **The shapes are borrowed, not invented.** `200` with `{"archived": true}` rather than `204`,
      because `OpenAPI §3.3` puts a request id on every response and §4.1 puts it in `meta`, which a
      204 has no body to carry — `RoleController::destroy` writes that reasoning out and this
      mirrors it, including `archived` over `deleted` (`DB-01`: the row is still there). The audit's
      `new_values` is **null**, which is the shape `AuditRecorderInterface` documents in as many
      words for a delete — the mirror of the create's null `old_values`. `admin.system_settings`,
      the same authority as the `POST`, for the reason `routes/api.php` already gives: §3.11 has no
      row for managed lists.
      **1925 backend (11666 assertions) · 553 frontend (34 files) · `npm run build` clean · pint 452
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 (1597 and 803 allowed).**
      Branch cut from `main` (`d8ea2d2`), baseline 1915/11618, so **+10 tests / +48 assertions** —
      the ten new endpoint tests. Pint 451 → 452 and the deptrac allowed counts move because the
      point adds exactly one file.
      ⚠️ **The first full-suite run was voided and re-run.** The `services/admin.ts` comment fix
      landed after that run started, and `LogicalPropertiesTest` scans `ts`. Both runs read
      1925/11666; the reported number is the **second**.
      RED first: **8 of the 10 failed**, and reading *which* two passed mattered — the two 404 tests
      passed before any code existed, because Laravel already 404s a route that is not registered.
      They were passing for the wrong reason and only became meaningful once the route existed.
      **Deliberate break — one, restored, re-run green.** `LIST_ENTRY_ARCHIVED` was renamed
      `LIST_ENTRY_REMOVED`; **exactly** the audit test failed, 33 others passed.
      ⛔ **The break I wanted most could not be run.** Stripping `permission:admin.system_settings`
      off the new route — to prove `test_that_a_manager_may_not_archive_an_entry` detects an
      unguarded route rather than merely a missing one — was **refused by the environment's
      permission classifier**, correctly, since the command reads as removing an authorisation
      guard. It was not attempted by another route. `routes/api.php` was verified untouched by
      `shasum -c` afterwards. **So that test's RED was "no route → 404", never "no permission →
      200", and the guard is unproven in that direction.** Named in the report and left for the
      owner to decide.
      **Waste audit** — the first point under the new rule.
      *Dead code:* every symbol added was grepped over `app/`, `routes/`, `tests/` and
      `resources/js` — `ArchiveListEntry` 3, `->archive(` 2, `liveEntry` 2, `destroy` 3,
      `LIST_ENTRY_ARCHIVED` 3. None sits at one hit, so none is dead.
      *Duplicate logic:* **one found, and removed inside this point.** `ArchiveListEntry` first
      carried its own `identifierOf()` — a byte-for-byte copy of `AddListEntry`'s raw
      `enum_lists` query. The fix removed the need rather than sharing the copy: `archive()` now
      returns the archived row's id, since the repository is the layer holding the row and a caller
      left to fetch it has to reach past the interface into the table. Verified: `grep -c
      identifierOf ArchiveListEntry.php` → 0.
      *Unused components:* **one found.** `services/admin.ts` said `POST` was "the only write this
      resource has" and that no `DELETE` existed — false the moment this route landed. Corrected in
      the same commit, and it now says plainly that **no SPA caller exists yet**, which is 6.2.
      *Unnecessary complexity:* `ConnectionInterface` is still used (the transaction) and is not a
      dead dependency. The two null checks in `handle()` are **not** redundant: `liveEntry()` reads
      without a lock, so the second is a genuine race guard. `liveEntry()` scans `entriesFor()`
      rather than opening a second query path — the largest seeded list has six entries, and a
      second path would need its own guarantee of agreeing with the first. Stated ceiling: if a
      managed list ever grows past a few hundred entries, that scan is the thing to replace.
      **Problems found: two.** (1) The `identifierOf` duplication above — created and removed inside
      the point, and it is what the new waste-audit rule was written to catch. (2) The voided
      first suite run, above.
      **Not covered:** **no screen.** There is no archive control, no confirm dialog and no
      `deleteListEntry()` in `services/admin.ts` — all of that is Point 6.2, so today the endpoint is
      reachable only by an API client. **There is still no `PATCH`**, so a mistyped *label* remains
      uncorrectable — only the whole entry can be withdrawn and a fresh one added. **Nothing
      un-archives:** the row keeps `deleted_at` forever and no endpoint clears it, so a withdrawal
      made in error is fixed by adding the code again, not by restoring the row — the audit log then
      shows both events, which is the honest history but not a restore. And the authorisation guard
      is unproven in the direction described above.

**Acceptance criteria**
- [x] New product appears under the Product tab, grouped by company *(Points 4.3 and 4.4, both
      halves. **Appears under the tab, grouped:** the Product tab asks `filter[kind]=product` and
      every load asks `group_by=company`, both asserted on the query string, and the company
      headings are asserted to appear once per company in the server's own order — including a
      heading of their own for the items with no company. **New:** the create button starts the form
      in the tab the person is standing on (asserted in both tabs), the payload carries
      `kind: 'product'` with §7.3's product fields, and a save closes the dialog and re-asks the
      server so the new row arrives in its group rather than being pushed into the page by the
      client. The write itself is `CatalogWriteEndpointTest`'s, per `D-67`.)*
- [~] Red-rated supplier → red chip beside their name on **every** screen *(Point 4.1 put the chip
      on the Suppliers screen; **Point 5.0 made its colour actually visible.** ⚠️ The wording here
      previously claimed the chip carried "§7.1's word as Design System §6.4 requires" — that
      citation was **half of §6.4**, which asks for an "icon + text/chip". The chip had no icon and
      its 14% fill computed to a grey, and the owner found that on the running screen after this
      line had already been written. It now carries the circle and a real border, and
      `SupplierRatingChip.spec.ts` guards both. **"Every screen" still cannot be closed here** — the
      other screens that name a supplier are Module 6's supplier quotations and Module 11's
      procurement, and neither exists. **The degree of tint remains unguarded**, because nothing in
      a `jsdom` suite can see a colour.)*
- [x] Service appears under the Service tab, separate from products *(Points 4.3 and 4.4. The
      Service tab asks `filter[kind]=service`, so the separation is the server's `WHERE` and not a
      client-side split, and the two tabs draw §7.3's two **different** column lists — asserted in
      both directions, that the Product tab shows Unit and no Service type and the Service tab the
      reverse. Point 4.4 carries the same split into the form: creating from the Service tab sends
      `kind: 'service'` with `service_type` required and **no** `unit` or `product_code` in the body
      at all, asserted on the decoded payload. ⚠️ See Point 4.4's second finding: the Service tab's
      **Name** column is always `—`, and which way to repair that is an owner's ruling.)*
- [ ] Deactivated product is hidden from new selection lists
- [x] Catalog holds **no prices** — descriptive data only *(Point 1.2: `catalog_items` has no
      numeric column at all; asserted three ways — §7.3 still forbids it, no `numeric` column
      exists, and no column name matches `/price|cost|margin|amount/i`. Proved by adding a real
      `money('price')` column and watching that one test fail.)*
- [x] Every catalog edit is written to the audit log *(Point 3.2: `SaveCatalogItem` records
      `CATALOG_ITEM_CREATED` / `CATALOG_ITEM_UPDATED` inside the same transaction as the write
      (`DB-11`), with `AUD-02`'s old values limited to the fields the write touched and no row at
      all for a PATCH that changed nothing. `D-45` makes this the mitigation for opening catalog
      editing to every employee. Proved by renaming the update event on purpose and watching that
      one test fail.)*
