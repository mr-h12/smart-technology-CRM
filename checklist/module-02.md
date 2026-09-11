> Frozen history of Module 2, cut verbatim from `CHECKLIST.md` on 2026-09-12 (commit `f8b993c`).
> Open boxes here are stale copies — the live ones are in `CHECKLIST.md`. Do not tick here.

## Module 2 — Settings, Managed Lists & Currencies

> As a system administrator, I want to configure company details, currencies and lists, so that the
> system runs on the company's real data.

**Tables** `settings` · `currencies` (+ rounding unit) · `fx_rates` + history · `enum_lists` ·
`system_limits`

#### Step 1 — schema *(point order approved 2026-08-27; key/value settings and managed lists mapped
to `admin.system_settings`, both approved by the owner in the same turn)*

- [x] **1.1** `settings` and `system_limits`, key/value.
      **Two tables because §3.11 lists two permissions** — *system settings* and *system limits
      (SLAs, thresholds)* are separate rows and separate §13 screens (4 and 6). Both are Super
      Admin only today, and §3.12 rule 5 lets a row be regranted without a deployment, so the
      split keeps the authorisation check at the table instead of inside a `WHERE` one query
      could forget. **Key/value because `AP-08` and `D-75`**: the lockout duration moves here
      *"where an administrator changes it without a deployment"*, and a column-per-setting table
      would need a migration for every new key — the deployment `D-75` exists to avoid.
      **`value` is `text`, never numeric (`DB-07`)**: a settings table that types the column
      `double precision` for one fractional limit has a float in the schema whatever the
      application casts it to. The type travels in `value_type`, checked against five literals.
      Uniqueness on `key` is **partial, `WHERE deleted_at IS NULL`** — `DB-01` soft-deletes, so a
      plain UNIQUE would let one archived row reserve a key permanently; Laravel silently ignores
      `unique()->where()`, so it is raw DDL. A blank key is refused by CHECK.
      **17 tests / 44 assertions.** Seven deliberate breaks, each with real output: the unique
      predicate inverted · the index made non-partial · each CHECK removed in turn · `value` made
      `double precision` · `down()` emptied (`DEV-03`) · the master-documentation mount removed,
      proving §4.8 is *read* rather than restated. Restoration verified with `shasum -a 256 -c`.
      **Three tests passed vacuously before they were fixed** — a missing table returns no float
      columns and throws on every insert, so "something threw" was accepting SQLSTATE `42P01`.
      They now assert `23505` and `23514` by code.
      **Not covered:** no rows, no model, no repository, no API, no permission enforcement — 1.1
      is storage only. `value_type` is five literals in a migration; the enum that pins them is
      Point 2.3. Nothing yet reads `identity.lockout_minutes` from this table.
- [x] **1.2** `currencies` (+ rounding unit and its on/off switch, `D-65`) and `fx_rates` with history.
      **The unit and the switch are two columns.** `D-52` makes the unit per-currency, `D-65` makes
      rounding optional, and `RoundingRule` already models them apart — switching rounding off keeps
      the unit so switching it back on need not invent one. A nullable unit meaning "off" would make
      `NULL` do a boolean's work.
      **Exactly one base currency**, held by a partial unique index on `is_base`: §13 screen 5 names
      it in the singular, `Currencies::base()` returns one, and with two `DB-06`'s `base_amount` has
      no defined meaning. Archiving one and naming another still works.
      **A rate is history, enforced by the database.** §5.3 and this module's acceptance criterion
      require an edit to leave the old rate standing and `AP-06` files rates under append-only
      critical data, so a `BEFORE UPDATE` trigger refuses any write that moves `rate`, either
      currency, or `effective_from` — SQLSTATE `FXH01`, the idiom `make_audit_log_append_only`
      established. **`deleted_at` stays writable on purpose:** `DB-01`'s soft delete *is* an
      `UPDATE`, and a blanket ban would forbid it. There is a test for each half.
      **19 tests / 48 assertions.** Nine deliberate breaks with real output: the single-base index
      removed · the code index made non-partial · each CHECK weakened in turn (`rounding_unit >= 0`,
      `rate >= 0`, the self-pair check) · `fxRate` swapped for `money`, caught by comparing the
      column's scale against `ExchangeRate::SCALE` rather than against the migration · the trigger
      given `WHEN (false)` · the trigger made unconditional, which broke the soft delete · `down()`
      emptied. Restored byte-identical, `shasum -a 256 -c`.
      **A defect the filtered run hid.** `php artisan test --filter` was green while the full suite
      failed: Point 1.1's rollback test called `migrate:rollback --step 1`, which means "whatever
      migrated last" — and 1.2 landed behind it, so the test rolled back *this* migration and then
      reported that `settings` had survived its own `down()`. Both tests now roll back by
      `--path`, and both were re-broken afterwards to prove the rewritten check still fails.
      **Not covered:** no rows — EGP/USD/EUR and their §5.3 units are Point 2.1. No model, no
      repository, no endpoint, no `admin.fx_rates` enforcement, and **no audit entry** — §3.12
      rule 4 makes one mandatory on an FX change and that belongs with the write path in 3.3. The
      trigger stops a rate being edited; it does not record who tried. Nothing reads `is_base` yet.
- [x] **1.3** `enum_lists` — the four `DB-05` lists in one table.
      **One table, not four.** The lists differ only in what references them and every screen reads
      them the same way. `DB-05` forbids the *membership* living in code — which is this module's
      acceptance criterion, a new sector appearing in the customer form without a deployment — while
      the *set of lists* is fixed by the columns that point at it, as `ManagedList` already records.
      **The `list` column is CHECKed against four literals, pinned to `ManagedList::cases()` by the
      test.** The migration cannot read the enum and the enum cannot read the migration, so drift
      fails here rather than at the first screen rendering an unknown list. Proved by adding a fifth
      case to the enum and watching two tests fall.
      **Both labels are NOT NULL** (§14.2, and `ListEntry` already takes both as non-nullable).
      `roles.name_ar` is the counter-example — nullable, null for all eight rows, an English word on
      an Arabic screen. Uniqueness is `(list, code)` and partial: `other` is a sector *and* a unit,
      and an archived code must be reusable (`DB-01`).
      **`position` is deliberately not unique.** No document says what two entries sharing a position
      should mean, and a constraint invented here would be a rule the documentation never made.
      **16 tests / 36 assertions.** Nine deliberate breaks with real output: a `DB-05` list dropped
      from the migration · the list CHECK weakened to `IS NOT NULL` · uniqueness made global, then
      made non-partial · the blank-code and negative-position CHECKs weakened · `label_ar` made
      nullable · `down()` emptied · a fifth case added to `ManagedList`. Restored byte-identical.
      **Problems: none.** The first RED was 15 failed / 1 passed, and the one pass was correct —
      the doc-versus-enum drift check does not touch the table.
      **Not covered:** no rows — §4.2's sectors, §7.3's units and service types are Point 2.2, and
      **`delivery_terms` has no documented values at all**, which `ManagedLists` already records.
      No model, no repository, no endpoint, no `admin.system_settings` enforcement (the mapping the
      owner approved on 2026-08-27), and nothing yet references an entry: `customers.sector_id`
      arrives with Module 3.

