> Frozen history of Module 3, cut verbatim from `CHECKLIST.md` on 2026-09-12 (commit `f8b993c`).
> Open boxes here are stale copies — the live ones are in `CHECKLIST.md`. Do not tick here.

## Module 3 — Customers

> As a sales employee, I want to add and view my assigned customers, so that I can manage my deals.

**Tables** `customers` · `import_batches` · plus **`SearchService`** with `PostgresSearchDriver` (ILIKE)

**Endpoints** CRUD + `PATCH /:id/archive` · `PATCH /:id/assign` · `POST /import`

> **⚠️ Owner decision, 2026-08-29 — the MVP import reads `CSV`, not `.xlsx`. Awaiting a `D-xx`.**
> This **narrows a documented requirement** and is recorded rather than applied silently:
> `MVP_Build_Plan_EN.md` Module 3 says "**Excel import**", §3.3 has an `import (Excel)` permission
> row, and §10 speaks of Excel throughout. `docs/` is untouched.
>
> **What forced the question.** `crm/composer.json` requires `laravel/framework` and
> `laravel/tinker` and nothing else — there is no Excel reader in this project. An `.xlsx` file is a
> ZIP of XML and cannot be parsed without one, so honouring the word "Excel" meant adding a
> dependency (`openspout/openspout` or `maatwebsite/excel`). CSV needs **no dependency at all**:
> `fgetcsv` is in the PHP standard library.
>
> **What this costs, stated rather than hidden.** A person who exports from Excel must choose
> *Save As → CSV UTF-8*, and the importer has to survive what that produces: a UTF-8 BOM on the
> first header, `;` as the separator under some Windows locales, and CRLF line endings. Those are
> the importer's problem now, and they are cheaper than a dependency. **`D-31` is unaffected** — a
> row with missing fields still saves flagged `is_incomplete`, whatever the file format was.
> The permission row keeps its documented name (`import (Excel)`, §3.3, Manager only); renaming a
> permission the seeded matrix carries is not in scope for a file-format decision.

**Frontend** role-filtered list · add/edit form · detail page · archive (individual + select all) ·
Excel import · "customers of deactivated employees" filter

#### Step 1 — schema *(point order approved 2026-08-29)*

- [x] **1.1** `customers` — §4.2's fields, `DB-01`/`DB-02`'s block, `DB-04`'s keys, `DB-09`'s index.
      **§4.2's field list is read out of the master documentation by the test**, not restated in it:
      a column dropped from both the migration and a hand-written list would pass a check that only
      agrees with itself. **`added_by` is `created_by`** — §4.2 and `DB-02` name the same fact, and
      two columns meaning "who added this row" is the defect rather than the reconciliation.
      **`customer_status` is a CHECK, not an enum table**, and `DB-05` is why: it requires enum
      tables for four named lists — sectors, units, service types, delivery terms — and customer
      status is not among them. §4.5 derives it from five ordered conditions, so a fifth status is a
      change to that rule and therefore a migration. It defaults to `prospect` because §4.5's fifth
      rule makes a customer with no deals a Prospect, and on the day this table is created there are
      no deals at all. **Nothing here derives anything** — `recompute_customer_status` is Module 5's.
      ⚠️ **`sector` carries no foreign key, and that was measured, not assumed.** `enum_lists`'s
      uniqueness is a *partial* index (`WHERE deleted_at IS NULL`, Module 2 Point 1.3) and PostgreSQL
      refuses a key against one — probed against the running database, which answered
      `SQLSTATE[42830]: there is no unique constraint matching given keys for referenced table
      "enum_lists"`. `DB-04` is therefore honoured wherever a key is possible (`sales_owner_id` and
      both actor columns) and the sector is validated at the boundary instead; the test pins **both**
      halves, so if that index ever becomes total the decision is revisited rather than inherited.
      **One index, not a speculative set:** `DB-09`'s owner index, because every §3.3 scope filters
      on it. The name search belongs to `SearchService` (2.1) and the default sort is not chosen
      until 3.2 — an index for a query that does not exist is a guess that costs writes.
      **20 tests · 1352 backend · 346 frontend.** RED first: 17 failed for a missing table while the
      one pure documentation check passed, and the field test failed on `hasColumn` rather than on
      its own counter, which is what proves the parser really read §4.2.
      **Three deliberate breaks:** the `whatsapp` column deleted (caught by name, out of the
      documentation) · the status CHECK removed (`vip` accepted) · the owner index removed (`DB-09`
      unmet). All restored; the last two confirmed with `shasum -a 256 -c`.
      **Problems found:** (1) PHPStan level 10 refused `DB::select(...)->indexdef` — an untyped row
      object — so the index check counts in SQL instead; narrowing it with a cast would have been the
      escape hatch the standards forbid. (2) A baseline checksum was never written because a failed
      `cd` short-circuited the `&&` chain before `shasum`; the first restore was verified by
      inspecting the column order instead, and a real baseline was taken before the next break.
      (3) A first full-suite run reported five deadlocks — two suites were running concurrently
      against one test database. Re-run alone: 1352 passed, 0 failed.
      **Not covered:** no seeder and no model — 1.1 is the table. No uniqueness on name, phone or
      email, because §4.2 declares none and `D-35` makes a similar name a *warning*, never a block.
- [x] **1.2** `import_batches` — the batch's metadata and counts, not the uploaded file
      *(⚠️ **The table is required and its columns are documented nowhere.** `import_batches`
      appears **once** in the whole corpus — `MVP_Build_Plan_EN.md` lists it among Module 3's
      tables — no section describes a field of it, and `OpenAPI_Contract_EN.md` does not mention
      import at all. The column set below is therefore **derived**, and recorded here as a decision
      awaiting a `D-xx` rather than presented as a reading of the documentation.
      **What each column is derived from:** *who and when* come free from `DB-02`, and §3.3 gives
      `import (Excel)` to the Manager alone so `created_by` is the importer · `original_filename`,
      because a batch nobody can identify answers no question anyone would ask of it · **three
      counts**, because `D-31` makes "saved but incomplete" an outcome distinct from "saved", so a
      batch that cannot report how many of each it produced cannot describe its own result.
      **A fourth count is deliberately absent:** outright failures are `row_count - imported_count`,
      and a stored copy is a number that can disagree with the two it is computed from.
      **The uploaded file is not kept.** No path column, and that is scope: storing the upload pulls
      in `SEC-15`'s virus scan, `D-39`'s configurable 10 MB and `D-38`'s permission-checked
      download — Module 5's files work. A path column today would promise machinery that does not
      exist, and a test fails if one appears.
      **The arithmetic is the database's** (`DB-04`): a batch cannot import more rows than it read,
      and `D-31` makes an incomplete row an *imported* row, so `incomplete_count` can never exceed
      `imported_count`. Both are CHECKs, not conventions the writer is trusted to keep.
      **13 tests · 1365 backend.** RED first: 12 failed. ⚠️ **The thirteenth passed vacuously** —
      "no column holds the uploaded file" is true of a table that does not exist, which is the
      hollow-test trap this project has already been bitten by once. It was verified **after** the
      table existed, by adding a `file_path` column and watching it fail by name.
      **Two deliberate breaks:** that `file_path` column · the `D-31` invariant CHECK removed
      (5 incomplete of 4 imported accepted). Both restored.
      **Problems found:** the first restore of break 2 was **not** byte-identical — re-inserting the
      block left a stray blank line before the closing brace, and `shasum -a 256 -c` reported
      `FAILED`. Found by the checksum, not by reading; the line was removed and the second check
      returned `OK`. This is the second time in two points that the restore step, not the break,
      was the risky half.
      **Not covered:** nothing links an imported customer back to its batch — `customers` has no
      `import_batch_id`. No source asks for one, and it is an **open owner question** rather than an
      omission: adding it later is a new migration, which is ordinary. No status column, no error
      log, no partial-resume — all invention, none of it documented.)*

#### Step 2 — `SearchService` *(approved 2026-08-29)*