#### Step 2 — domain and seeding *(point order approved 2026-08-27)*

- [x] **2.1** §5.3's currencies as rows, and the domain reading them back.
      **The seeder creates and never overwrites.** `IdempotentSeeder` defines repeatable as not
      duplicating a row, not bumping a counter, and **not overwriting an edit somebody made
      deliberately** — and §5.3 makes the unit editable under System Settings with `D-65`'s switch
      beside it. `updateOrCreate`, which `RolePermissionSeeder` uses correctly for a matrix the
      document owns, would here undo an administrator's documented action on the next deployment.
      A test edits USD to `0.05`, switches its rounding off, re-seeds, and requires both to survive.
      **The units are read out of §5.3, not out of `Currencies`.** A test comparing the seeder
      against the class the seeder loads is defect #4 on this project's list — self-consistent, and
      blind to both drifting from the document together. Proved by moving USD to `0.05` in the
      class and watching two tests fall.
      **`CurrencyRepositoryInterface` reads the table, and a test proves it is not reading the
      class**: it edits the EUR row and requires the repository to report the edit. `AP-08` and
      §3.12 rule 5's argument, applied to money.
      **No FX rate is seeded.** `Currencies::seededRates()` offers exactly one and calls it a
      tautology — EGP against itself — and Point 1.2's `fx_rates_distinct_currencies` CHECK refuses
      it, correctly: a currency priced against itself is not a rate. The identity belongs in
      conversion code; every real rate is entered by a human under §3.11's `FX rates`.
      **11 tests / 24 assertions.** Seven deliberate breaks with real output: `updateOrCreate` ·
      `seedsTestData()` true (`DEV-08`) · the wrong base currency · rounding seeded off · the
      repository answering from `Currencies` · `withTrashed()` · the class drifting from §5.3.
      Restored byte-identical.
      **Two boundary findings, both real.** deptrac reported **3 violations and 1 uncovered** the
      moment Admin gained its first Eloquent model: `Admin: ~` denied it Illuminate, and the model's
      `Precision::CAST_MONEY` import made it the **only class in any module reaching into
      `App\Support`**. The ruleset now reads `Admin: [Framework]` — which does *not* weaken the
      Domain rule, that being `deptrac.layers.yaml`'s empty Domain ruleset in a separate config —
      and the cast was **removed rather than re-homed**: PostgreSQL returns NUMERIC as a string
      (`pdo numeric -> string (1.500000)`, measured), `RoundingRule` does BCMath over that string,
      and a cast would add a conversion `DB-07` forbids. No `AdminContract`/`AdminDriver` split
      yet — nothing outside Admin points at it, and Identity's split exists for a crossing that
      actually happened.
      **Not covered:** managed-list rows are 2.2 and settings/limits rows are 2.3. No endpoint, no
      permission enforcement, no audit entry. The repository reads; nothing writes. `fx_rates` is
      still empty, so no conversion is possible yet.
- [x] **2.2** the four `DB-05` lists seeded from `ManagedLists`, and read back through a repository.
      **The acceptance criterion, half of it, is now provable:** *a new sector added in settings
      appears in the customer form without a deployment*. A row inserted after seeding is returned
      by `ManagedListRepositoryInterface` with no code change, and survives the next seeder run.
      The other half — the customer form — is Module 3, and the criterion stays unticked until then.
      **The seeder creates and never overwrites** (`IdempotentSeeder`), which here is a requirement
      rather than manners: `updateOrCreate` would undo an administrator's rename on the next
      deployment and re-create a withdrawn entry. Nothing is deleted either — an entry that
      disappears from `ManagedLists` stays (`DB-01`), because withdrawing a sector customers are
      filed under is a decision, not a side-effect of running a seeder.
      **`delivery_terms` is seeded empty, deliberately.** `DB-05` names the list and no document
      gives it a single value; `ManagedLists` already recorded that. The test pins the emptiness so
      it reads as a decision rather than an oversight.
      **The members are compared against §4.2 and §7.3 in the mounted documentation**, not against
      the class the seeder loads — a comparison with its own source is defect #4 on this project's
      list. Proved by renaming `banks` to `bank` in `ManagedLists` and watching the check fail.
      **13 tests / 35 assertions.** Seven deliberate breaks with real output: `updateOrCreate` ·
      `seedsTestData()` flipped (`DEV-08`) · Arabic labels seeded blank (§14.2) · the repository
      answering from `ManagedLists` instead of the table, which broke three tests including the
      acceptance criterion · `withTrashed()` · the display order reversed · the class drifted from
      §4.2. Restored byte-identical (`shasum -a 256 -c`).
      **Problems.** The documentation parser was wrong twice before it was right — §7.3 writes the
      units *inside* a table cell that continues afterwards (`| Unit (piece · metre · kilo ·
      extendable) | Active service (yes/no) |`), so the row's next cell was parsed as a fourth unit.
      Fixed by ending the list at its closing parenthesis; the logic was checked in isolation before
      the third edit rather than guessed at again. PHPStan then rejected `pluck()->all()` as
      `array<mixed>`; narrowed with `assertIsString` rather than a cast, and the drift break was
      re-run afterwards to prove the rewritten helper can still fail.
      **Not covered:** no endpoint, no `admin.system_settings` enforcement, no ordering or renaming
      through an API — Step 3. Nothing references an entry yet; `customers.sector_id` is Module 3.
      No uniqueness on `position`, so the repository orders by `(position, code)` to keep ties
      stable between requests.
- [x] **2.3** `settings` and `system_limits` seeded, and `D-75`'s `identity.lockout_minutes` moved
      out of `config/identity.php` behind an interface.
      **One row is seeded, and the emptiness around it is the point.** §13 screen 6 names five
      limits and screen 4 names ten settings; the documentation gives a value to **one** of them.
      `D-17` says the stale-deal threshold is *"configurable in settings"* and stops; §11 says
      deadlines and SLAs *"come from settings"* and stops. A seeded default for any of those would
      be a business rule nobody wrote, arriving as configuration and read as fact — the refusal
      `ManagedLists` makes for delivery terms and `Currencies` for exchange rates. `settings` is
      seeded **empty**, and a test pins that so it reads as a decision rather than an omission.
      **The table overrides configuration; it does not replace it.** With no row the reader answers
      from `config/identity.php`, so Module 1's behaviour and all of its tests are unchanged. With a
      row, the row wins — proved end to end in `AuthenticationTest`: a stored 45 locks an account
      for 45 minutes while `Config` still says 30. A malformed value falls back rather than failing
      closed, because `(int) 'half an hour'` is `0` and a zero-minute lock never locks.
      **The contract lives in `app/Support/Settings`, not in Admin.** `deptrac.modules.yaml` gives
      every module an empty ruleset, and Identity learning Admin's name is a crossing that needs its
      own decision. The alternative — splitting Admin into `AdminContract`/`AdminDriver` the way
      Storage and Audit are split — is recorded in the interface's docblock as the shape a future
      `D-xx` may prefer; it was not taken as a side-effect of a seeding point.
      **Both deptrac configs gained a narrow `SharedContracts` layer** (`^App\Support\Settings\.*`,
      not all of `App\Support`) so the crossing is named rather than uncovered: the first run after
      wiring reported **Uncovered 2**, and an uncovered line does not fail the build.
      **11 tests / 17 assertions across two files.** Seven deliberate breaks with real output: the
      numeric guard removed, so a typed word became a zero-minute lock · `whereNull('deleted_at')`
      dropped · the table ignored entirely · the seeder rewriting its row each run · the unit
      nulled · an undocumented `deals.stale_threshold_days` invented · Identity hard-coding 30
      again. Restored byte-identical (`shasum -a 256 -c`).
      **Problems.** Pint's `ordered_imports` was "fixed" three times without effect: `pint <path>`
      was given the host path while the container's working directory *is* `crm/`, so the fixer ran
      against a file that does not exist and reported success. Caught only by re-running `--test`.
      **Not covered:** no endpoint and no `admin.system_limits` enforcement (Step 3); no cache
      (`PRF-08`, Point 4.2) — the reader queries per call, deliberately. `files.max_size_bytes`
      (`D-71`) and the challenge TTLs stay in `config/`: `D-75` names only the lockout duration.
      The seeded 30 is still an interim awaiting the owner's answer.

#### Step 3 — the API *(point order approved 2026-08-27)*

- [x] **3.1** `GET` and `PATCH /api/v1/settings`, behind `admin.system_settings`.
      **§3.11's row, asserted rather than assumed.** *system settings* is the Super Admin's and
      `—` for everyone else, including the Manager, who holds *FX rates* on the row below it. Three
      negative tests cover Manager read, Manager write and a sales employee — §3.12 rule 1 puts
      enforcement at the API, so the refusal is the feature.
      **The editable fields are §13 screen 4's, and only those.** A key/value table accepts
      anything; `SystemSetting` is what makes the endpoint answer `haxx.enabled` with a 422 instead
      of storing it. The *values* stay the business's — nothing is seeded — but the *field names*
      are the document's. **Two of §13's ten are deliberately absent:** the logo needs a `files`
      row, an upload endpoint and `SEC-15`'s scan (Module 5), and the PDF and email templates need
      whatever a template *is* (§16, Module 9). Both are owed and named here rather than dropped.
      **`SETTINGS_UPDATED` is audited** although §3.12 rule 4 does not list it: `AUD-01` asks for a
      comprehensive audit, and the company name this screen edits is printed on every quotation §16
      generates. `AuditEnforcementTest` caught the new writer on its first run, exactly as designed.
      **Two boundary changes, both named.** `Admin → AuditContract` is added to
      `deptrac.modules.yaml` on the same terms as Identity's crossing of 2026-08-24 — the contract
      half only, never the driver. And `ApiEnvelope` is **duplicated** into Admin rather than moved:
      Identity's copy predicted this moment and said the shared move is *"a boundary change, and
      boundary changes are their own point"*, which `CLAUDE.md`'s module-isolation rule now says
      from the other side. **Debt: move the envelope to a shared layer before a third module needs
      one.**
      **14 tests / 77 assertions.** Nine deliberate breaks with real output: the permission
      middleware removed · `auth` removed · the unknown-key closure gutted · the numeric rule
      dropped · the audit call bypassed · every save inserting a new row · unset fields omitted from
      the response · `meta.request_id` renamed · the empty-body guard weakened. Restored
      byte-identical.
      **Problems.** A settings key contains a dot and Laravel reads a dot as nesting, so every
      per-field rule matched nothing, `validated()` returned no `settings` key at all, and the
      controller died with a 500 instead of refusing anything — fixed by escaping the dot in the
      rule path. The same collision then hit the *test*: `assertJsonPath('data.settings.company.name')`
      read three levels that do not exist. And `audit_log.entity_id` is a `UUID` column, so passing
      the key produced `SQLSTATE[22P02]`; the entry now points at the row and carries the key in its
      values. **`auth` on the route is belt-and-braces:** break 2 showed the 401 comes from the
      permission gate, so the unauthenticated test does not distinguish the two.
      **Not covered:** no `settings` UI (Module 2 has no screens yet), no logo, no templates, no
      currency or list endpoints (3.2–3.4), no cache (`PRF-08`, 4.2), and **no validation that a
      value means anything** — `defaults.currency` accepts `ZZZ` and `locale.language` accepts
      `xx`, because tying them to `currencies` and to §14.2's two locales is a rule this point had
      no authorisation to invent.