- [x] **2.1** the seam + `PostgresSearchDriver` (ILIKE) in `App\Support\Search`, with a
      `SharedContracts` entry in `deptrac.layers.yaml` on the `App\Support\Settings` precedent
      *(`D-48`: "Meilisearch comes last — behind an abstraction layer built on day one", and the
      build plan states the condition — without the layer, adding Meilisearch at the end means
      **rewriting every screen with a search bar**.
      **The driver answers with identifiers, not rows, and that is what makes the swap possible.**
      Meilisearch searches its own index and returns document ids that the caller hydrates from
      PostgreSQL; a contract returning whole rows could be met by an ILIKE driver and **not** by a
      Meilisearch one, so the shape is chosen now rather than discovered in Module 15.
      **`SearchIndex` is an enum, not a string** — the build plan writes `search(index, query,
      filters)` without saying what `index` is, and a case cannot be wrong. One case, because one
      table exists.
      **The wildcard escaping is the load-bearing line.** `%`, `_` and `\` are ILIKE instructions,
      and `OpenAPI_Contract_EN.md` §6.2 says `q` must "not expose a database-specific search
      syntax" — a person searching `50%` is not writing a pattern.
      **The filter allowlist is a security boundary, not tidiness:** a filter key is a column name
      reaching SQL and a key cannot be bound the way a value can. `sales_owner_id` and
      `is_archived` earn their places from §3.3 (every customer read is owner-scoped) and Flow 7 —
      a capped search that ignored either would return the wrong results, not fewer right ones.
      **An empty query is refused rather than answered:** every row or none of them are both silent
      wrong answers, so the caller decides what a cleared box means.
      **`ponytail:` a hard cap of 500 with no pagination** — the ceiling is that an over-cap search
      truncates with no signal; the upgrade path is Meilisearch's native pagination in Module 15,
      not a bigger number. Noted in the class.
      **12 tests · 1377 backend.** RED first: the class did not exist.
      **Two deliberate breaks:** the escaping deleted · the filter allowlist deleted. Both restored,
      both confirmed with `shasum -a 256 -c`.
      **Problems found — three, and the first is the one that matters.** (1) ⚠️ **The percent-sign
      test passed with the escaping deleted.** The decoy was `Ahmed Hassan`, and the unescaped
      pattern `%50%%` matches anything containing `50`, which that name does not — a decoy that
      cannot be matched the wrong way proves nothing about the right way. Replaced with
      `507 Supplies`, which the wildcard *does* match; all three escaping tests then failed on the
      break as they must. **Found by breaking the code, never by reading the test** — the fifth such
      weak assertion this project has caught this way. (2) PHPStan level 10: `select()` is typed
      `array`, so `array_map` returned `array<string>` where the contract promises `list<string>`;
      replaced with a loop that appends, and `identifier()` now takes `mixed` and narrows with
      `is_object` — an assertion, not a cast. (3) A `cd crm &&` chain short-circuited again and the
      container binding was silently never added; the failing test caught it. **Second occurrence
      in two points** — `cd X && …` is now avoided outright in favour of absolute paths.
      **Not covered:** **`name` is the only searchable column.** No source enumerates them — §6.2's
      example is `?q=ahmed`, §4.2 makes `name` the identifying field and `D-35` compares names —
      so `contact_person`, `phone` and `email` are each an **open owner question**, and each is one
      line in `SearchIndex::columns()`. No Arabic normalisation yet: that is 2.2. No caller uses the
      seam until 3.2, so the `SharedContracts` deptrac entry collects nothing today. No relevance
      ranking, and none pretended — ILIKE has no notion of a better match.)*
- [x] **2.2** Arabic normalisation for name matching (§10.2: hamza · taa marbuta · yaa)
      *(`App\Support\Search\ArabicNormalisation`, wired into `PostgresSearchDriver` on **both**
      sides. **Exactly three families, because exactly three are named — twice.** §10.2 describes the
      duplicate check as a "fuzzy match with normalisation of hamza, taa marbuta and yaa forms" and
      §14.2 says the same of search; the two sections agree on which three, so diacritics and tatweel
      are **not** folded however usual that is elsewhere. That is an **open owner question**, and one
      line here when answered — it changes which customers the system calls duplicates.
      **One rule, two languages, and a test that they agree.** The query is folded in PHP and the
      stored column by PostgreSQL's `translate()`, because folding every row in PHP would mean
      reading every row. That is two implementations of one rule — the defect waiting to happen — so
      the mapping lives in **one** pair of constants, the SQL binds them directly, and a test runs
      both over the same names and compares. ⚠️ `translate()` being character-wise on UTF-8 Arabic
      was **probed against the running database** before the design depended on it, not recalled:
      `translate('أحمد إبراهيم فاطمة ليلى', …)` answered `احمد ابراهيم فاطمه ليلي`, and
      `length('أإآٱةى')` answered `6`.
      **Both directions are asserted**, and that is what makes the tests able to see a half-done
      job: folding only the query would find `احمد` on file from `أحمد` typed and not the reverse,
      and folding only the column does the opposite. Proved — see the breaks.
      **30 tests · 1407 backend.** RED first: the class did not exist.
      **Two deliberate breaks:** the query-side fold removed (**3 of 7 direction cases failed — the
      three where the written form is typed**, exactly the half that break leaves broken) · the
      column-side fold removed (4 failed, the mirror). Both restored, both confirmed with
      `shasum -a 256 -c`.
      **Problems found:** PHPStan level 10 rejected both data providers — the docblocks promised
      `list<…>` while the arrays are string-keyed, and those keys are the data-set names PHPUnit
      prints in the output above. The annotation was wrong, not the code.
      **Not covered:** no diacritics, no tatweel (above). **The duplicate warning itself is not
      built** — §10.2's "similarity above the threshold" needs a threshold, and no source states one:
      `SystemLimit` has six limits and none of them is it. That is 3.3's problem and a second open
      owner question. **`ponytail:` `translate()` on every row is a sequential scan** — no index can
      serve it. The ceiling is fine at MVP customer counts; the upgrade path is a functional index on
      the same expression, or Meilisearch, and neither is guesswork today.)*

#### Step 3 — row scope and the API *(approved 2026-08-29)*

- [x] **3.1** row-scope resolution (§3.3: All · Team · Out · Own · Asgn) + negative-authorization tests
      *(`CustomerRowScope` — a pure Domain function turning the scope codes a §3.2 permission carries
      into the only two things a `customers` query needs: **every row**, or **the rows of a known set
      of sales owners** (`SEC-08`).
      **The five codes are strings, not Identity's `Scope` enum, and that is forced rather than
      chosen.** `deptrac.modules.yaml` gives `Customers` an empty ruleset (`Customers: ~`) because
      `AP-02` wants each module extractable — so the module may reference nothing at all, Identity
      included. The codes therefore cross the boundary as the strings §3.2 writes, and a test **reads
      §3.2's table out of the master documentation** and compares, so the two transcriptions cannot
      drift; a sixth code added to §3.2 fails the build rather than being silently ignored by an
      authorisation rule.
      **An unknown code throws rather than denying.** Failing closed on it would hide a typo behind
      an empty list that looks exactly like a legitimate refusal — the same reason
      `AuthorizePermission` already throws on an unknown route scope.
      ⚠️ **Three of the five scopes have no mechanism and fail closed** — recorded as owner-deferred
      debt above, not as finished behaviour.
      **11 tests · 1418 backend · 346 frontend.** RED first: 11 failed before the class existed.
      **Three deliberate breaks, each confirmed to fail and each restored byte-identical
      (`shasum -a 256 -c` → `OK`):** `team` widened to every row (2 failed) · the unknown-code
      `throw` removed (1 failed) · `asgn` dropped from the recognised set (1 failed).
      **Problems found:** (1) the documentation parser first returned **6** codes, not 5 — §3.2 bolds
      the `—` "Not permitted" row too; caught by running the check, not by reading it. (2) A first
      full-suite run reported 4 failures from two suites sharing one test database — the trap this
      file already documents; re-run alone, 1418 passed.
      **Not covered:** no query and no endpoint — the predicate is not applied to the database until
      3.2. Nothing here handles `is_archived` (Flow 7, Point 3.4). The negative-authorization tests
      are **unit-level**; real `403`s over HTTP arrive with the endpoint.)*
- [x] **3.1b** the shared-layer point `ApiEnvelope`'s own docblock had been owed since Module 2
      *(unplanned, approved by the owner 2026-08-29 when Point 3.2's reading surfaced it.
      **Why it had to come first:** `deptrac.modules.yaml` gives `Customers` an empty ruleset, so the
      module cannot reference `Illuminate`, let alone an envelope owned by Identity — and
      `ApiEnvelope` sat in `Identity\Presentation`, which the module config places in
      `IdentityDriver`, the half *"nothing may ever reach"*. Customers had two honest options: a
      third copy, or the move this file already owed. The debt entry named its own trigger —
      *"before a third module needs one"* — and Customers is the third.
      **The copies had already drifted:** Admin's carried no `error()`, so `OpenAPI §5`'s error
      envelope was one module's and silently not the other's. Measured by diffing them, not assumed.
      **Scope was cut by a probe, not by preference.** The same debt names the list-query contract,
      and that half **cannot** move: both exceptions live in Domain, whose ruleset is empty by
      design. A real reference was added and deptrac answered `DependsOnDisallowedLayer`; the probe
      was reverted and verified byte-identical. So the envelope moved and the exception did not, and
      the register now says exactly that.
      **18 files, all of them one `use` line**, plus the two deletions and the two deptrac configs.
      `1418 passed (9175 assertions) · 131.53s · EXIT=0` · `pint --test` **PASS 347 files** (348
      minus the removed copy) · `phpstan` **[OK]** · `deptrac` ×2 **Violations 0 · Uncovered 0**.
      ⚠️ **The assertion count fell by one and that was chased rather than waved through:**
      `NoHardCodedTextTest` asserts once per PHP file under `app/` and `routes/`, and this point
      removed two files and added one. Net −1 file, net −1 assertion. Explained, not a defect.
      **Problems found:** (1) a `for f in $files` loop silently did nothing because zsh does not
      word-split unquoted variables — caught because `perl` reported "File name too long"; no file
      was half-edited, confirmed against `git status` before retrying with `while read`. (2) The
      retry added a real `use` to `Admin\Domain\Listing\InvalidListingQuery`, which only mentions
      the envelope in a **docblock** — a Domain class given an import it must not have. Reverted with
      `git checkout --`. ⚠️ **deptrac reported `Violations 0` with that bad import in place**, so an
      unused import is invisible to it; that is why the probe above used a real reference.
      (3) A `git stash`-based comparison was killed by a 2-minute timeout **after** stashing and
      **before** popping, leaving the whole change in the stash; recovered with `git stash pop` and
      all 22 files verified present.
      **Not covered:** the list-query duplication (above), and `Customers` still has **no** deptrac
      ruleset entry — that arrives with 3.2, which is where the module first needs `Framework` and
      `SharedContracts`.)*
- [x] **3.2** `GET /customers` (§10.1's deactivated-employee filter · `q` through `SearchService`) and
      `GET /customers/{id}`
      *(**Customers' first endpoint, and its first deptrac ruleset.** The module had `Customers: ~` —
      it could not reference `Illuminate`, let alone anything else — so this point grants it
      `Framework`, `SharedContracts` and `IdentityContract`. The third is the crossing
      `deptrac.modules.yaml` predicted in writing: *"SEC-07 makes this the module every other module
      will eventually ask about permissions"*. `SEC-08` cannot scope a row without knowing what the
      caller's grant reaches, so Customers reads Identity's `PermissionDecision` off the request. It
      points at the **contract** half only; nothing points at `IdentityDriver`.
      **The row scope is applied by construction.** Every read starts from one private `scoped()`
      builder factory — a filter each method remembers to add is a filter one method will forget,
      and the row that leaks is somebody else's customer. A scope that permits nothing returns early
      instead of asking PostgreSQL a question whose answer is known.
      **`q` narrows, never widens.** `D-48`/§6.2 route it through `SearchService`, which answers with
      **ids**; those are intersected with the already-scoped query. Handing the search a scope filter
      and trusting its answer would put an authorisation decision inside the component Module 15
      replaces with Meilisearch. A test proves it end to end in Arabic — `?q=احمد` matches
      `أحمد للتجارة` through §10.2's normalisation — and another proves `q` cannot reach past the
      caller's own rows.
      **§10.1's filter asks Identity rather than joining `users`.** `CLAUDE.md` forbids direct
      cross-module database access, so `owner_inactive` pages through `UserDirectoryInterface`. A
      `ponytail:` comment names the ceiling and the upgrade (a bulk `inactiveUserIds()`, agreed with
      Identity rather than added from a Customers point).
      **Multi-field sort, unlike Identity's criteria** — and that is the contract, not drift: §6.2's
      example is written against this very resource, `?q=ahmed&sort=-created_at,name`.
      **A row out of scope is 404, never 403** (`OpenAPI §5.1`: "do not reveal which case applies"),
      which is why `CustomerNotFound` is one exception for both cases rather than two.
      **19 tests · 1437 backend (9344 assertions) · 145.17 s · EXIT=0** (1418 + 19 ✓) · `pint --test`
      **PASS 359 files** · `phpstan` **[OK]** · `deptrac` ×2 **Violations 0 · Uncovered 0** ·
      frontend **346 passed**, built in 4.16 s.
      ⚠️ **Two tests passed vacuously in RED and were verified afterwards** — the documented trap,
      caught this time by reading which tests passed rather than only the count. With no route at
      all, a request 404s, so *"a row outside the caller's scope is 404"* and *"an unknown id is 404"*
      both passed against a module with no code in it. **Verified after the route existed, by two
      deliberate breaks:** `find()` made to ignore the scope (the scope test failed) and
      `CustomerNotFound` made to render 422 (both failed). Restored and confirmed byte-identical with
      `shasum -a 256 -c`.
      **Problems found:** (1) the first RED run failed inside the test's own helper rather than
      against the endpoint — `RefreshDatabase` migrates but does not seed, so no role existed;
      `RolePermissionSeeder` is now seeded in `setUp`, which also means the endpoint is checked
      against §3.12 rule 5's **database** matrix rather than a fixture agreeing with itself.
      (2) PHPStan level 10 rejected seven mixed-narrowing sites — a cast on `getAuthIdentifier()` and
      six offsets on `->json()`. Fixed by narrowing with assertions, never casts, as the standards
      require.
      **Not covered:** no `POST`/`PATCH` (3.3), no archive/restore endpoint (3.4) — `filter[is_archived]`
      reads archived rows but **does not enforce Flow 7's "Manager and Team Leader only"**, which is
      3.4's rule and is not yet applied here. No `include`, no `group_by`. The owner's *name* is not
      expanded onto a row: it belongs to Identity, and inlining it would reach for another module's
      rows once per row. And `team`/`out`/`asgn` still resolve to nothing, so Team Leader, Outdoor
      Supervisor and Procurement see an empty list — tested explicitly, so the deferral's cost is
      visible rather than surprising.)*
- [x] **3.3** `POST /customers` and `PATCH /customers/{id}`, with `D-35`'s similar-name **warning,
      never a block**
      *(**A scope on `create` is not a `WHERE`.** §3.3's create row carries scopes —
      `All · All · Out · Own · Own · — · —` — and there is no row yet to filter, so the only thing a
      scope can constrain before the row exists is **who it is filed under**: `all` files under
      anybody, `own` files under the actor and refuses anybody else. The same `CustomerRowScope`
      answers it, which is why `team`/`out`/`asgn` fail closed here exactly as they do on read — an
      Outdoor Supervisor cannot create a customer at all today, and that is tested rather than left
      to be discovered.
      **`sales_owner_id` is writable on create and `prohibited` on update.** §3.3 makes `assign` a
      permission of its own — two roles hold it where five hold `edit` — and `OpenAPI §7.2` gives it
      the route `PATCH /customers/{id}/assign`. Accepting the column on a generic update would hand
      every `edit` holder a permission the matrix does not grant them. Point 3.5 builds the transfer.
      **Three columns are refused with a 422 rather than dropped:** `customer_status` (§4.5 and
      `D-49` derive it), `is_archived` (Point 3.4's own action) and `is_incomplete` (`D-31`'s
      importer flag, Point 3.6). `CustomerDraft` would filter them out anyway, so the write is safe
      either way — but a caller who sends `customer_status` has a wrong idea about who owns that
      value, and a 201 would confirm it.
      **`pg_trgm`, chosen by measurement.** §10.2 asks for a *similarity score against a threshold*,
      which `ILIKE` cannot answer. PHP's two candidates were measured and both are **byte-wise** —
      `strlen('أحمد')` is 8 where `mb_strlen` is 4 — so `levenshtein()` and `similar_text()` score
      Arabic by its UTF-8 bytes. Probed in a throwaway database before the migration was written:
      folded through `ArabicNormalisation`, `احمد للتجاره` vs `أحمد للتجارة` scores **1.00** (0.44
      unfolded) while two unrelated company names score **0.09**. The extension is available in
      `postgres:17.5-bookworm` and was **not** installed; the DB role is superuser, checked with
      `usesuper` before relying on `CREATE EXTENSION`. ponytail: no GIN index — `OD-08` scopes the
      tuning to the first hundred customers and the probe runs once per save.
      **The probe runs after the write, never before it.** `D-35` is *"warning only; the employee
      decides"*, so there is no branch in which a similar name can stop a save — structural rather
      than a rule somebody has to keep.
      **No `Idempotency-Key` and no `If-Match`, read rather than skipped.** `OpenAPI §9.1` lists
      deals, quotations, supplier quotations, purchase orders, reports and versions; a customer is
      on none of them and is archivable rather than irreversible (`DB-01`). §9.2 scopes optimistic
      concurrency to quotations and says the pattern reaches other resources *"only through a
      documented contract update"*.
      **`AUD-01` create and update**, recorded inside the same transaction as the write (`DB-11`),
      with the old values limited to the fields the write actually touched (`AUD-02`).
      **`Customers → AuditContract`** in `deptrac.modules.yaml`, on the same terms as Identity's
      crossing of 2026-08-24 and Admin's of 2026-08-27 — the contract half only.
      **31 tests · 1468 backend · 346 frontend.** RED first: **27 failed** before any of it existed.
      **Four deliberate breaks, each confirmed to fail and each restored byte-identical
      (`shasum -a 256 -c` → `OK`):** the duplicate probe's scope removed (the leak test failed) ·
      the create-scope owner check disabled (1 failed) · `sales_owner_id` un-prohibited on `PATCH`
      (1 failed) · the migration's `down()` made a no-op (`DEV-03` failed).
      **Problems found:** (1) after `save()`, `customer_status` was still null in memory — the
      column's `DEFAULT 'prospect'` fills it and nothing in this module sets it — which killed
      `hydrate()` on a non-nullable argument and took 10 tests with it; fixed with a `refresh()`,
      and the comment says why rather than what. (2) PHPStan level 10 rejected five sites in the new
      tests; fixed by narrowing (`assertIsNumeric`, `DB::scalar`) and by deleting one assertion it
      could prove would never fail — never by casting. (3) ⚠️ **Two Module 2 tests failed, and they
      were right to:** `SystemLimitEndpointTest` asserts the limit **count** first, so the seventh
      case broke it exactly as `i18n.spec.ts` breaks on a new hint. Both were updated with the
      reason, not just the number — a Module 3 point editing a Module 2 test, disclosed here because
      the enum it guards is the one this point extended.
      **Not covered:** no archive/restore (3.4), no assign (3.5), no import (3.6), and **no screens
      at all** — `D-35`'s yellow warning has an API and no UI until Point 4.2. The duplicate probe
      is scoped, with the cost recorded above. `customer_status` is still never derived: §4.5's
      recalculation is Module 5's. Nothing here rate-limits creation.)*
- [x] **3.4** `PATCH /customers/{id}/archive` and restore (Flow 7: Manager and Team Leader only)
      *(**One permission for two routes, because §3.3 writes one row.** The matrix merges the pair
      as `archive / restore` granted `All · Team · — · — · — · — · —`, and `PermissionMatrix`
      carries a single `customer.archive` with no `customer.restore` beside it. Both routes check
      the same ability; inventing a second permission would be a matrix row no document contains.
      `OpenAPI §7.2` supplies the shapes — `PATCH /customers/{id}/archive` and `…/restore` — which
      are exactly the two action suffixes it lists for this resource.
      **Idempotent, and silent when nothing changed.** §7.2 wants each action's *"accepted current
      state"* documented and no source names one, so archiving an archived customer succeeds and
      does nothing — **and writes no audit row**. `AUD-03` keeps entries permanently and immutably,
      so one describing a change that did not happen is a permanent false record; Flow 7's
      select-all restore makes "some of these are already active" the ordinary case rather than the
      odd one.
      **Archive is not delete.** Flow 7 — *"No customer is ever permanently deleted"* — plus `DB-01`
      and §3.12 rule 3. Nothing touches `deleted_at`, and a test asserts it stays null.
      **Two audit events, and the split is §3.12 rule 4's.** Rule 4 names *"restore from archive"*
      among the nine that must always be recorded, which is why `AuditEvent::archiveRestored()`
      already existed; the archive itself is an update and rides on `AUD-01`. Both write inside the
      same transaction as the flag (`DB-11`).
      ⚠️ **Flow 7's "Manager / TL only" is half met, and the half that fails is the Team Leader.**
      §3.3 gives them `Team`, which has no mechanism (owner's deferral, 2026-08-29), so
      `CustomerRowScope` fails it closed and **a Team Leader can archive and restore nothing at
      all** — 404 on every customer in the company. Tested explicitly, so the deferral's cost is
      visible rather than surprising.
      ⚠️ **A correction to what Point 3.2's own notes claimed.** They said `filter[is_archived]`
      *"does not enforce Flow 7's Manager and Team Leader only — that rule is 3.4's"*. Read against
      the source, that rule is not 3.4's and is not anybody's: Flow 7 restricts **who archives and
      restores**, not who may *view* an archived row, and §3.3's `view` row carries no archived
      carve-out. Nothing was invented to close it — the filter is unchanged and the question is on
      the register instead.
      **24 tests · 1492 backend · 346 frontend.** RED first: **22 failed, 2 passed** — and the two
      were read rather than counted: with no route at all, *"a Team Leader archives nothing"* and
      *"an unknown id is 404"* both pass against a module with no code in it. The documented trap,
      caught again by reading **which** tests passed.
      **Three deliberate breaks, each confirmed to fail and each restored byte-identical
      (`shasum -a 256 -c` → `OK`):** the row scope replaced with `all` (the Team Leader test
      failed) · `CustomerNotFound` rendered as 422 (the unknown-id test failed) · the idempotence
      short-circuit disabled (both silence tests failed).
      **Problems found:** (1) the two `@dataProvider` annotations expanded to nothing — this repo is
      on PHPUnit's `#[DataProvider]` attribute, as `CustomerRowScopeTest` already showed, and the
      annotation form silently collapsed ten cases into two. Caught by the test count, not by
      reading the file. Fixed to the attribute; 16 tests became 24.
      **Not covered:** ⚠️ **no bulk restore.** Flow 7 grants restore *"individually or select-all"*
      and `OpenAPI §7.3` gives the shape (`{"ids": [...]}`, per-record results, no bypass of row
      scope) — it is documented and it is **not built here**, because the approved point names the
      singular routes. *(Point 4.5 superseded this: the owner chose a client loop over the singular
      route, so no screen blocks on it — but `API-07` still documents the endpoint and it is still
      not built.)* No bulk archive either,
      and that one is not clearly documented at all. Nothing here changes who may *view* archived
      rows. `ArchiveCustomer` is invisible to `AuditEnforcementTest` — measured, see the register.)*