- [x] **3.2** `GET /api/v1/currencies` and `PATCH /api/v1/currencies/{code}` — the rounding unit and
      its on/off switch (`D-65`), behind `admin.system_settings`.
      **The permission is the one §5.3 files it under.** *"Editable under System Settings →
      Currencies"* — so `admin.system_settings`, the Super Admin's, and **not** `admin.fx_rates`,
      which the Manager also holds and which Point 3.3 will guard. A test asserts the Manager is
      refused here, which is the row of §3.11 rather than a formality.
      **A partial change keeps what it did not name.** `D-65` flips a switch *beside* the unit, so
      `PATCH` with only `rounding_enabled` reads the current rule and applies the request on top.
      **`DB-07` end to end:** the unit arrives as a decimal string, is stored as one and leaves as
      one — `0.005` survives the round trip, and a test asserts the JSON field is a string rather
      than comparing numbers, because a float is exactly what would still compare equal.
      **`CURRENCY_ROUNDING_UPDATED` is audited** although §3.12 rule 4 does not list it: this is the
      number every total in that currency is rounded by, and §5.3 says a change applies to new
      quotations only — so *when* it changed is the question reconciling an old one will ask.
      **16 tests / 94 assertions.** Seven deliberate breaks with real output: the route guarded by
      `admin.fx_rates` · the partial-change fallback replaced by a constant · `gt:0` dropped · the
      audit call bypassed · `find()` including archived rows · the unit serialised as a float · the
      empty-body guard removed. Restored byte-identical.
      **Two breaks did not fail, and both are recorded rather than papered over.**
      1. **The `D-65` test could not see an invented unit.** It exercised USD, whose unit is `0.01`
         — the same value the broken fallback substituted. Rewritten to use **EGP**, whose unit is
         `1`, and the break then failed as it should. §5.3's table is what makes EGP the right
         fixture: it is the only one of the three with a different unit.
      2. **The archived-currency 404 comes from `replaceRounding()`, not from `find()`.** With
         `find()` deliberately including trashed rows the endpoint still answered 404, because the
         write path's `firstOrFail()` excludes them. The two are behaviourally identical at the API,
         so no test was contrived to tell them apart — but the 404 is not where the test implies.
      **A hole in the audit-coverage guard, found in passing and NOT fixed here.**
      `AuditEnforcementTest` classifies a class as a database writer only when it carries one of
      four signals — `ConnectionInterface`, `->table(`, `DB::`, `Eloquent\Model`. A repository that
      writes purely through a module-aliased Eloquent model (`CurrencyRow::query()`, `$row->save()`)
      carries none of them and is therefore **invisible to the guard**. Measured: five classes fall
      through it today — `EloquentCurrencyRepository` and four Identity adapters shipped with
      Module 1 (`BearerSessionResolver`, `EloquentSessionStore`, `EloquentAccountDirectory`,
      `EloquentUserDirectory`). Every one of them is in fact audited a layer out, so nothing is
      unrecorded — but `AUD-01`'s enforcement is weaker than it reads. **Owed: its own point**,
      because widening the signal list re-classifies four already-shipped Identity classes and each
      needs its disposition decided rather than guessed.
      **Not covered:** §5.3's *"changing either the unit or the on/off setting affects new
      quotations only"* — there are no quotations until Module 7, so the acceptance criterion stays
      unticked. Changing **which** currency is the base is not offered (§13 screen 5 names it;
      `DB-06`'s `base_amount` makes it a migration-shaped decision, not a `PATCH`). No new currency
      can be added — `CurrencyCode`'s three are the set. No optimistic locking: two administrators
      editing at once, and the last one wins without a `409`.
- [x] **3.3** `GET`/`POST /api/v1/fx-rates` — the rate history and one way to add to it, behind
      `admin.fx_rates`.
      **The negative authorisation test inverts, and that is the point of the point.** §3.11's
      *FX rates* row is `✅ Super Admin · ✅ Manager`, one line below the *system settings* row
      Point 3.2 guarded. So the Manager who is **refused** by `PATCH /currencies/{code}` is
      **allowed** by both endpoints here, and four tests assert both directions — a route that
      carried `admin.system_settings` by copy-paste would look right and silently delete §3.11's
      grant to the Manager. Break 1 proved it: two Manager tests turned 403.
      **A new price is a new row, and there is no `PATCH` and no `DELETE`.** §5.6 — *"Changing an
      FX rate never affects an existing quotation"* — and `AP-06`. Point 1.2 already made an
      `UPDATE` a database error (`FXH01`), so the guarantee is structural rather than enforced by
      this code, which is why it survives a later module that forgets it exists. A test asserts the
      router answers **405** on the collection URI, deliberately not 404 on `/{id}`: a URI no route
      matches answers 404 whether or not the endpoint was ever written, which is the vacuous shape
      Point 1.1 already paid for.
      **`FX_RATE_CHANGED` is mandatory, not discretionary.** §3.12 rule 4 names *"FX rate change"*
      among the nine — unlike `SETTINGS_UPDATED` (3.1) and `CURRENCY_ROUNDING_UPDATED` (3.2), both
      written on `AUD-01`'s general grounds. **`old_values` is null on purpose:** the contract says
      *"absent on a create"* and this is a create — the previous rate is not replaced, it is still
      in `fx_rates` and still applies to everything issued under it. Copying it in would put a
      second copy of history in a table `AUD-03` forbids correcting.
      **`OpenAPI §4.2` arrives with this point.** The rate history is the first genuinely unbounded
      collection in Module 2 — `GET /settings` and `GET /currencies` are both bounded by an enum —
      so §4.2's *"never return an unbounded collection"* finally bites. `ApiEnvelope::collection`
      is the **second** copy of Identity's, on the same terms and with the same debt as `single()`.
      §6.2's allowlists are declared **empty**: `filter[...]` and `sort` are refused with `400
      invalid_request` rather than ignored, and a `filter[from_currency]` is deliberately *not*
      invented — §13 screen 5 says *"rate history"* and stops.
      **28 tests / 177 assertions.** Fourteen deliberate breaks with real output: the route guarded
      by `admin.system_settings` · `auth` removed · `gt:0` dropped · the decimal pattern dropped ·
      `date_format` weakened to `date` · the mandatory audit event renamed · the `23505` translation
      removed · the archived-currency check removed · `totalPages()` allowed to return 0 · the
      documented order reversed · the `per_page` maximum and the unknown-filter refusal removed ·
      the distinct-currency guard removed · the rate serialised as a float · the omitted
      `effective_from` replaced by a constant. Restored byte-identical, `shasum -a 256 -c`.
      **Three findings, all recorded rather than papered over.**
      1. **Break 2 did not fail.** With `auth` removed the suite stayed green: the 401 comes from
         the permission gate, exactly as Point 3.1 measured. `auth` on this group is
         belt-and-braces and the unauthenticated test does not distinguish the two.
      2. **Break 5 did not fail either, and the test was strengthened until it did.** `last tuesday`
         is refused by Laravel's `date` rule as well, so the original test could not tell `date`
         from `date_format`. `2026-08-01` is where they differ and `DB-08` is why it matters — a day
         with no time and no offset becomes midnight in whatever zone the process is in, silently.
         The test now sends three spellings and the re-run break failed correctly.
      3. **A restore slip the checksum caught.** Undoing break 1 with `sed` rewrote the permission
         on the *settings* and *currencies* groups as well — `FxRateEndpointTest` stayed green
         throughout, because it does not touch either. `shasum -a 256 -c` is what reported it. The
         lesson is the rule as written: restore is verified byte-for-byte, not by a green filter.
      **A gate the filtered run could not see.** `CurrencyMatrixDataTest` tokenises every file in
      `Domain\Money` and forbids `ceil`, `floor`, `intdiv`, `round`, `fdiv`, float literals and
      float casts — `DB-07` *"anywhere near a price"*, enforced by namespace rather than by
      judgement. `RateHistoryPage::totalPages()` uses `ceil` over a row count, which is not near a
      price. The answer was to move the class, not to weaken the guard: `RateHistoryQuery`,
      `RateHistoryPage` and `InvalidRateHistoryQuery` now live in `Admin\Domain\Listing`. A blunt
      guard flagging an innocent class is the guard working, and only the **full** suite ran it.
      **`InvalidRateHistoryQuery` is a third duplication, named here.** Admin may not import
      Identity's `InvalidListQuery` — `deptrac.modules.yaml` grants it `Framework`,
      `SharedContracts` and `AuditContract` and nothing else. `ApiExceptionRenderer` may import
      both, because it lives outside `./app/Modules` and therefore outside deptrac's boundary, and
      it renders the identical shape. **Debt: the list-query contract and `ApiEnvelope` are now two
      halves of the same owed move to a shared layer.**
      **`lang/{en,ar}/admin.php` arrive with this point** — Module 2's first user-facing strings.
      `LocaleTest` compares the two key sets in both directions.
      **Not covered:** §5.6's *"changing an FX rate never affects an existing quotation"* as an
      outcome — there are no quotations until Module 7, so the acceptance criterion stays unticked;
      what is proved is the half that makes it possible, that the old row is still there. No
      `filter[from_currency]` and no sortable fields. No `J-12` staleness alert (4.1). No rate is
      ever **archived** — `DB-01` would allow it and no document asks for it. Nothing validates
      that a rate is *plausible*: `0.00000001` and `99999999` are both accepted, because a sanity
      band is a business rule this point had no authorisation to invent. And **no identity rate is
      stored** — EGP↔EGP is a tautology `fx_rates_distinct_currencies` refuses, which the endpoint
      now says first, as a 422 with a field name.
      **The frontend gate was not run locally.** Docker Hub was unreachable (two failed pulls of
      `node:22`) and the repo's `node_modules` carries Linux bindings installed inside the
      container, so the host toolchain cannot start vitest. This point changed **zero** frontend
      files — verified with `git status` — and CI ran the gate.
- [x] **3.4** `/api/v1/managed-lists/{list}` and `/api/v1/system-limits` — `DB-05`'s four lists and
      §13 screen 6's limits.
      **`GET` and `POST /managed-lists/{list}` answer to two different authorities, and §3.11 names
      neither.** The matrix has **no row** for managed lists, so the two halves are decided rather
      than quoted: the **read is authentication alone**, the way `GET /auth/me` is, because §8 puts
      Customers on six roles' screens and Catalog on five and not one can render without a sector or
      a unit; the **write carries `admin.system_settings`**, the Super Admin's row, because changing
      what the company may file a customer under is a settings action. Break 1 proved the read half
      is load-bearing rather than lax: putting the `GET` behind `admin.system_settings` failed the
      **acceptance-criterion test itself** — the new sector appeared in settings and nowhere else.
      **Recorded as a decision awaiting a `D-xx`**, on the same terms as `DELETE /roles/{role}`.
      **The module's acceptance criterion is now proved at the API.** A sector is added through the
      settings endpoint and returned by the endpoint the customer form will call, in one test, with
      no deployment between them — which is what Points 1.3 and 2.2 built the table and the
      code-refusing repository for. It stays **unticked** below, precisely: there is no customer
      form until Module 3, so what is proved is the half this module owns.
      **`admin.system_limits`, not `admin.system_settings`** — §3.11's own row, and Point 1.1's two
      tables for the same reason. **Break 7 did not fail and could not**: both rows belong to the
      Super Admin alone today, so no test can tell the two names apart. The distinction is made now
      anyway because §3.12 rule 5 makes regranting a row a configuration change — the day the
      Manager is given the limits row, an endpoint that had quietly named the settings row would not
      follow. Written into `routes/api.php` beside the group.
      **`SystemLimit` declares six, and the sixth is why.** §13 screen 6 names five;
      `identity.lockout_minutes` is the sixth because it is the only limit the documentation values,
      it is already seeded, and `SystemSettingsSeeder` says outright that *"the first thing that will
      happen to this row is somebody changing it"*. An endpoint listing five would have left the
      only live limit uneditable. A test drives the full `D-75` loop: change it through the endpoint,
      and `SettingReader::integer()` — what `AuthenticateUser` actually reads — returns 45.
      **The other five report `null` and nothing is seeded.** `D-17` says the stale-deal threshold
      is *"configurable in settings"* and stops; §11.5 says the daily deadline comes *"from
      settings"* and stops. Point 1.1 made the column nullable so *"not configured yet"* is
      distinguishable from a configured zero, and a test asserts all five are null rather than
      defaulted. ⚠️ **Two key names carry an inference, named rather than hidden:**
      `weekly_review_window_hours` reads §11.5's *"within 24 hours"* as the window §13 means, and
      `max_file_size_mb` reads `D-39`'s *"10 MB (configurable)"* as the unit. Neither number is
      seeded, so an inference about a **unit** cannot become an invented **value** — but both are
      owed an owner's confirmation.
      **A zero-minute lockout never locks**, so `SystemLimit::rule()` is a positive-integer pattern
      and not `integer`. Break 8: with `integer`, `0`, `-5` and `1.5` were all accepted with a 200.
      **One paginator, not three.** Point 3.3's `RateHistoryQuery`/`RateHistoryPage`/
      `InvalidRateHistoryQuery` became `ListingQuery`/`Page`/`InvalidListingQuery` — `Page` generic
      over its item, with a `meta()` that assembles §4.2's six keys once instead of in each
      controller. Generalising one-point-old code inside its own module is not the boundary change
      `CLAUDE.md` reserves for its own point: same layer, same module, same namespace, and
      `FxRateEndpointTest`'s 28 cases were the safety net, green before and after.
      **39 tests / 277 assertions** (22 managed lists · 17 limits). **Twelve deliberate breaks with
      real output:** the read put behind the settings permission · the write left unguarded · the
      Arabic label, code pattern and position floor dropped · the `23505` translation removed and
      the count widened past its list · the display order replaced · the unknown-list 404 removed ·
      the audit event renamed · the limits route given the settings permission · the positive-integer
      rule weakened to `integer` · the unit dropped on insert and the enum loop replaced by stored
      rows · the write bypassed · the lockout's unit dropped. Restored byte-identical,
      `shasum -a 256 -c`.
      **Two findings, recorded rather than papered over.**
      1. **A vacuous test, caught by the RED count.** RED was 38 failed and **1 passed** — the
         unknown-list `POST` 404, which a missing route answers too. It now writes a known list
         first, and RED became 39/0. This is the third time this shape has appeared (1.1, 3.3, 3.4)
         and the counter-measure is the same each time: exercise the real path in the same test.
      2. **Break 7 could not fail** — see `admin.system_limits` above. A limitation of §3.11 as it
         stands, not of the test, and making it observable would mean inventing a matrix change.
      **The audit guard fired on its first full run**, as it did in Point 3.1:
      `DatabaseSystemLimitRepository` is now registered in `AuditEnforcementTest::WRITERS`, audited
      one layer out by `UpdateSystemLimits`. ⚠️ **Its sibling was invisible.**
      `EloquentManagedListRepository` gained an insert this point and the guard did not notice —
      it writes purely through a module-aliased Eloquent model and carries none of the four signals.
      The hole measured in Point 3.2 with five classes now has **six**. Still owed its own point.
      **Not covered:** no **rename** of a list entry and no **archive** of one — `DB-01` forbids
      deletion and withdrawing a sector customers are filed under is a decision with consequences,
      which `ManagedListSeeder` already refuses to make silently; both are owed. No reordering
      endpoint (`position` is set on creation and never moved). No validation that a limit's *value*
      means anything beyond its type — `limits.daily_report_deadline` accepts `"tomorrow-ish"`,
      because §11.5 gives no format. **`D-39`'s 10 MB is declared and not wired**: whatever Storage
      reads for its upload ceiling today still reads it, and pointing Module 5 at this row is a
      change in Module 5. No cache (`PRF-08`, Point 4.2). No screens — §13's screens 4, 5 and 6 are
      Step 5.
      **The frontend gate was not run locally**, for the third time and the same reason: Docker Hub
      unreachable and the repo's `node_modules` built inside the container. **Zero frontend files
      changed** — verified with `git status` — and CI ran it.

#### Step 4 — scheduling and caching *(point order approved 2026-08-28)*

- [~] **4.1** `J-12 fx_rate_staleness_alert` — **withdrawn by the owner, 2026-08-28.**
      The point was published with three options for the staleness threshold, because §15's header
      says timings come *"from settings"* while §13 screen 6 names five limits and none of them is
      an FX staleness threshold — so `J-12` could not be built without adding a key `SystemLimit`'s
      own docblock says the document fixes.
      **The owner's decision, in their words:** *"We do NOT want a staleness threshold, and no
      alerts should be triggered for FX rates. The exchange rates simply exist as records, and
      employees will update them manually whenever needed."*
      **So nothing is built, and nothing is removed.** The rates already are purely passive storage:
      Point 3.3 records them, Point 1.2's trigger keeps them append-only, and no reader treats age
      as meaningful. `SystemLimit` keeps its six cases and gains no seventh.
      ⚠️ **`J-12` is still a row in §15's single reference list**, and `docs/` is not edited here.
      The job is **not implemented by owner decision**, which is a different thing from an oversight
      — recorded so that the next reader of §15 finds the reason rather than a gap. It is a
      candidate for a `D-xx` in `§2.8` whenever the owner next opens that log.
- [x] **4.2** `PRF-08` — a cache for `settings` and `system_limits`, with invalidation on write.
      **Scope is one of `PRF-08`'s four.** *"Caching for catalog · suppliers · permissions ·
      settings"* — catalog and suppliers are modules that do not exist, and **permissions belong to
      Module 1**, which `CLAUDE.md`'s module isolation puts out of reach of a point working in Admin.
      **This point is mostly about invalidation, and that is not a figure of speech.** `D-75`
      promises the owner changes the lockout duration *without a deployment*, and
      `AppServiceProvider` binds every Module 2 repository with `bind` and not `singleton` for that
      exact reason — its own comment reads *"an instance memoised for the life of the process is a
      deployment wearing a different name"*. A cache without invalidation is that same deployment
      wearing a third name and lasting longer. So every test that proves a cache **hit** is paired
      with one that proves a **write is visible immediately**, and `test_that_changing_a_limit_
      changes_what_the_login_flow_reads` drives it through `SettingReader` — what `AuthenticateUser`
      actually reads on every failed login.
      **The flush is after the transaction commits, never inside it.** Inside leaves a window in
      which a concurrent reader repopulates the cache from rows that have not committed; a rollback
      then leaves values that never existed. Both use cases therefore write inside the transaction
      and flush after it returns — and the read-back that forms the response is outside too, so what
      the response reports is what committed.
      **`DatabaseSettingReader` now reads through the repository, not the connection.** It owned a
      second copy of the same query and ran it once per call. Deleting that copy is what put the
      `D-75` hot path on the same cache entry §13 screen 6 uses, so **one invalidation covers both**.
      **Forever, with no TTL.** A TTL would be a number nobody wrote down, and this project does not
      invent those. Every write that can change these tables — `UpdateSettings`,
      `UpdateSystemLimits`, `SystemSettingsSeeder` — forgets its key.
      **The seeder flush is the hole this point would otherwise have opened.** A seeder writes
      *underneath* the API, so a cache warmed before it ran would hide `D-75`'s row until somebody
      happened to save the settings screen — in production, a deployment leaving the lockout
      configured and not in force. It flushes unconditionally, including on the "row already exists"
      path, because the row existing does not mean the cache knows it does.
      **`SettingsCacheInterface` is in `Domain\Contracts` and the Application layer never names a
      cache key.** `deptrac.layers.yaml` gives Application `Domain`, `Framework` and
      `SharedContracts` — reaching into `Infrastructure` for the concrete cache is a violation, and
      the boundary is right. The two methods are named after *what changed*, not after what to
      delete. **Caught by reading the ruleset before running the gate**, not by the gate.
      **10 tests / 50 assertions. Five deliberate breaks with real output:** both invalidations
      removed · the seeder flush removed · a poisoned entry filtered instead of reloaded · the cache
      never read · the flush moved back inside the transaction. Restored byte-identical.
      **Two findings, recorded rather than papered over.**
      1. **Six of the ten tests passed in RED**, and they are not vacuous — they are the
         *invalidation* tests, and an invalidation test cannot fail before the cache it invalidates
         exists. They were proved by break 1 and break 2 instead, which is what the break step is
         for. The distinction from Point 3.4's genuinely vacuous test is worth keeping: that one
         could never have failed; these could only fail after GREEN.
      2. **Break 5 did not fail, and cannot with the suite as it stands.** Moving the flush inside
         the transaction changed nothing: no reachable path rolls one of these transactions back,
         and the suite is single-process, so the concurrent reader the ordering protects against
         cannot be staged. **The after-commit ordering is reasoning, not a tested property**, and it
         is written into both use cases so the next editor meets the argument rather than the
         convention.
      **`singleton` for `SettingsCacheInterface`, and it is the only one in this module.** It holds
      no answer of its own — only the cache store's handle — so memoising it memoises nothing.
      **Not covered:** `currencies` and `enum_lists` are **not** cached — `PRF-08` does not name
      them, and the managed-list read is paginated, which is a different problem with a different
      invalidation. No cache metrics or hit-rate reporting (§13 screen 18 is Performance monitor, a
      later module). A row edited **directly in SQL** is not seen until something writes through the
      API — not a supported way to change configuration, and a TTL would only shorten that window
      rather than close it.
      **The frontend gate was not run locally**, same reason as Points 3.3 and 3.4: Docker Hub
      unreachable and `node_modules` built inside the container. **Zero frontend files changed** —
      verified with `git status` — and CI ran it.

#### Step 5 — §13's screens *(point order and four decisions approved 2026-08-28)*

> **The owner's four decisions, 2026-08-28.** (1) Managed lists get a **dedicated screen** §13 does
> not name — recorded as a decision awaiting a `D-xx`, on the same terms as `DELETE /roles/{role}`.
> (2) Screen 4 draws **eight** fields, with **no disabled placeholders** for the logo or the
> templates. (3) Screen 5 is **one screen** with its two halves rendered by permission. (4) Screen 6
> draws **all six** limits, `D-75`'s included. Staleness alert omitted (Step 4); base currency
> read-only.

- [x] **5.1** §13 screen 4 — *System Settings*, plus `services/admin.ts`, the route and the menu item.
      **Eight fields, and the two that are missing are missing on purpose.** The **logo** is Module 5
      (a `files` row, `SEC-15`'s scan, `D-38`'s permission-checked download) and the **PDF and email
      templates** are Module 9. Owner decision (A): no disabled placeholders — a control that cannot
      be used is a promise the product has not made.
      **`DB-07` reaches the screen.** Every input is `type="text"`, including the tax percentage: a
      `type="number"` binds to a JavaScript number, and a JavaScript number is a float.
      `inputmode="decimal"` gets the phone keypad without the conversion, and a test asserts the
      submitted value is a **string**.
      **Only what changed is sent.** `UpdateSettings` writes one audit entry per field with the old
      and the new value, so sending all eight on every save would fill `audit_log` with "changed X to
      X" — and `UpdateSettingsRequest` refuses an empty change set with a 422, so the button does
      nothing when nothing was edited rather than asking the server to say no.
      **A backend defect this screen exposed, found and fixed here.** `PATCH /settings` with a bad
      tax value answered *"The **settings.defaults.tax percent** field must be a number."* — Laravel
      derives the attribute name from the rule path, and that path carries the `settings.` prefix and
      the escaped dot `rules()` needs. The internal key was reaching the user. Fixed with a
      translated `attributes()` and covered by a new case in `SettingsEndpointTest`, so the message
      is right for **every** client rather than patched over in this one screen.
      ⚠️ **Two different key shapes on one line, both measured.** The `attributes()` array key is the
      **unescaped** path (`settings.defaults.tax_percent`) because the lookup uses the *resolved*
      attribute — with the escaped key nothing changed. The **lang** key uses **underscores**, because
      `__()` reads dots as nesting too, and the dotted key returned itself: the message then read
      *"The admin.settings.attributes.defaults.tax_percent field…"*. **Third time a dot has meant
      nesting in this module**; Point 3.1 paid for the first. Verified in isolation with `tinker`
      before the third edit, which is what the two-strike rule is for.
      **`ApiError` gains `details` and `messageFor(field)`** — additive; `detailCodes` is untouched
      and every Module 1 screen still reads it. It answers *what rule broke* and cannot answer *which
      field*, and a form with eight inputs needs that: one banner saying "validation failed" makes a
      person hunt for the control they got wrong. The sentence shown is the **server's**, localised
      by it for the request's `Accept-Language` — a client that composed its own would be a second
      copy of a validation rule.
      **14 component tests · 251 frontend tests · 17 files.** Seven deliberate breaks with real
      output: every field sent on every save · an unset field rendered as the word `null` · a 403
      rendered as a generic error · success flagged before the server answers · the per-field message
      dropped · the route's permission swapped to `admin.fx_rates` · `attributes()` removed. Restored
      byte-identical.
      **Two of the seven did not apply on the first attempt** — the search string's indentation was
      wrong, the file was untouched, and the suite stayed green. `shasum -a 256 -c` reported *no
      change* rather than a restore, which is how it was caught both times. **A break that does not
      apply looks exactly like a check that does not fail**, and only the checksum tells them apart.
      **Two existing guards caught the new work on their own, which is the point of them.**
      `navigation.spec.ts` generates a case per menu item pinning it to its route's
      `meta.requiredPermission` — break 6 failed there, not in a test written for it. And
      `NoHardCodedTextTest` keeps an explicit inventory of every `.vue` file and refused the new one
      until it was declared; the list is an inventory, not a suppression, and the scan passed on the
      file before it was added.
      **Not covered:** no logo upload and no template editor (Modules 5 and 9). **No unsaved-change
      warning** — Design System §5.2 asks a Form view for one, and it belongs with the shell rather
      than with one screen; recorded rather than half-built here. No optimistic locking: two
      administrators editing at once, and the last one wins without a `409`. No validation that a
      value *means* anything — `defaults.currency` still accepts `ZZZ`, unchanged from Point 3.1.
- [x] **5.2** §13 screen 5 — *Currencies & FX*, one screen with its two halves rendered by permission.
      **The route carries `admin.fx_rates`, read from §3.11 rather than copied from the screen above.**
      §3.11 gives `system settings` to the Super Admin and `—` to the Manager, while **FX rates** on
      the next line is `✅ Super Admin · ✅ Manager`. A route carries one permission and the screen has
      two audiences, so it carries the **wider** of the two: every seeded holder of
      `admin.system_settings` also holds `admin.fx_rates`, and guarding with the narrower one would
      bounce the Manager off a screen §3.11 grants them — the defect `navigation.ts` names, "a dead
      link is not a permission problem, it is a lie". The rounding half is then drawn by permission
      inside the component, which is `SEC-09`'s visual complement and never the check.
      **The Manager's view is a tested state, not a degraded one.** Rates and no rounding table — and
      the currencies are **not requested at all** for a caller without `admin.system_settings`,
      because a guaranteed 403 buys nothing but an error state on a half that should not be drawn.
      **The base currency is read-only** (owner decision, 2026-08-28). `CurrencyController` offers the
      unit and the switch and nothing else, so the base is a fact beside the code, not a control — and
      a test asserts the base row offers exactly the same inputs as every other row.
      **No staleness element of any kind** — `J-12` withdrawn by the owner, 2026-08-28. A badge with
      no rule behind it is a promise nothing keeps.
      **`DB-07` reaches the screen.** The rounding unit and the FX rate are both `type="text"` with
      `inputmode="decimal"`; break 2 swapped the unit to `type="number"` and failed **two** tests, the
      second being the PATCH body — a number input does not round-trip `0.05` as the string the API
      is owed.
      **Only what that row changed is sent.** `UpdateCurrencyRoundingRequest` refuses a body naming
      neither field with a 422, so the button does nothing when nothing was edited.
      **`AP-06` in the client.** A rate is append-only, so recording one re-reads the history from
      page 1 rather than patching a row in place; there is no edit and no delete because the resource
      has no `PATCH` and no `DELETE`.
      **22 component tests · 285 frontend tests · 20 files · 1325 backend tests.** Eight deliberate
      breaks with real output: the rounding half drawn for everyone · the unit as a number input ·
      every field sent on every row save · the currency codes not upper-cased · the history not
      re-read after a write · every currency marked as the base · the loading state never rendered ·
      a hard-coded heading in the new `.vue`. All restored byte-identical, each confirmed with
      `shasum -a 256 -c` rather than by re-reading the file.
      **The eighth break is the one that matters most.** It proved `NoHardCodedTextTest` is really
      scanning the new file rather than merely listing it: the literal was reported by name.
      **Not covered:** **no back-dating a rate** — `effective_from` is optional and absent means
      *now*, which is the only moment this screen offers; the endpoint accepts a past or future one
      and the screen does not. **No currency dropdown on the rate form** — `GET /currencies` carries
      `admin.system_settings`, which the Manager does not hold, so there is no endpoint that would
      answer them; both codes are free text validated by the server, and inventing a client-side list
      would be a second copy of the currency table. **A custom role holding `admin.system_settings`
      without `admin.fx_rates`** (§3.12 rule 5 permits one) is bounced by the route guard even though
      it may edit rounding — no seeded role is in that position, and the API is unaffected. No
      optimistic locking, and no unsaved-change warning: both already on the debt register.
- [x] **5.3** §13 screen 6 — *Limits & SLAs*, all six fields.
      **`admin.system_limits`, not `admin.system_settings`.** §3.11 lists them as two rows and both
      are the Super Admin's today, so the two names select the same callers — which is exactly why
      the distinction is made now: §3.12 rule 5 makes regranting a row a configuration change, and
      the day a Manager is given the limits row, a guard that had quietly named the settings row
      would not follow. Point 1.1 split the tables for the same reason.
      **The server owns the list, the order and the units — the client restates none of it.**
      `DatabaseSystemLimitRepository::all()` iterates `SystemLimit::cases()`, so the response is the
      whole enum in §13's order with each `unit` and `value_type` beside it. There is deliberately no
      `LIMIT_KEYS` beside `SETTING_KEYS`: screen 4 needs a list because two of its ten fields are
      **not** drawn, and every one of these six is. A second copy of the enum in the client is the
      copy that goes stale.
      **The unit is translated, not printed.** `SystemLimit::unit()` returns English words and §13
      screen 6 mixes days, hours and megabytes on one form. An unrecognised unit renders nothing
      rather than leaking `limits.unit.furlongs` into the page.
      **`D-75`'s lockout is the sixth and including it is the point.** It is the only limit the
      documentation values and the only one with a live reader; a screen drawing §13's five would
      leave the one working limit uneditable.
      **A backend defect this screen exposed, found and fixed here — the twin of Point 5.1's.**
      `UpdateSystemLimitsRequest` had no `attributes()`, so a refusal read *"The
      **limits.limits.stale deal days** field format is invalid."* — the internal key in front of a
      person, with the word `limits` in it **twice** because that key already begins with the prefix
      the rule path adds. In Arabic too. Measured with a throwaway request before a line was
      written, then fixed in the request class with a translated `attributes()` and covered by two
      new cases in `SystemLimitEndpointTest`, so the message is right for **every** client rather
      than patched over in one screen. Both key shapes are the ones Point 5.1 paid for: the array key
      **unescaped**, the lang key with **underscores**.
      **The `details[].field` path is untouched and is the submitted one** — `limits.limits.stale_deal_days`,
      measured, not inferred. The screen reads exactly that.
      **A weak assertion of my own, caught and replaced before it could pass on a defect.** The unit
      test asserted the English labels, and `en.limits.unit.days` is the same word the server sends —
      so it would have passed just as happily on the raw payload value. Re-asserted in **Arabic**,
      where `megabytes` becomes ميغابايت and the English word must be absent. Break 5 then failed on
      it; the English-only version would not have.
      **17 component tests · 305 frontend tests · 21 files · 1330 backend tests.** A real RED first
      this time — the spec failed to resolve the component, and the two backend cases failed 2/17
      before `attributes()` existed. Six deliberate breaks: an unconfigured limit rendered as the word
      `null` · a `type="number"` control · every limit sent on every save · the field path read
      without its submitted prefix · the raw server unit printed on every row · a hard-coded heading
      in the new `.vue`. All restored byte-identical, each confirmed with `shasum -a 256 -c`.
      **Not covered:** **the daily report deadline has no format, client-side or server-side.**
      `SystemLimit::rule()` is `string` for it, so the API accepts `"17:00"`, `"5 PM"` and `"soon"`
      alike, and the screen does not invent a format the documentation does not give — a `type="time"`
      control would have been the client deciding what the value means. **Owner question:** what
      spelling should `limits.daily_report_deadline` hold? **No unsaved-change warning** and **no
      optimistic locking** — both already on the debt register, unchanged. Two of the six key names
      still carry the inference `SystemLimit` records (`weekly_review_window_hours`,
      `max_file_size_mb`); nothing here values them, so the inference cannot become a number.
- [x] **5.4** Managed lists — the dedicated screen §13 does not name
      *(`/managed-lists`, `ManagedListsView.vue`. **The owner's decision of 2026-08-28 was put again
      on 2026-08-29**, because `S-02` had changed the premise under it: every other Module 2 screen
      is now a section of `/settings`. The owner chose the **dedicated route** a second time, and the
      deciding fact is a permission rather than a preference — `/settings` is guarded by
      `admin.fx_rates`, and `GET /managed-lists/{list}` is guarded by **nothing but a session**.
      §3.11 has no row for managed lists at all; §8 puts Customers on six roles' screens and Catalog
      on five, and not one of them renders without a sector or a unit. Folding this into the settings
      page would have hidden the sector list from every role the open read exists for. Still a
      pending `D-xx` on the same terms as the 2026-08-28 entry; `docs/` untouched.
      The route therefore declares **no `requiredPermission`** and the nav item declares
      `permission: null` — `navigation.spec.ts` pins the two equal, and a link stricter than its
      route is §5.1's defect in mirror image. The **add form** is drawn by
      `admin.system_settings`, which is what `POST /managed-lists/{list}` names: `SEC-09`'s visual
      complement, never the check — the API refuses either way, and `ManagedListEndpointTest` is
      where that is proved. Four lists as buttons, closed by `ManagedList`. Both labels on every row
      and both required in the form (`§14.2` — an entry with one label renders blank in the other
      language, which is why Point 1.3 made both columns `NOT NULL`). `position` is parsed once, at
      the moment of sending: it is a sort key and not an amount, so `DB-07` has nothing to say about
      it, and `AddListEntryRequest`'s floor of 1 is left to the boundary rather than restated here.
      Paged through `ListingQuery` with `page` as the only parameter (§6.2 makes an undeclared one a
      400). Per-field refusals come back as the server's own sentences via `ApiError.messageFor` —
      the duplicate code is one of them, `admin.managed_list.duplicate_code` on field `code`.
      **14 component tests · 346 frontend tests · 22 files · 1333 backend tests.** A real RED first —
      the spec could not resolve the component. **One deliberate break:** the add form's `v-if` was
      forced true so it drew for a role holding no administration row; the read-only case failed
      `expected true to be false`, 13/14 passing, and the file was restored byte-identical and
      confirmed with `shasum -a 256 -c`. **Problems found:** `vue-tsc` refused five reads of
      `fetchMock.mock.calls` — an untyped `vi.fn(async () => …)` types its `calls` as the empty
      tuple, so every destructured `[url]` and `[, init]` is `TS2493`. Fixed by declaring the mock
      parameters the way `SystemSettingsView.spec.ts` already does. **Not covered:** there is **no
      edit and no delete**, because the API offers neither — `DB-01` forbids physical deletion and
      withdrawing a sector customers are filed under is a decision with consequences; renaming a
      label stays owed. Nothing validates that a label is not a duplicate of another label — only
      `code` is unique. **No unsaved-change warning** and **no optimistic locking**: both already on
      the debt register, unchanged. `delivery_terms` is still seeded empty on purpose, so that list
      opens on the empty state and that is correct rather than broken.)*

> **Scheduling architecture, approved 2026-08-28.** Any maintenance or scheduled task in this scope
> is a **scheduled console command** on the `J-15` pattern in `routes/console.php`, deferring
> queue/worker execution until Horizon is configured. With `J-12` withdrawn, Step 4 has no scheduled
> task to attach it to — the approval is recorded here so the next such task does not re-litigate it.

> **`4.3` and `4.4` were published and postponed by the owner, 2026-08-28**, to a dedicated pass.
> Both are now in *Debt the server does not gate* above: the `AuditEnforcementTest` signal hole, and
> the move of `ApiEnvelope` and the list-query contract to a shared layer.

**Acceptance criteria**
- [ ] FX rate edit → old rate stays in history + mandatory audit entry
- [~] New sector added in settings → appears in the customer form **without a deployment** —
      **the API half is proved** by Point 3.4: `POST /managed-lists/sectors` then
      `GET /managed-lists/sectors` as a sales employee, in one test, no deployment between them.
      **Point 5.4 adds the screen half**: the entry is added at `/managed-lists` by a holder of
      `admin.system_settings` and read back by any signed-in employee, no deployment between them.
      The criterion stays partial only because **the customer form is Module 3** and does not exist
      yet — nothing about this list is waiting on it.
      The **customer form** is Module 3, so the criterion is not tickable here.
- [ ] Currency rounding unit changes → **only new quotations** are affected
- [x] Rounding units default correctly: EGP `1` · USD `0.01` · EUR `0.01` *(Point 2.1 — seeded from
      `Currencies` and asserted against §5.3 as the documentation publishes it, not against the class
      that produced them)*
- [ ] Rounding can be switched **off** per currency → final total stored unrounded, `rounding_diff` = `0` (`D-65`)