- [x] **3.5** `PATCH /customers/{id}/assign` (Flow 10: owner and history transfer + audit entry)
      *(**`assign` is its own permission, and the route says so.** §3.3 grants it
      `All · Team · — · — · — · — · —` where `edit` reaches five roles, `PermissionMatrix` already
      carried `customer.assign`, and `OpenAPI §7.2` lists the suffix verbatim. The route checks
      `customer.assign` and never `customer.edit` — which is also why `SaveCustomerRequest` has
      prohibited `sales_owner_id` on `PATCH /customers/{id}` since Point 3.3.
      **"The customer and their full history move" is satisfied by not moving anything.** The
      customer's id does not change, so the audit trail keyed to it — and every later module's deal,
      quotation and visit — follows the owner change by construction. A transfer that copied rows
      between owners would be the version of this flow that loses history. Tested: an
      `ARCHIVE_RESTORED` entry written before the transfer is still the same customer's after it.
      **The reach is the same one every other Customers route uses.** `CustomerRowScope` resolved
      from §3.2's codes, the row read **inside** the transaction through that scope, and 404 for
      absent-or-out-of-reach without revealing which (§5.1).
      **Idempotent and silent when nothing changed** — Point 3.4's rule for Point 3.4's reason
      (`AUD-03`): assigning a customer to the owner they already have succeeds, writes nothing, and
      leaves `updated_by` untouched.
      **§3.12 rule 4's mandatory entry**, written inside the transfer's transaction (`DB-11`) with
      `AUD-02`'s old value beside the new one. `AuditEvent::customerReassigned()` already existed.
      **The new owner is checked through Identity's contract**, never `exists:users,id` — the direct
      cross-module read `CLAUDE.md` forbids. `UserDirectoryInterface::find()` answers null for a
      hidden account too, so §3.12 rule 6's Super Admin cannot be made an owner through a guessed id;
      a test pins that.
      **No persistence surface was added.** `CustomerDirectoryInterface` is untouched:
      `CustomerDraft::forAssignment()` is the one field `forUpdate()` refuses, and the existing
      `update()` writes it. One named factory, so the writable-key set stays in one place.
      ⚠️ **NARROWING, AWAITING A `D-xx` — Flow 10's "both employees notified" is not built.** The
      owner ruled on 2026-08-30 to ship the transfer without it. The reading behind the ruling:
      §18.1 limits the MVP to *"Badges and Inline Validation Only"* and its badge counters are
      *Requests · Approvals · Reports · My Quotations*, with **customers not among them**; §18.2,
      *"Email — The Only Exception"*, is a **closed list of five** — `MAIL-01` verification code ·
      `MAIL-02` password changed · `MAIL-03` account status changed · `MAIL-04` new account
      credentials · `MAIL-05` critical failures to Super Admin — and a customer reassignment is on
      none of them; §18.3 puts the full notification centre post-MVP. Machinery **does** exist
      (Identity ships two `Illuminate\Notifications` mail notifications and `User` is `Notifiable`),
      so this is a scope decision and not a capability gap. `test_that_assigning_notifies_nobody`
      pins it as a decision rather than an omission. **This narrows a documented flow and needs a
      `D-xx` in the master log.**
      ⚠️ **Flow 10 is half reachable, and the half that fails is the Team Leader** — §10.1 names
      *the Team Leader* as the one who reassigns a deactivated employee's customers, and §3.3 gives
      them `Team`, which has no mechanism (owner's deferral, 2026-08-29). `CustomerRowScope` fails it
      closed, so **a Team Leader can assign nothing at all** — 404 on every customer in the company,
      and only the Manager can actually perform Flow 10 today. Tested, so the deferral's cost is
      visible rather than surprising.
      **22 tests · 1514 backend · 346 frontend.** RED first: **20 failed, 2 passed** — and the two
      were read rather than counted: with no route, *"a Team Leader assigns nothing"* and *"an
      unknown id is 404"* both pass against a module with no code. The documented trap, and both
      tests were re-verified afterwards.
      **Three deliberate breaks, each confirmed to fail and each restored byte-identical
      (`shasum -a 256 -c` → `OK`):** the absent/out-of-reach guard throwing 422 instead of
      `CustomerNotFound` (**both** vacuous-in-RED tests failed — 2 failed) · the idempotence
      short-circuit disabled (the silence test failed — 1 failed) · the owner-existence check removed
      (the unknown-owner and hidden-Super-Admin tests failed — 2 failed).
      **Problems found:** one, and the guard caught it rather than a reader. A comment added here
      claimed `AssignCustomer` would be **invisible** to `AuditEnforcementTest` the way
      `ArchiveCustomer` is — and the register test failed on the unlisted class, because this one
      calls `->update(`, which **is** one of the DML verbs the scanner matches, where `setArchived(`
      is not. The claim was wrong before the run and would have gone into a commit message unchecked;
      the class is now registered `AUDITED` and both notes say what was measured.
      **Not covered:** no notification, per the ruling above. **No bulk assign** — no source
      describes one; Flow 7's *"select-all"* is about restore, not transfer. Assigning **to** a
      deactivated employee is allowed, exactly as creating under one is (§10.1 keeps customers
      attached to deactivated accounts and offers a filter for them); **unassigning is impossible**
      — `sales_owner_id` is `required`, because §3.3 has no unassign row and an ownerless customer is
      `D-34`'s path, not this one. An **archived** customer can still be assigned: no source forbids
      it. Nothing here derives `customer_status`, and there is **still no screen** — Flow 10's UI is
      Point 4.3's.)*
- [x] **3.6** `POST /customers/import` — CSV through `fgetcsv`, no dependency
      *(**§3.3's `import (Excel)` row, the Manager alone.** A dash in every other column, the Team
      Leader's included — the one Customers row where `All · Team` does not apply. The permission
      keeps the document's name; the owner's narrowing of 2026-08-29 is about the **file format**,
      not about who may import.
      **`fgetcsv`, and three behaviours measured in this image (PHP 8.4.24) rather than assumed:**
      CRLF is handled, a newline inside a quoted field is preserved and the row still reads whole,
      and **a UTF-8 BOM is not stripped** — the first header cell arrives as `\xEF\xBB\xBFname`.
      So the BOM is stripped in `CustomerCsv` and the other two are left to the standard library.
      The `;` separator is sniffed off the header line, because that is what a spreadsheet saves in
      a locale where `,` is the decimal separator.
      ⚠️ **`D-31`'s "missing fields" is undocumented, and this is the reading — awaiting a `D-xx`.**
      §4.2 marks only `name` required, so the literal reading flags nothing and leaves `D-31`,
      §10.1's filter and §11's exclusion with no subject at all. **Any of §4.2's ten user-entered
      fields left empty flags the row** — the conservative direction, because it never calls a
      record complete when it is not. The cost, stated rather than hidden: in practice nearly every
      imported row carries the flag. The narrower reading — only the columns the file itself
      declares — is the owner's to choose.
      **A nameless row is a failure, not an incomplete one.** `customers_name_not_blank` is a CHECK,
      so the row cannot save; it counts in `row_count` and not in `imported_count`, which is exactly
      the arithmetic Point 1.2 wrote into `import_batches` when it refused a fourth count. The same
      applies to a value longer than its column: refused, because a name cut at 255 is a different
      customer, silently.
      **One transaction for the whole file** (`DB-11`), one `CUSTOMER_CREATED` per imported row
      (`AUD-01` names create explicitly), and one `import_batches` row at the end. No
      `Idempotency-Key`: `OpenAPI §9.1` lists the resources that need one and a customer is on none
      of them — Point 3.3 read that first and this point follows it rather than re-deciding it.
      ⚠️ **`StorageContract` was added to `Customers` in `deptrac.modules.yaml`, and the guard is
      why.** `StorageServiceTest` forbids `fopen(` — and eleven other filesystem primitives —
      everywhere outside `Modules/Storage/Infrastructure`, as a property of *every* file in the
      application (§14.2: "local file system behind an abstraction layer"). `fgetcsv` needs a
      stream, so the choice was to cross the boundary properly or to spell the call differently and
      leave the crossing undetectable. `StorageServiceInterface` gained **`readUploadStream()`** —
      the pair to `store()`'s `$sourcePath`, taking a raw path precisely because the file is *not*
      in storage — and `CustomerCsv` now parses a stream it never opened. This is the crossing
      `deptrac.modules.yaml`'s own header anticipated: *"a module may later be allowed to depend on
      StorageContract"*. Contract half only; nothing points at `StorageDriver`.
      **The file is still not stored, scanned or served.** Point 1.2's decision holds:
      `import_batches` has no path column, so `SEC-15`'s scan, `D-39`/`D-71`'s ceiling on a *stored*
      file and `D-38`'s permission-checked download stay out of this point. `AllowedFileType` is
      `D-40`'s six — PDF · JPG · PNG · WEBP · DOCX · XLSX — and **CSV is not among them**, which is
      the other half of why the import file is not an attachment. The size ceiling is
      `config('files.max_size_bytes')` (30 MB, `D-71` superseding `D-39`'s 10 MB) —
      **not `limits.max_file_size_mb`**, which is unseeded with no `config/limits.php` behind it, so
      `SettingReader::integer()` would fall through to a configured **0** and refuse every file.
      Measured before it was used.
      **29 tests · 1543 backend · 346 frontend.** RED first: **29 failed, 0 passed** — nothing
      vacuous this time, because no test asserts a bare 404.
      **Three deliberate breaks, each confirmed to fail and each restored byte-identical
      (`shasum -a 256 -c` → `OK`):** the BOM strip removed (1 failed) · the `name` guard removed
      (2 failed) · `D-31`'s flag pinned to false (3 failed).
      **Problems found: two, both caught by a guard rather than by a reader.** (1) `StorageServiceTest`
      failed on `CustomerCsv.php → fopen(` — the abstraction guard, doing exactly what it was built
      for; the fix is the contract method above, not an exemption. (2) PHPStan level 10 rejected five
      things at once, four of them mine to narrow rather than cast: a `mixed` stream parameter,
      `$flagged and $incomplete++` as a statement, `(int) config(...)` (now `Config::integer`), and a
      test helper returning an ungenericised `TestResponse`.
      **Not covered:** **no owner column in the format** — §3.3 makes `assign` its own permission and
      Point 3.5 its own route, and a spreadsheet full of UUIDs is not a format anyone fills in, so
      imported rows arrive **unowned** and the Manager assigns them. No `D-35` duplicate probe on
      import (`OD-08` is unseeded, so it would do nothing anyway, and §10.2 describes the employee's
      save). No dry-run and no per-row error report — the response carries the four counts and the
      failures are `row_count - imported_count`. No queue: a 30 MB file is parsed inside the request,
      with a `ponytail:` comment naming the chunking upgrade. `customer_status` is still never
      derived, and **there is no import screen** — Point 4.5's.)*

#### Step 4 — screens *(re-decomposed and approved 2026-08-30; supersedes the five-point list of 2026-08-29)*

The owner approved an eight-point decomposition after the sources were read: `4.0` was split out of
`4.1` (a catalogue and a route are not a screen), and **`4.5a` is new** — Flow 7's select-all restore
has no endpoint, and a screen cannot be built on one that does not exist.

- [x] **4.0** `services/customers.ts` · the route · the sidebar item
      *(**The catalogue is the eight endpoints Step 3 built, and nothing else.** `D-67`: the SPA
      consumes `/api/v1` and never owns a calculation, a permission decision or a state transition,
      so every rule this file could restate — who may archive, which scope reaches which row, what
      makes a record incomplete — stays the server's.
      **The query shape is `CustomerListCriteria`'s.** `OpenAPI §6.2` answers an unknown filter with
      a 400 and `ALLOWED_FILTERS` is closed at five, so an unset filter is **omitted** rather than
      sent empty. `false` is a filter and `null` is the absence of one — `filter[is_archived]=false`
      asks for the unarchived, which is a different question from "do not filter on this".
      **`api.ts` gained two things, both measured first.** `request()` JSON-stringifies every body
      and pins `Content-Type: application/json`, so a `FormData` would have arrived as `{}` — hence
      `apiUpload()`, which omits the header so the browser writes its own multipart boundary. And
      `collection<T>()` moved out of `identity.ts` into `api.ts` rather than being copied a second
      time; `identity.ts` now imports it. One unwrapper, one pagination fallback.
      **The screen is real, not a placeholder.** `navigation.ts` states the rule — "a dead link is
      not a permission problem, it is a lie" — so the sidebar item added here resolves to a screen
      that calls `GET /customers` and renders Design System §5.2's loading, empty, error and
      permission-denied states. The table, the filters, the sort and the paginator are 4.1 and 4.2.
      A 403 and a 500 get different screens: one is a boundary, the other is a fault (`SEC-09`).
      **The route names `customer.view` and no scope** — §3.3's scope is answered per row by
      `CustomerRowScope`, and a guard naming one would refuse five of the six roles §8 lists.
      **Two guards fired, and both were right.** (1) `guards.spec.ts` asserted that an Indoor Sales
      caller lands on the fallback *"while §8's screen has not been built"* — it is built now, so the
      redirect reaches `customers`, exactly as `LANDING_ROUTE`'s docblock promised: "each module
      lands by registering its route, not by editing this file". The map was **not** edited.
      (2) `RoleLandingTest` failed with its own instructions — *"'customers' is now a registered
      route… remove it from this assertion and confirm the landing redirect is exercised by
      guards.spec.ts"* — so it gained a `BUILT` list and the fallback is still asserted for every
      role whose §8 screen is unbuilt.
      **17 tests · 1548 backend · 368 frontend.** RED first: **2 test files failed to resolve, 0
      tests ran** — the honest RED for a module that does not exist yet, and therefore one that
      proves nothing on its own, which is why the breaks below matter more than usual.
      **Four deliberate breaks, each restored byte-identical (`shasum -a 256 -c` → `OK`).** ⚠️ **The
      first one passed** — the filter-omission test could not fail, because it only ever passed
      `sector` and `q` and never a `null` boolean, so the defect it existed to catch was untested.
      The test was strengthened (and a second added for `false`), then the same break failed it
      correctly. The other three: the multipart header pinned back to JSON (1 failed) · the 403
      branch collapsed into the error state (1 failed) · a landing route claimed `BUILT` but not
      registered (1 failed).
      **Problems found:** the vacuous test above, and nothing else.
      **Not covered:** no table, no filters, no sort, no paginator, no row actions — 4.1 and 4.2. No
      *My Customers* item for the Team Leader (§8 names one; it is an open question, and their scope
      reaches nothing today anyway). The list body is a plain `<ul>` that 4.1 replaces.)*
- [x] **4.1** the list screen — §5.2's table, server-side sort, the paginator, column priorities
      *(**The sort is the server's, and the tests read the URL to prove it.** §5.2 requires
      "server-side filters/sort/search" and §6.5 requires "Every list is server-paginated. Do not
      create a UI that requires loading all records." A client-side `Array.sort` would order the 25
      rows in hand while claiming to have ordered 4,000, and it would pass any assertion made about
      the rendered order — so every sort assertion is about the query string that was sent.
      **The three sortable columns are `CustomerListCriteria::ALLOWED_SORTS`** (`name`,
      `start_date`, `created_at`) and the initial order is its `DEFAULT_SORT`, restated on the
      screen because `OpenAPI §6.2` answers an undeclared sort field with a 400 — a header this
      screen invented would be a button that breaks it. §6.2's `-` prefix is the descending form.
      **One sort key, though the server parses several.** No source asks the UI for a second, and a
      two-key header interaction is a design nobody has approved; the criteria still carries it.
      **A sort change returns to page 1** — "page 2" is a position in an order, so keeping it after
      the order changes shows a page the user never asked for.
      **Column priorities (§5.2):** name and the §4.5 status always; sector, contact and phone fold
      away below `md`; the two dates below `lg`. §6.5's sticky header, `tabular-nums` on figures,
      and a row hover/`focus-within` state. The block axis does not mirror, so `top-0` is correct in
      both directions (`LogicalPropertiesTest` states that rule itself).
      **Two date columns formatted two different ways, deliberately.** `created_at` is a UTC
      timestamp, so `DB-08` puts it through `toLocaleDateString` into the reader's timezone;
      `start_date` is a calendar date (§4.2, "First engagement") with no timezone, and a `Date`
      would place it at UTC midnight and show the day before to anyone west of Greenwich.
      **§4.5's status is a word, not a code** — `deal_not_completed` never reaches a screen. An
      unknown code prints itself rather than an empty cell, because §3.12 rule 5 makes the set
      configuration.
      **10 tests · 1548 backend (9870 assertions, unchanged — no new PHP or `.vue` file, so no
      per-file guard moved) · 378 frontend (24 files).** RED first: **10 failed, 6 passed**, the six
      being 4.0's own.
      ⚠️ **One of the ten passed in RED and was rewritten before implementation** — "hides the
      paginator when the list fits on one page" passes against a screen with no paginator at all. It
      now asserts both halves in one test, so it cannot pass vacuously.
      **Three deliberate breaks, all caught, restored byte-identical (`shasum -a 256 -c` → `OK`):**
      the page reset dropped from `sortBy` (1 failed) · the raw `customer_status` printed instead of
      its label (1 failed) · `sort` omitted from the request so the order never left the browser
      (3 failed).
      **Problems found:** the vacuous paginator test above; nothing else.
      **Not covered:** no filters, no search box, no row actions, no *My Customers* item — 4.2
      onward. `is_incomplete` and `is_archived` are fetched but not drawn, so `D-31`'s flag is
      invisible on the list. Archived rows are absent by the server's default (`filter[is_archived]`
      defaults false) and nothing on the screen says so yet — 4.2's filter. Three roles still see
      the empty state, and the table changes none of that.)*
- [x] **4.2** the filters and the search — `customer_status` · `sector` · `is_incomplete` ·
      §10.1's `owner_inactive` · `q`
      *(**Every control sends a declared parameter and nothing narrows anything in the browser.**
      `ALLOWED_FILTERS` is closed at five and `OpenAPI §6.2` answers an undeclared one with a 400, so
      the tests assert the query string rather than the rendered rows. The URLs are decoded before
      matching — `filter[sector]` travels as `filter%5Bsector%5D`.
      **`null`, never `false`.** An unchecked *incomplete* box asks nothing about the column;
      `false` would ask for the complete records only, which is a different question and would hide
      every `D-31` row. Pinned by a test that checks and then unchecks.
      **§10.1 is met in its own words** — "a *Customers of deactivated employees* filter on the
      customer screen". It is drawn for everyone who reaches the screen: §10.1 names Team Leader and
      Manager as who must have it, not who must be denied it, and `GET /customers` applies no
      permission carve-out to `owner_inactive`. A client-side role gate would be a restriction no
      server rule enforces, which is exactly what `SEC-09` forbids. **Recorded as a reading.**
      **The sectors come from `DB-05`'s managed list**, not from a copy of §4.2's seeded six —
      `GET /managed-lists/sectors` names no permission, and `listEntries()` already existed in
      `services/admin.ts` (reused, not re-written). Both labels ship on every entry, so neither
      language falls back to a code. A refusal there costs one filter, not the screen: the list still
      loads and still says so. `ponytail:` page 1 only — a 26th sector needs a paged fetch.
      **§4.5's four status codes are restated in the component** because they are a database CHECK
      with no endpoint to ask; the migration's `customers_known_status` is the original.
      **Two empty states, because only one of them is ever true** — "you have no customers" and
      "nothing matched these filters".
      **`filter[is_archived]` is deliberately absent** — it belongs to Point 4.5's archive screen,
      per the approved decomposition.
      **9 tests · 1548 backend (9870 assertions, unchanged again — no new file) · 387 frontend
      (24 files).** RED first: **9 failed, 16 passed** (4.0's six and 4.1's ten).
      ⚠️ **One of the nine passed in RED, for the second point running** — "still lists customers
      when the sector options cannot be loaded" passes against a screen that never asks for options.
      It now asserts that the request was made **and** refused, and fails without the feature.
      **Three of 4.1's tests then failed on a second mount request** — `asked[1]` had become the
      managed-list call. The assertions were re-pointed through a `customerCalls()` filter rather
      than re-numbered, so an index no longer depends on which of two parallel fetches lands first.
      **Four deliberate breaks, all caught, restored byte-identical (`shasum -a 256 -c` → `OK`):**
      `isIncomplete` sending `false` when unchecked (1 failed) · the page reset dropped from
      `applyFilters` (1 failed) · a sector-options failure raised into the screen's error state
      (1 failed) · `q` never passed to the service (1 failed).
      **Problems found:** the vacuous test and the three index assertions above; nothing else.
      **Not covered:** no row actions, no form, no detail page — 4.3 onward. The search is submitted,
      not live: `SearchService` is asked once per submit rather than once per keystroke, and no
      source asks for as-you-type. `q` still matches `name` only (`SearchIndex::Customers::columns()`
      — open question 4). No saved or shareable filter state: nothing is written to the URL, so a
      filtered list cannot be linked or restored on reload. `is_incomplete` and `is_archived` remain
      undrawn **on the row** — the filter exists, the column does not.)*
- [x] **4.3** add/edit form — sector from the managed lists, region free text with suggestions
      (`D-20`), the yellow duplicate warning, 422 `details` bound to the fields
      *(**`CustomerFormModal.vue`**, wired into the list screen. §4.2's **ten** user-entered fields
      through `POST /customers` and `PATCH /customers/{id}` — Point 4.0's catalogue, unchanged; no
      new service code.
      **The three prohibited columns are never sent.** `customer_status`, `is_archived` and
      `is_incomplete` are `prohibited` in `SaveCustomerRequest` — a 422, not a silent drop (§4.5,
      `D-49`) — so a form that posted them would simply never save.
      **No owner control, and that is a narrowing awaiting a `D-xx`.** `sales_owner_id` is writable
      on create, but changing it is `customer.assign` with its own §3.3 row and its own route
      (`OpenAPI §7.2`, Point 3.5), and filling a picker needs `GET /users`, which `customer.create`
      does not carry. A control that 403s for the person looking at it is worse than no control.
      **A customer created here therefore arrives unowned** unless the creator's scope files it
      under them — the same state imported rows are in.
      **§4.5's derived status is drawn read-only with its reason**, as text rather than a disabled
      input: a disabled input still reads as a control somebody could be given.
      **§10.2's `D-35` warning arrives after the write**, because the server does not block. The
      dialog stays open so the names can be read and the list refreshes underneath; the similar
      customers are **not** links, on `navigation.ts`'s rule — Point 4.4's detail page does not
      exist yet.
      **Design System §5.2's unsaved-change warning is implemented here** — the register's debt 13a,
      owed since Module 1. Cancel, the scrim and Escape take one path (§6.1: Escape must not discard
      silently); nothing typed closes at once, something typed asks first.
      **§6.1's server validation lands on the field it names**, through the existing
      `ApiError.messageFor()` rather than a second copy of a rule, and entered values survive a
      refusal.
      **`SEC-09`:** the New customer and Edit controls mirror §3.3's two permission rows and enforce
      nothing; `CustomerWriteEndpointTest` is the gate. Each of those two tests asserts **both**
      halves — drawn for the holder, absent for the one without — because a one-sided assertion
      passes against a screen that draws nothing at all.
      **17 frontend tests · 1551 backend (9874 assertions) · 404 frontend (25 files).**
      **The backend number moved for the first time since 4.0, and the arithmetic is the check:**
      the new `.vue` enters both `LogicalPropertiesTest` providers and the new `.spec.ts` enters
      `markupFiles` (**+3 tests**), and `NoHardCodedTextTest` asserts once more per vue file
      (**+4 assertions**).
      **Six deliberate breaks, each failing the tests it should and no others, all restored
      byte-identical (`shasum -a 256 -c` → `OK`):** a prohibited column in the payload · the
      unsaved-change check bypassed · the `D-35` list dropped · the server field message discarded ·
      `canEdit` forced true · client validation bypassed.
      **Problems found: two.** The frontend RED ran **0 tests** — the component did not exist, so the
      import did not resolve; that RED is no evidence at all, and the six breaks above are the only
      proof the suite bites. And `NoHardCodedTextTest` caught a real defect: a bare `ℹ` glyph reads
      as user-facing text (it does not flag `⚠`), so both markers now use the repo's inline
      `aria-hidden` SVG convention from `states/ErrorState.vue`. The file entered that test's vue
      inventory **only after the scan half passed on it** — the list is coverage, not suppression.
      Two smaller ones, both caught by `vue-tsc` and both the same lesson: `ListEntry` has **no
      `id`** (assumed, then read — the option is keyed on `code`), and an undeclared `vi.fn(async
      () => …)` types `mock.calls` as the empty tuple.
      **Not covered:** `D-20`'s region **suggestions** — the field is free text and no suggestions
      endpoint exists; the other half is owed. `D-35`'s warning still never fires in practice, since
      `limits.customer_similarity_threshold` is unseeded by owner decision — the path is tested
      against a stubbed response. No owner picker (above). No detail page to open a duplicate in
      (4.4). No archive or restore from the form, no bulk actions, no import screen — 4.5, 4.5a,
      4.6.)*
- [x] **4.4** detail page — summary first, related second, actions by permission
      *(**`CustomerDetailView.vue`** on the new route `/customers/:id`
      (`customer-detail`), behind the same `customer.view` and **no scope** as the list — whether
      *this* row is reachable is answered per row by `CustomerRowScope`. **No sidebar item:** §8
      lists *Customers*, not a record.
      **The 404 is one state and says nothing about which case applied.** `OpenAPI §5.1` defines it
      as "does not exist **or is not visible to the caller**. Do not reveal which case applies",
      which is why `CustomerNotFound` is a single exception; a screen saying "you do not have access
      to this customer" would undo that in the one place a person reads it. A **403** is a different
      answer — about the caller, not about a row — and gets `PermissionDeniedState`.
      **Summary first** (§5.2): §4.2's fields, with a nullable one printed as an em-dash rather than
      as `null`; §4.5's derived status as a chip with the reason beside it (never a control); and
      **`is_archived` / `is_incomplete` drawn as chips** — the first place either flag is visible per
      customer, since the table has no column for them.
      **Related second:** `D-16` puts the communication history in `notes`, so that is the section.
      **There is no deals or timeline section** — that is Module 5, and drawing an empty one would
      imply a feature.
      **Actions by permission:** Edit only, gated on `customer.edit`, reusing Point 4.3's modal, and
      the page **re-reads** after a save rather than patching the object in hand — `is_incomplete`
      is the server's calculation.
      **Two links that could not exist before this point.** The table's name cell now resolves to the
      record, and §10.2's similar customers became links — its other half, "or **open the existing
      customer instead**", which 4.3 had to leave as plain names on `navigation.ts`'s rule.
      **10 frontend tests · 1554 backend (9878 assertions) · 414 frontend (26 files).** The backend
      delta is `+3` / `+4`, the same shape and mechanism as 4.3: a new `.vue` enters both
      `LogicalPropertiesTest` providers, a new `.spec.ts` enters `markupFiles`, and
      `NoHardCodedTextTest` asserts once more per vue file.
      **Six deliberate breaks, each failing the tests it should and no others, all restored
      byte-identical (`shasum -a 256 -c` → `OK`):** a 404 rendered as permission-denied · the
      not-found sentence naming permission · no re-read after save · `orDash` returning the raw
      value · the row link pointing at the wrong id · the duplicate names un-linked.
      **Problems found: three.** (1) The first run failed six tests because the stub answered
      **every** URL with the record — the detail page asks for two things on mount, so `listEntries`
      received a customer and `items` stopped being an array. Point 4.2's lesson exactly; the stub
      now routes by resource. (2) **Adding the `RouterLink`s made 51 tests pass while the links
      rendered as nothing** — `Failed to resolve component: RouterLink` was a warning, not a
      failure. Both specs now mount a real router and assert the resolved `href`. (3) The
      persisted-cwd trap bit again: a relative path from a shell already inside `crm/`.
      **Not covered:** no archive/restore (§3.3's merged `customer.archive` — Point 4.5) and **no
      assign**, though Point 3.5 shipped the route: **no point in the approved list names a screen
      for it**, and building one here would be going past the plan. Recorded as an open question.
      **No "years of dealing"** — §4.2 says `start_date` "drives" it and that is the only mention:
      no field on the wire, no definition of a partial year, no rounding rule. Computing one would
      be the SPA inventing a business value (`D-67`).)*
- [x] **4.5** the archive screen — archive and restore individually, select-all restore
      *(**It is the list screen's other half, not a new screen.** §8 does name an **Archive** item
      for the Manager and the Team Leader — but Flow 7's first row puts a rejected *quotation* in
      "the quotation archive" while "**the customer stays in the list**", so that item spans modules
      and its other half is Module 7's. Module 3 fills the half it can, where the rows already are:
      **`filter[is_archived]`**, which Point 4.2 left out on purpose. A dedicated `/archive` route
      holding customers alone would promise the §8 screen and draw a fraction of it —
      `navigation.ts`'s "a dead link is not a permission problem, it is a lie" in a third costume.
      **This is a reading, not a documented rule, and it is the reversible half of this point.**
      **The toggle has two positions because the server has two.** `CustomerListCriteria:89` reads
      `$filters['is_archived'] ?? false`, so absence **is** `false` and there is no "show both" for
      a third position to ask for. The filter is sent in **both** positions, `false` included: the
      server would default to the same thing, but a screen showing one half of the records should
      say which half rather than leave it implied.
      **One permission, both directions.** §3.3 writes `archive / restore` as a single merged row
      (`All · Team · — · — · — · — · —`) and `PermissionMatrix` carries no `customer.restore`, so
      both controls are drawn by `customer.archive`. `SEC-09`: the buttons are the menu and
      `CustomerArchiveEndpointTest` is the gate.
      **The row decides the action, not the filter** — a row carries `is_archived`, so a list
      holding both kinds still offers the right control on each one.
      **§6.2's Danger variant is the archive half only**; a restore puts a record back and is not
      destructive. **§6.6's confirmation is asked for archive and for the bulk restore, and not for
      a single restore** — §6.6 names archive, deactivate, rejection, return and approval, and a
      single restore is none of them and is idempotent besides (Point 3.4). The archive question
      states its consequence *and* what does not happen: nothing is deleted, and it can be restored.
      **§6.6's "return focus to the invoking control" is implemented in the caller**, not by editing
      Module 1's `ConfirmDialog` — which is **reused as it stands**: it is already generic and
      text-driven, with `danger`, Escape and `role="alertdialog"`. Moving it to a shared folder would
      edit Module 1's screens for a tidier import path; recorded as debt instead.
      **⚠️ Select-all restore is a loop over the singular route — the owner's decision of
      2026-08-30, and a narrowing of `API-07`.** `API-07` ("Bulk operations for archive and
      restore") and `OpenAPI §7.3` describe an endpoint, and none is built. Each call in the loop
      carries the same `customer.archive` middleware and the same row-scoped lookup, so §7.3's
      "authorize and audit each affected record" and "do not allow a bulk request to bypass row
      scope" hold **by construction** rather than by a new server promise. `Promise.allSettled`,
      never `all`: §7.3 also asks for "per-record result data", and one refused row must survive as
      a refusal instead of collapsing the batch. **Awaiting a `D-xx`.**
      **Bounded by §6.5**, which is the rule and not a convenience: "Every list is server-paginated.
      Do not create a UI that requires loading all records" — so select-all is the page in hand
      (25 default, 100 max), and the selection is cleared on every reload, because ids from one page
      address different rows on another.
      **10 frontend tests · 1554 backend (9878 assertions, unchanged) · 424 frontend (26 files) ·
      pint 380 · phpstan [OK] · deptrac 0/0 twice.** The backend number not moving **is** the check:
      this point adds no `.vue`, `.ts` or `.php` **file**, and every per-file guard counts files.
      **Five deliberate breaks, each failing the test written for it, restored byte-identical
      (`shasum -a 256 -c` → `OK`):** the filter never sent · archive fired without asking ·
      `allSettled` swapped for `all` · focus never returned · the loop restoring every row instead
      of the selected ones.
      **Problems found: three.** (1) **The focus test passed alone and failed in the full suite** —
      `ConfirmDialog` moves focus inside a `setTimeout(…, 0)` and `flushPromises` drains microtasks
      only, so the assertion was a coin flip that landed differently under suite load. It now waits
      on a macrotask. The half that flaked is the half that makes the test non-vacuous: without it,
      `invoker.focus()` would "return" focus to a button that never lost it. (2) **424 unit tests
      passed while `vue-tsc` failed** — `exactOptionalPropertyTypes` refuses `attachTo: undefined`;
      the option is spread conditionally now. The documented trap that `test:unit` is not the gate,
      hit again. (3) The persisted-cwd trap bit once more on a relative path.
      **Not covered:** `API-07`'s endpoint still does not exist, so **there is no bulk archive**
      (which no source clearly documents anyway) and no server-side bulk anything. Select-all does
      not cross pages. **Nothing here changes who may *view* an archived row** — §3.3's `view` row
      carries no archived carve-out, so the acceptance criterion below stays unticked. Still no
      assign screen (Point 3.5's route has no UI at all) and no import screen (4.6).)*
- [x] **4.5b** ⚠️ **unplanned, owner-approved 2026-08-30** — the Super Admin's menu drew every
      business screen, including *Customers*, which §8 does not give them
      *(**Reported from the running app by the owner.** §3.3's seven columns are
      `Manager · TL · Out.Sup · Out.Sales · Indoor · Procure · CEO` — **there is no Super Admin
      column** — and §8 gives the Super Admin *"22 administrative screens (section 13)"* with no
      Customers among them. `navigation.ts:60` already knew: its comment says "§8 puts *Customers*
      on **six** roles' screens".
      **Cause:** `hasPermission()` answers §3.1's unconditional access first (`auth.ts`), and
      `AppSidebar.vue` filtered the menu with it — so one function was answering two different
      questions. **The old docblock's justification was measured and found false:** it claimed the
      override was needed because the Super Admin's grant rows "authorise nothing for them"; the
      seeded role holds **nine grants, every one `admin.*`** — precisely §13's screens.
      **Fix:** a second question, `holdsPermission()`, identical to `hasPermission` minus the §3.1
      override, used by the sidebar filter **only**. Exactly one item disappears — *Customers*; the
      six others are either held (`admin.create_user`, `admin.manage_roles`, `admin.fx_rates`) or
      carry `permission: null`.
      **Deliberately NOT a gate (owner's decision, 2026-08-30).** The router guard and every
      in-screen control still use `hasPermission`, and `AuthorizeAction` still short-circuits on the
      server — so the Super Admin may still reach `/customers` by URL and the API still permits it.
      A guard that refused where the API permits would be a client-side restriction with no server
      counterpart, which is the shape `SEC-09` warns about.
      **Landing checked before the change, not after:** `LANDING_ROUTE.super_admin = 'admin'`, which
      is unregistered, so they fall back to `home` — hiding the item strands nobody.
      **6 frontend tests · 430 frontend (26 files) · 1554 backend (9878, unchanged — no new file) ·
      pint 380 · phpstan [OK] · deptrac 0/0 twice.** The sidebar's permission filter had **no test
      coverage at all** before this; it does now.
      **Two deliberate breaks, each failing the test written for it, restored byte-identical
      (`shasum -a 256 -c` → `OK`):** the sidebar put back on `hasPermission` · `holdsPermission`
      given the override back.
      **Problems found: two.** (1) **The first assertions read the brand line, not the menu** — the
      product name is "سمارت تكنولوجي — نظام إدارة **العملاء**", which contains the Customers label,
      so `text()` matched it and the test failed against working code. The helper now reads
      `nav a` link texts. A whole-component `text()` match is a substring trap in any localised UI.
      (2) The persisted-cwd trap again.
      **Not covered:** the same §8-vs-§3.1 split will apply to **every future module's screen** —
      each new business nav item is correct for the Super Admin only because `holdsPermission` now
      asks the right question; nothing enforces that a future item uses it. And §8's *Procurement*
      list has **no Customers screen** while §3.3 grants them `view: Asgn` — an unrelated
      document-level disagreement, recorded here and not acted on.)*
- [ ] ~~**4.5a** bulk restore endpoint~~ — **superseded by the owner's decision of 2026-08-30**: 4.5
      ships select-all as a client loop over `PATCH /customers/{id}/restore`, so the screen no longer
      blocks on this. **The gap itself remains open**: `API-07` and `OpenAPI §7.3` document a bulk
      archive/restore endpoint that is not built, and it stays on the register awaiting a `D-xx`
- [x] **4.6** the `.csv` import screen — the four counts, and a link to the incomplete filter
      *(**`CustomerImportModal.vue`, a dialog on the Customers screen — no route and no nav item.**
      §8 names no *Import* item for any role, and Point 4.5b was the cost of a menu offering a
      destination §8 does not name. Import is an action on the Customers screen, so it lives where
      that screen is.
      **§3.3's `import (Excel)` row is `All · — · — · — · — · — · —`** — the Manager alone, a dash
      even for the Team Leader — so the button is drawn by `customer.import` and
      `CustomerImportEndpointTest` is the gate (`SEC-09`). §6.2 keeps one Primary per context and
      *New customer* is it, so Import is Secondary.
      **CSV and not Excel is the owner's narrowing** (2026-08-29, `fgetcsv`, no library); the
      permission keeps the document's name. Still awaiting a `D-xx`.
      **Four numbers, and the fourth is this screen's own.** `ImportBatchPayload` sends
      `row_count`, `imported_count` and `incomplete_count` and deliberately **no** failure count —
      its own docblock says why: "a field that can disagree with the two it is derived from is a
      field that eventually will". Failures are `row_count − imported_count`, computed here.
      ⚠️ **A failed row and an incomplete row are not the same thing, and the labels say so.**
      `D-31` accepts incomplete data and flags it — **those rows were imported** and are inside
      `imported_count`. A failure saved nothing at all (a blank name violates the table's CHECK).
      **§10's "dedicated filter", reached rather than described.** The result offers Point 4.2's
      `is_incomplete` filter, and only when `incomplete_count > 0` — a control offering a filter
      that would match nothing is a control that lies. It is an **emit, not a `RouterLink`**,
      because no filter state reaches the URL (standing debt) and a link could not carry it.
      **Design System §6.3's four for a file upload:** allowed format · configured size limit ·
      upload status (§6.1's text alternative on the busy button, not a bare spinner) · the
      permission that draws it. §6.1's server validation is `ApiError.messageFor('file')` — the
      server's own sentence — **with the chosen file preserved** through a 422.
      ⚠️ **The 30 MB limit is a label, not a check, and it can drift.** The ceiling is
      `config('files.max_size_bytes')` (`D-71`), server-side configuration the SPA has no endpoint
      for: `GET /system-limits` needs `admin.system_limits`, which the importing Manager does not
      hold, and its `limits.max_file_size_mb` is unseeded anyway. §6.3 requires the limit be shown,
      so it is a string in the lang file — and if `FILES_MAX_SIZE_BYTES` is ever set, the label
      lies while the server stays right. **On the register.**
      **12 frontend tests (9 modal + 3 wiring) · 1557 backend (9882) · 442 frontend (27 files) ·
      pint 380 · phpstan [OK] · deptrac 0/0 twice.** The backend delta is **`+3` / `+4`**, the
      predicted shape for one new `.vue` plus one new `.spec.ts` — third time it has closed to the
      unit.
      **Four deliberate breaks, each failing the test written for it, restored byte-identical
      (`shasum -a 256 -c` → `OK`):** the multipart field renamed off `file` · failures reported as
      the incomplete count · the incomplete filter offered unconditionally · the chosen file
      discarded on a refusal.
      **Problems found: three.** (1) **The 422 fixture used the wrong envelope shape** — a map of
      field to messages, where `OpenAPI §5.1` defines `details` as an **array** of
      `{field, code, message}`. The screen fell through to its generic sentence and looked exactly
      like a code bug. Checked against `CustomerFormModal.spec.ts`'s working fixture rather than
      guessed twice. (2) A `docker run` fired from the repo root instead of `crm/` reported
      **"no tests"** and left a stray root `node_modules/.vite`, which only `crm/.gitignore`
      ignores; removed. (3) `NoHardCodedTextTest`'s inventory assertion sits **before** the scan
      loop, so the first failure proved only that the set changed — the scan half was run
      separately, after the file was listed, and passed (48 tests).
      **Not covered:** no dry-run, no per-row error report (a failure is arithmetic, not a reason),
      **no queue** — a 30 MB file is parsed inside the request — no `D-35` duplicate probe on
      import, and **imported rows arrive unowned** with no screen able to assign them (Point 3.5's
      route still has no UI). The import history is not listed anywhere: `import_batches` is
      written and only the batch just created is ever shown.)*
- [x] **3.7** the import reads a header by the **word**, not by the identifier — an unplanned point,
      raised by the owner from the running application on 2026-08-30 and approved as a point of its
      own the same day.
      **The report.** A real export was refused with *"This file has columns the importer does not
      accept: contact, second phone, start date"*. Its header line read
      `Name,Sector,Region,Contact,Phone,Second phone,WhatsApp,Email,Start date`, and six of its nine
      columns matched while three did not.
      **The diagnosis: the refusal was correct, and the contract was too narrow.** `CustomerCsv`
      compared each cell — lower-cased and trimmed — character for character against
      `CustomerDraft::WRITABLE`, which is §4.2's list of column *identifiers*. Nothing in any source
      says a file must repeat an identifier's punctuation, and a refusal over a space is one nobody
      can act on without being shown the schema. Refusing rather than silently dropping was right
      and is unchanged.
      **Two mechanisms, and the split is deliberate.** (1) **Normalisation** — lower-case, and any
      run of spaces or hyphens collapsed to one underscore — settles `Start date`,
      `Contact-Person` and every future multi-word field. It invents no vocabulary: it is the same
      word with the spreadsheet's punctuation. (2) **`ALIASES`, exactly two entries**, for the
      headers that are a *different* word, and each is quoted rather than guessed: `contact` →
      `contact_person` because §4.2 itself describes the field as *"Single contact (`D-18`)"*, and
      `second_phone` → `phone2` because `customers.attributes.phone2` is **already** the words
      "second phone" in the lang files — the name every validation message gives that field. Nothing
      else: `phone_2`, `mobile`, an Arabic label would each be a guess about a file nobody has shown.
      **The aliases are a constant, not a lang-file lookup**, and that was a decision rather than an
      oversight: deriving the accepted set from `customers.attributes` would make a data-import
      contract change whenever a translator edits a label, and make it depend on the caller's
      locale — a file that imports for one user and is refused for another.
      **One field, one column.** Aliases create a new way for two headers to mean one field, so they
      owe a guard: a field named twice is refused with a new `duplicate_columns` message naming the
      **field**, not the headers. The same guard also closes a pre-existing hole — `name,name` used
      to be accepted with the last column silently winning. One check, both cases (`CLAUDE.md`: the
      lazy fix is the root-cause fix).
      **An unknown column is now named as the person wrote it.** Reporting the normalised form would
      answer a complaint about `Sales rep` with the word `sales_rep`, which describes the importer's
      internals to somebody hunting for their own spreadsheet column.
      **1703 backend (10819 assertions) · 446 frontend (27 files) · `npm run build` clean · pint 412
      files · PHPStan level 10 clean · deptrac violations 0 / uncovered 0 on both configs.**
      RED first: **8 failed, 29 passed** — and the 29 are the pre-existing controls in this file,
      which is what proves the change did not move them. ⚠️ One new test **passed in RED and was
      rewritten**: `two headers naming one field are refused` went green for the wrong reason —
      `contact` was then simply an unknown column, so the file was refused by a different rule. It
      now asserts the message names the mapped field `contact_person`, which only the collision
      refusal prints, and it failed in RED after that.
      **Two deliberate breaks, neither a deletion:** (1) the normalisation's replacement changed from
      `'_'` to `''` — the plausible other reading of "remove the punctuation" → failed **exactly**
      the four tests that need a separator to become an underscore, and correctly left the `contact`
      alias test passing; (2) the unknown-column report changed back to `strtolower($written)` — the
      previous behaviour, and the plausible regression → failed **exactly** the one test that pins
      it. Both restored, confirmed with `shasum -a 256 -c`.
      **Verified against the real file, not only against fixtures:** the owner's original
      `customers_import.csv` — unmodified, BOM and CRLF intact — was parsed through `CustomerCsv`
      itself inside the container: **18 rows accepted**, keyed
      `name,sector,region,contact_person,phone,phone2,whatsapp,email,start_date`. It had been
      refused before this point.
      **Problems found:** the false-pass above, and nothing else. Assertion delta reconciled to the
      unit **by measurement**: this test file was 141 assertions before and is 180 after — the whole
      +39, since the point adds no `app/` file, no route and no migration, so
      `NoHardCodedTextTest`, `EndToEndConnectivityTest` and `UserSchemaMigrationTest` all move by
      zero. 10780 + 39 = 10819.
      **Not covered:** the import still runs **no `D-35` duplicate probe** — importing the same file
      twice creates duplicates in silence, which is Point 3.6's pre-existing gap and is unchanged
      here. Arabic header labels are **not** accepted, deliberately. The upload modal still does not
      tell the person which columns are accepted, so a refusal is the first place they learn the
      vocabulary. Values are untouched: a phone written in Arabic-Indic digits (`٠١٢٠٤٧٢٦٣٤٠` in the
      owner's file) is stored as typed and will not match a search in Latin digits.

> **Out of scope for Module 3, stated so it is not looked for here.** Customer-status derivation
> (`recompute_customer_status` → Module 5) · excluding incomplete records from financial reports
> (Module 13) · free-text `q` on `GET /users` (debt, owner decision 2026-08-29) · duplicate merging
> (§10.2: post-MVP).

**Acceptance criteria**
- [ ] Sales employee sees only own customers · Team Leader sees team · Manager sees all
- [x] Excel import with missing fields → record saves flagged **"incomplete"**, in a dedicated filter
      *(**Ticked 2026-08-30, on the owner's instruction, with the third clause's missing evidence
      supplied first.** Clause by clause:
      **"import with missing fields"** and **"saves flagged incomplete"** —
      `CustomerImportEndpointTest::test_that_a_row_with_a_missing_field_is_flagged_and_still_saved`
      asserts `imported_count: 1` *and* `is_incomplete => true` in the table, with
      `test_that_a_row_with_every_field_is_not_flagged` as its other half. `D-31`: the flag is not
      a refusal.
      **"in a dedicated filter"** — ⚠️ **this clause was NOT proved when the tick was requested.**
      The only coverage was frontend specs asserting `filter[is_incomplete]=true` **reaches** the
      URL, which proves the request is built and not that the server narrows anything: a filter
      that is accepted and ignored answers 200 with every row and passes every test that reads
      only a query string. `CustomerListEndpointTest` had tests for `is_archived` and
      `owner_inactive` and **none for the one filter this criterion names**.
      So `test_that_the_incomplete_filter_selects_only_flagged_records` was written before the
      tick: unfiltered shows both · `=true` shows only the flagged row · `=false` shows only the
      complete one, because absence is not `false` for this filter and a filter stuck on one
      answer must not pass. **Broken on purpose** (`if (false)` around the `where`) — it failed
      that test **and only that test**, 19 others still green, which is the measure of what was
      missing. Restored byte-identical (`shasum -a 256 -c` → `OK`).
      **The screens:** Point 4.2 draws the filter, Point 4.6 reports `incomplete_count` and sends
      the person to it, and only when the count is above zero.
      ⚠️ **One narrowing rides along with this tick:** the criterion says **Excel**, and the format
      shipped is **CSV** (owner, 2026-08-29 — `fgetcsv`, no library). The mechanism the criterion
      describes is met in full; the file format it names is not, and that still awaits a `D-xx`.)*
- [ ] Incomplete records are **excluded from financial reports** until completed
- [x] Name similar to an existing customer → **yellow warning**; the employee decides, no blocking
      *(**Ticked 2026-08-30. `OD-08` is answered: `limits.customer_similarity_threshold = 0.60`,
      seeded by `SystemSettingsSeeder`.** The probe, the row-scoped query, the `meta` block and the
      form's yellow warning were all built at Points 3.3 and 4.3 and **had never once fired** — the
      limit was deliberately unseeded, so every existing test injected its own number through
      `thresholdOf()` and would have kept passing had the seeder shipped nothing.
      **The number was measured before it was chosen.** `similarity()` over `translate()`-folded
      names: the documented `D-35` pair (`أحمد للتجارة` / `احمد للتجاره`) **1.00** ·
      `Alpha Trading` / `Alpha Trading Co` **0.82** · `مؤسسة الأمل` / `مؤسسة الأمل الطبية` **0.71** ·
      `Alpha Trading` / `Alpha Trading Company` **0.64** · unrelated names **0.00**.
      ⚠️ **No threshold separates the cases cleanly, and this is recorded rather than smoothed over.**
      `أحمد للتجارة` against `محمد للتجارة` — **different companies** — scores **0.62**, *above*
      `شركة النور` against `شركة النور للتجارة`, which is **one company with a trade suffix** at
      **0.58**. The overlap is a property of trigram similarity, not of the value, and no number
      fixes it. `D-35` warns and never blocks or merges, so `0.60` was chosen to favour firing over
      silence: a false warning costs a glance, a missed one costs a duplicate record. **At 0.60 the
      sample carries one false positive (0.62) and one miss (0.58)** — stated so neither is later
      discovered as a surprise.
      **Proved on the seeded value, not an injected one:**
      `CustomerWriteEndpointTest::test_that_the_seeded_threshold_warns_on_a_duplicate_and_stays_silent_otherwise`
      runs `SystemSettingsSeeder` and asserts both halves — the §10.2 pair warns, and two different
      firms sharing a `مؤسسة` prefix do not.
      **Two guards broke by design and were updated with their reasons, not quietly widened:**
      `SystemSettingsSeedingTest::test_that_no_undocumented_limit_is_invented` (one key → two) and
      `SystemLimitEndpointTest::test_that_an_unvalued_limit_reports_null_rather_than_a_default`
      (6 unvalued → 5, plus an assertion on the seeded value).
      **Two deliberate breaks, restored byte-identical (`shasum -a 256 -c` → `OK`):** the seeding
      removed (4 tests failed) and — the meaningful one — **the value lowered to a plausible `0.45`,
      which failed the behaviour test**, proving it discriminates on the number and not merely on
      the row existing.
      **1560 backend (9908 assertions) · 442 frontend (27 files) · pint 380 · phpstan [OK] ·
      deptrac 0/0 twice.** `+2` tests, no new file. **Applied to the dev database** so the warning
      is live there, not only in the test suite.
      **Not covered:** the threshold is one number for every sector and language; `D-35`'s
      *merging* remains post-MVP (§10.2); and the probe is still row-scoped, so two callers with
      disjoint scopes can each create the same customer (register item 3b).)*
- [ ] Manually archived customer → visible only to Manager and Team Leader
- [ ] Change of sales owner → customer and full history transfer + audit entry
- [ ] **Every search call goes through `SearchService`** — no direct queries
