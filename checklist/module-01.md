> Frozen history of Module 1, cut verbatim from `CHECKLIST.md` on 2026-09-12 (commit `f8b993c`).
> Open boxes here are stale copies — the live ones are in `CHECKLIST.md`. Do not tick here.

## Module 1 — Identity & Permissions (Dynamic RBAC)

> As a team member, I want to log in with my email and password, so that I can access the system
> with my role's permissions.

**Tables** `users` · `roles` · `permissions` · `role_permissions` · `user_sessions`

#### Step 1 — schema *(point order approved 2026-08-23)*

- [x] **1.1** `roles`, `permissions`, `role_permissions` — the tables `SEC-07` means when it says
      "dynamic RBAC **stored in the database**".
      **A permission is a triple, not a pair.** `§3.2` is explicit — *"Permission = Resource +
      Action + Scope"*, with `customer.view.own` as its own example — so the scope is part of the
      permission's identity and lives in `permissions`. `role_permissions` says only which triples
      a role holds, which is what makes a permission change an `INSERT` rather than a deployment.
      The Point 7.2 registry groups the same matrix the other way, as a resource+action carrying a
      `Grant` per role, because that is the readable shape for `§3.3`…`§3.12`. **The two were
      proven to agree by expanding one into the other: 143 distinct triples and 212 links**,
      measured against `PermissionMatrix::all()` before the migration was written, and asserted by
      a test that writes the entire matrix into the tables and counts it back out.
      **Every unique index is partial — `WHERE deleted_at IS NULL` — and that is a correctness
      decision, not a preference.** `DB-01` soft-deletes everything and `D-34` archives rather than
      deletes, so a plain `UNIQUE` would let one archived role reserve its slug for the lifetime of
      the system: nothing could take that name again, and the archived row could not be restored
      beside a replacement either. Laravel's `unique()->where()` is **silently ignored** and yields
      an ordinary index — the same trap `scopeIndex` already documents — so these are raw DDL, and
      a test reads `pg_indexes` to confirm the predicate is really there rather than trusting the
      call that looked like it worked.
      **`§3.2`'s five scopes are a database `CHECK`.** A sixth value is not a narrower permission,
      it is one that no scope comparison will ever match — failing open or closed depending on the
      caller, and silently either way. The migration writes five literals and `Scope` declares five
      cases; neither file can read the other, so the test is the only place they must agree.
      **`deleted_by` is deliberately absent.** `DB-02` names four audit columns and this is not one
      of them, `standardAudit()` creates four, and no table Module 0 shipped carries it. A test
      asserts the absence, and reads the required four **out of the `DB-02` row in §4.8** rather
      than restating them, so the rule and the schema cannot drift apart quietly.
      **Checks:** 28 tests, 345 assertions. `down()` proven by `migrate:reset` — all three tables
      gone, zero leftover indexes, the `CHECK` gone with its table. Three deliberate failures, each
      restored under `shasum -a 256 -c`: `action` narrowed 64→8 produced *"value too long for type
      character varying(8)"* on the real matrix row `deal.assign_owner`; `role_permissions` removed
      from `down()` produced *"cannot drop table permissions because other objects depend on it"*;
      the slug index made non-partial broke both the archive behaviour and the structural check.
      **Not covered:** no row is seeded — the seeder that carries the Point 7.2 registry into these
      tables is Module 1's application work, and the only rows these tables have ever held were
      written by the test and rolled back with it. No Eloquent model, no policy, no endpoint, and
      **no enforcement**: this is storage, and `SEC-07`'s "enforced at the API and row level" is
      not started. `created_by`/`updated_by` still carry no foreign key — `standardActorForeignKeys()`
      waits for the real `users` table, which is 1.2. `is_system` is recorded but nothing yet
      refuses to delete a system role
- [x] **1.2** `users` replaced, and `user_sessions` created.
      **The scaffold is replaced, not altered, and the reason is a column type.** `$table->id()` is
      a bigint auto-increment; `D-61` fixes UUIDv7 and `standardActorForeignKeys()` is waiting to
      point `created_by` at a `uuid`. No `ALTER` reconciles those, which is what
      `design/DATABASE.md §4` means by *"replaced in Module 1, not extended"*. Because `DEV-03`
      forbids editing a migration that has already run, `0001_01_01_000000` is untouched and a
      **correcting** migration drops what it made and builds the real table — and `down()` **puts
      the scaffold back**, bigint key and `remember_token` and all, which is the half that makes
      the pair genuinely reversible. A test asserts the restored table is the scaffold.
      **`user_sessions` is a device list, not a session store.** `SEC-05` reads "8-hour session
      timeout + **active device list** + force logout", and `SESSION_DRIVER=redis` in both `.env`
      and `.env.example` — checked. So Laravel's session payload lives in Redis and this table
      records which devices are signed in, carrying `session_id` so a row can actually be matched
      to that session and revoked. **It has no `payload` column**, and a test fails if one appears
      while the deployed driver is still Redis — read out of `.env.example`, because the suite runs
      on `SESSION_DRIVER=array` and the runtime value says nothing about deployment. That
      distinction was found by the test failing on its first run.
      **`is_active` and `deleted_at` are different states, and both are needed.** `D-34`
      deactivates rather than deletes *and keeps the deals attached* — a deactivated user must stay
      visible to the Team Leader who reassigns their work, which a soft delete would hide. So
      archiving frees the email address and deactivating does not, and there is a test for each.
      **`role_id` is `RESTRICT`, emphatically not `CASCADE`.** Cascading would mean deleting a role
      deletes the people who held it, which is the exact inverse of `D-34`. `user_sessions.user_id`
      does cascade, for the repairs `DB-01` still allows.
      Also: `is_hidden` for `§3.12` rule 6, `failed_login_attempts` + `locked_until` for `SEC-03`
      with a `CHECK` that the counter cannot go negative, `password` at 255 for either `SEC-02`
      algorithm, and `last_activity_at` as a real `timestamptz` rather than Laravel's epoch integer
      because `D-29` compares it against an interval and `DB-08` wants UTC types.
      **Checks:** 23 tests in `UserSchemaMigrationTest`, 700 in the suite. Four deliberate
      failures, each restored under `shasum -a 256 -c`: dropping `is_hidden` broke the **migration
      itself** — `column "is_hidden" does not exist` while building its index, a harder failure
      than an assertion; the session key made non-cascading gave `violates foreign key constraint
      "user_sessions_user_id_foreign"`; reversing the `down()` order gave `cannot drop table users
      because other objects depend on it` **and took two other modules' down tests with it**, which
      is the correct blast radius for a broken rollback; adding a `payload` column tripped the
      Redis pin.
      **Not covered:** still no seeded row, no policy, no endpoint, **no authentication**. Nothing
      hashes a password, nothing counts a failed login, nothing expires an idle session and nothing
      hides the Super Admin — `is_hidden` is a column that no query yet reads. `SEC-06`
      two-factor has no columns at all. **`standardActorForeignKeys()` is now possible and has not
      been applied**: `created_by`/`updated_by` across every Module 0 table still carry no foreign
      key, which stays the recorded `DB-04` gap `D-72` named. And the scaffold's other two tables,
      `password_reset_tokens` and `sessions`, were deliberately left in place rather than dropped
      in passing — both are now unused and both are on the debt register

#### Step 2 — models, seeders, authentication, enforcement ✅ *(complete 2026-08-24; point order approved 2026-08-23)*

- [x] **2.1** The Eloquent models, and the seeders that fill Step 1's tables with `§3`.
      **Seeded, and counted against the matrix rather than against a number somebody typed:**
      8 roles · **143 permissions** · **212 grants** · 8 test users, 1 of them hidden. Every one of
      those figures is derived from `PermissionMatrix` inside the test *and then* compared to the
      literal, so a matrix that grows fails loudly instead of disagreeing quietly. A second test
      compares the actual set of `resource.action.scope` strings, because writing 143 of the wrong
      rows would satisfy a count.
      **`RolePermissionSeeder` is configuration and runs in production; `UserSeeder` is test data
      and refuses to.** `SEC-07` puts the matrix *in the database*, so `seedsTestData()` is false
      for the first and true for the second — and a test asserts both directions, including that
      the matrix seeder is **not** blocked when the environment is production.
      **Idempotency is asserted by identity, not by counting.** The snapshot compares ids and
      `created_at` across two runs: a seeder that truncated and re-inserted would hold every count
      steady and change every id. That is exactly the injected defect the proof below used, and the
      count assertions all passed while it was in place.
      **`env()` inside a seeder was a real defect, found by checking rather than reasoning.** With
      `config:cache` applied — which production runs, per Point 8.4 — `env('SEED_TEST_USER_PASSWORD')`
      returns **NULL** for a key that is set: measured before and after caching with the value in
      `.env`. `UserSeeder` would have refused a correctly configured password *only in production*.
      It now reads `config('seeding.test_user_password')`, and `config/seeding.php` records why.
      There is still no default and no committed literal — the seeder refuses to run without the
      key, and refuses again if the value would fail `D-28`.
      **A second silent defect: `deleted_at` is not `$fillable`, so Eloquent dropped it.** The
      first version restored an archived canonical role by putting `deleted_at => null` in the
      `updateOrCreate` payload. Eloquent discards a non-fillable attribute **without a word**, so
      the row stayed archived while the seeder reported success. The restore test caught it; the
      seeders now call `restore()` explicitly.
      **`App\Models\User` moved into the module.** `AP-02` gives a module its own persistence, and
      the model now sits in `Identity/Infrastructure/Eloquent` beside `Role`, `Permission` and
      `UserSession`, with `config/auth.php` and the factory's `$model`/`newFactory()` following —
      Eloquent's factory convention resolves against `App\Models` and no longer lands.
      **`deptrac` needed two named exceptions, and both are in the diff rather than in a habit.**
      Identity is now split `IdentityContract` / `IdentityDriver`, exactly as Audit and Storage
      already are: an Eloquent model is a framework object, and a bare `~` ruleset forbids the
      framework outright — **22 violations** said so. A `Factories` layer was added to both configs
      because a model naming its own factory was landing as *uncovered*, and uncovered does not
      fail a build. New baselines: **layers 159 · modules 140**, violations 0, uncovered 0.
      **One older test was rewritten, deliberately.** `SeederContractTest` asserted `db:seed`
      creates **zero** users — true when `DatabaseSeeder` called nothing, and now false by design.
      The property that survived is the one it was really about: every seeded user is one
      `TestPersonas` names, and Laravel's `test@example.com` is absent.
      **Checks:** 719 tests, 3423 assertions. Four deliberate failures, restored under
      `shasum -a 256 -c`: dropping the `own`-scope triples gave `Undefined array key
      "customer.view.own"`; pointing `roles()` at the wrong pivot key gave *"permissions() and
      roles() do not describe the same pivot"*; flipping `seedsTestData()` to false let the seeder
      run in production; and truncate-then-reinsert tripped the identity snapshot while every
      count still passed.
      **Not covered:** **no authentication and no enforcement.** Nothing logs in, nothing checks a
      permission, and the 212 grants are rows no query reads — `SEC-07`'s "enforced at the API and
      row level" is 2.3. `is_hidden` is seeded correctly and hides nobody, because there is no user
      list. No `Scope::includes()` call exists outside its own unit test. The models are persistence
      mappings only: no policy, no repository interface, and no `Application` use case — a later
      point decides whether Identity follows Storage's repository shape or keeps these models as
      the seam. `standardActorForeignKeys()` is still unapplied, so `created_by`/`updated_by`
      remain without foreign keys across every table
- [x] **2.2** Authentication — `login` · `logout` · `me`, lockout after five failures with Super
      Admin notification (`SEC-03`), eight-hour idle expiry (`D-29`). *(2026-08-24)*
      **Credential:** a server-issued **bearer token**, which `OpenAPI §3.1` names as an
      alternative to a cookie session. `AP-07` and `D-67` put one API under the desktop SPA and the
      field PWA, `SEC-05` wants a device list and force-logout (a row, and a soft delete),
      `D-29`'s idle rule then belongs to `IdleTimeout` rather than to a Redis TTL, and `SEC-13`'s
      CSRF surface does not exist for a credential a browser never attaches by itself.
      `user_sessions.session_id` holds the token's **SHA-256 digest**, never the token.
      **Recorded as `D-74`** (owner-approved 2026-08-24, logged in `§2.8`). The trade-off is
      recorded with it: a token the SPA holds is script-reachable where an `HttpOnly` cookie is
      not, bounded by per-device revocation, the eight-hour idle death, and digest-only storage.
      **Layering:** Application may reach neither Eloquent nor Presentation, so the use cases run
      against three Domain contracts — `AccountDirectoryInterface`, `SessionStoreInterface`,
      `ProfileReaderInterface` — bound to Eloquent adapters in `AppServiceProvider`, the pattern
      Storage already uses. `deptrac.modules.yaml` gains **one named crossing**: Identity →
      `AuditContract`, because `AUD-01` and `SEC-16` make the audit write mandatory. Baselines
      moved to **layers 270 / modules 208**, both still 0 violations and 0 uncovered.
      **Two defects the tests caught, both silent:** a refusal thrown from inside
      `DB::transaction()` **rolled back its own `SEC-03` counter and `SEC-16` audit row** — five
      wrong passwords left `failed_login_attempts = 0` and no audit trail, so the account never
      locked; and an **arrow function captured the lockout event by value**, so the account locked
      and the Super Admin was never told. A third, `ForgetResolvedGuards`, is a
      `RequestGuard`-memoisation fix: logout returned `200`, deleted the row, and let the revoked
      token straight back in on the next call.
      **`identity.lockout_minutes = 30` is recorded as `D-75`** (owner-approved 2026-08-24).
      `SEC-03` and §9 Flow 0 stop at "locked"; Point 1.2's `locked_until` column presupposes an
      expiry, and `D-75` supplies it. Two gaps travel with it: **no manual unlock** and **no IP
      block** (`SEC-16`).
      **Not covered:** `SEC-04` (email verification for password changes) and `change-password`;
      `SEC-05`'s device-list and force-logout *screens* — the rows and the revocation exist, the
      endpoints do not; `SEC-10` Login As; `SEC-16`'s IP blacklist; and an unknown email writes no
      audit row at all, because `audit_log.entity_id` is `UUID NOT NULL` and there is no entity —
      that failure lives only in the `AUD-05` log line. **No authorization is enforced yet:** every
      authenticated caller reaches every endpoint, and `SEC-07`/`SEC-09` are 2.3.
- [x] **2.3** Enforcement — a gate reading the matrix from the database (`SEC-07`), a negative test
      per scope, and the Super Admin hidden from every list (`§3.12` rule 6). *(2026-08-24)*
      **The engine:** `AuthorizeAction` asks two questions and no more — is the role §3.1-exempt,
      and what do the grant rows say. `EloquentPermissionRepository` joins `role_permissions` to
      `permissions` for one `resource.action`, skipping soft-deleted rows on both sides (`DB-01`),
      and memoises **within one request only**: §3.12 rule 5 makes a matrix change a configuration
      change, and a cache with any longer life turns that into a wait or a deploy. It never
      consults `PermissionMatrix` — that class is the seed, not the authority, and a test proves
      it by revoking a grant and asserting the very next call refuses.
      **Scopes stay a set, not a winner.** `Scope::includes()` is a partial order — `Own ⊂ Team ⊂
      All`, with `Out` and `Asgn` under `All` but incomparable with `Team` — so
      `PermissionDecision` keeps every scope the role holds and answers `allows(Scope)` against
      all of them. There is deliberately no `widest()`: picking one would invent a comparison §3.2
      does not make, and that is how `Asgn` silently stops working for somebody who also holds
      `Team`.
      **Surfaces:** `permission:resource.action[,scope]` route middleware (`SEC-09`, §3.12 rule 1),
      leaving the decision on a request attribute so `SEC-08`'s row filter does not re-resolve it;
      and `Gate::before` so `$user->can('deal.view')` answers from the database. `before()` returns
      `null` rather than `false` on a denial — `false` is final and would stop a later module's
      policy adding a row-level refusal, while an undefined ability is denied anyway.
      **§3.12 rule 6:** `User::scopeListable()`. A scope to opt into, not a global scope to lift —
      a global one would also hide the account from `SEC-03`'s notification, `SEC-10`'s Login As
      and the audit trail, turning a rule you can forget to apply into one you can forget to lift.
      **Two wrong assumptions this point corrected by looking:** §3.11 Administration **is** "the
      one table with a Super Admin column", so the role legitimately holds nine `admin.*` grant
      rows — the first draft of the test asserted zero. And `defineRoutes()` is an Orchestra
      Testbench hook that Laravel's own `TestCase` never calls, so eight tests answered **404
      instead of 403** and would have passed as "refused" under a laxer assertion.
      **Verified live against the development database**, not only in the suite: Indoor Sales holds
      `customer.view` at `own` and cannot approve, Manager holds `all` and can, CEO holds `all` and
      **cannot** approve (§3.1's observer), Super Admin passes a resource nobody has defined, and
      `listable()` returns 7 of 8 users.
      **Not covered:** **no production route carries `permission:` yet** — the middleware is proven
      on routes the test registers, because Module 1's user/role/permission CRUD is a later point
      and Modules 3+ own the resources. `scopeListable()` likewise guards no listing that exists;
      until `GET /api/v1/users` ships, §3.12 rule 6 rests on convention plus its test. `SEC-08` is
      *decidable* — the reach is resolved and handed downstream — but **no query is row-filtered
      yet**, because there are no business rows. `SEC-10` Login As is untouched

#### Step 3 — password change, user administration, Login As *(breakdown approved 2026-08-24)*

- [x] **3.1** `POST /api/v1/auth/change-password` — current-password challenge, `D-28` policy,
      every session revoked, `PASSWORD_CHANGED` audited. *(2026-08-24)*
      **Three checks, in this order:** the current password first, so a hijacked session cannot
      change the credential it stole — that is the entire reason the field exists; then `D-28`
      through `PasswordPolicy`, the same class the seeder calls, so the rule has one home; then
      "not the same as the current one", asked of the **hash** rather than by comparing two
      submitted strings, because that comparison cannot be fooled by whitespace or by a client
      that normalises one field and not the other.
      **Every session dies, the caller's included.** §9 Flow 0 ends the flow with "**log in
      again**". Revoking only the *other* devices leaves the session an attacker is most likely
      holding — the live one — and a password change that does not evict the person it was meant
      to evict is worse than none, because the user believes it worked. The response says so:
      `sessions_revoked` and `reauthentication_required`.
      **The endpoint takes no target.** No `user_id`, and there will not be one: changing somebody
      else's password is §3.11's `admin.*`, a different action needing a different check. A test
      posts `user_id` and `email` for another account and asserts they are ignored entirely.
      **The audit row carries no credential** — not the old hash, not the new one, not the
      plaintext. `AUD-03` makes it permanent, and a permanent record of a credential outlives the
      account. A test greps the serialised row for all three.
      **Verified live over TLS through nginx**, not only in the suite: wrong current password 422
      with `current_password_incorrect`, digits-only 422, valid change 200 with
      `sessions_revoked: 1`, the old token then 401, the old password 401, the new one 201, and
      one `PASSWORD_CHANGED` row.
      ⚠️ **`SEC-04` is NOT satisfied.** It reads "Mandatory email verification for password
      changes", and §9 Flow 0 spells it out: "Password change → **verification code by email** →
      new password → log in again." Steps one, three and four ship; **step two does not**. A
      current-password challenge is a different control — it proves the caller knows the old
      password, not that they hold the mailbox. `ChangePasswordTest` pins the gap against the
      documentation itself so it cannot be mistaken for done. **This point does not close
      `SEC-04`.** ✅ **Closed by Point 3.3** on 2026-08-25 — the pin is now inverted and asserts
      the requirement is satisfied.
      **Also not covered by 3.1:** no password-reset flow for somebody who has *forgotten* their password
      (`password_reset_tokens` is still a dead scaffold table), no re-use history, no forced
      rotation, and no notification to the user that their password changed
- [x] **3.2** User administration — `GET`/`POST /api/v1/users`, `GET`/`PATCH /api/v1/users/{id}`,
      `PATCH .../deactivate` and `.../reactivate`. *(2026-08-25)*
      **§3.12 rule 6 is finally enforced by something.** `scopeListable()` had no caller until now;
      `EloquentUserDirectory` has exactly one builder factory and every read starts from it, so the
      rule cannot be forgotten per method. The hidden Super Admin is absent from the listing **and
      unreachable by id** — `404 resource_not_found`, never 403, because `OpenAPI §5.1` forbids a
      refusal that confirms the resource exists and confirming it is precisely what rule 6 forbids.
      The cost is stated rather than hidden: **one Super Admin cannot administer another** through
      this API, because rule 6 says "for any role" and names no exception.
      **§3.12 rule 7 guards `PATCH` as well as `POST`.** Moving an account onto a forbidden role is
      creating that account by a different verb. Refused with **422**, not 403 — the caller may
      administer users; the submitted `role_id` is what is unacceptable.
      **`D-34`'s switch revokes every session.** §10.1 says login is "Blocked", and flipping
      `is_active` alone blocks only the *next* login: every bearer token already issued keeps
      working until `D-29`'s eight idle hours expire it, so a dismissed employee keeps their access
      for the rest of the working day — the one moment the switch exists for. Deactivating an
      already-inactive account is idempotent and writes **no** audit row: `AUD-03` keeps rows for
      ever, and one that records a click rather than a change is permanent noise.
      **Audit:** `USER_CREATED`, `USER_UPDATED`, `USER_DEACTIVATED`, `USER_ACTIVATED`, and
      `ROLE_CHANGED` as its **own** event — §3.12 rule 4 makes "role change" mandatory, and an
      auditor filters on the event column, so a role change buried in a generic update's diff is a
      row that query never returns. No credential reaches any row; a test greps for the plaintext,
      `$2y$` and `$argon2`.
      **`is_hidden` is derived from the role, never accepted from the payload** — a flag a client
      could set is a flag a client could clear. Tests post `is_hidden` in both directions and
      assert it is ignored.
      **Two documented rules are read back out of the master documentation** rather than
      transcribed into the test: §3.11's create-user allowlist and §3.12 rule 7's denylist.
      **Verified live over TLS through nginx:** 8 users in the database, 7 listed; a Manager
      creating a Manager → 422 `role_not_assignable`; create → 201 and the new person signs in;
      deactivate → `sessions_revoked: 1`, their live token 401, their login 403 with §10.1's
      message; second deactivate → `changed: false`; Indoor Sales → 403; `per_page=101` → 400
      `invalid_request`; reactivate → 200; role change → 200; and exactly one row of each of the
      five audit events.
      ✅ **`D-78` is RECORDED** — approved by the owner 2026-08-25 and written into
      `docs/CRM_Documentation_EN.md` §2.8 under explicit authorisation; the file is hook-protected,
      so the edit went through a script rather than the blocked tools, disclosed at the time.
      `grep -c "^| D-78 |"` returns 1. **The strict Team Leader restriction is confirmed with it.**
      What the decision settles: §3.11 has
      exactly two user rows, "create user" and "deactivate user", and **no** row for viewing,
      editing or reactivating a user. There is therefore no documented `user.view.*`,
      `user.update.*` or `user.reactivate.*`, and inventing them would add permissions the seeded
      matrix does not hold — every Manager refused while Super Admin passed on unconditional
      access alone, silently deleting §3.11's grant to the Manager. So all six endpoints are
      mapped onto the two documented rows. **The mapping cannot over-grant** — both rows are held
      by exactly the same two roles, so the set of callers is §3.11's whichever row a route names
      — but the labels are a judgement call. One visible consequence: a refused caller reading the
      list is told "the action **admin.create_user** is not permitted", which is confusing.
      ✅ **§3.11 and §3.12 rule 7 disagree about Team Leader; the narrower reading is now the
      owner's confirmed decision (`D-78`, 2026-08-25).**
      §3.11 lists four roles with the word "only"; rule 7 forbids three, which would leave five.
      The allowlist is implemented, so **a Manager cannot create a Team Leader** and must ask the
      Super Admin — who is "the developer, completely hidden". A test pins the gap as exactly Team
      Leader so the day the owner decides otherwise it is one entry in `MANAGER_MAY_CREATE`.
      **Also not covered:** §9 Flow 9's "**credentials emailed**" — the administrator sets the
      initial password and must convey it out of band, no mail is sent; `phone` and `whatsapp`,
      which Flow 9 names and Point 1.2's approved schema has **no columns for**; the free-text `q`
      search, deliberately omitted because `OpenAPI §6.2` routes it through `SearchService`, which
      is Module 3's; `Idempotency-Key` (`OpenAPI §9.1`), for which no infrastructure exists
      anywhere yet — the partial unique index on `email` supplies the practical protection;
      **self-deactivation is not blocked** — a Manager may sign themselves out permanently, which
      nothing in §3.11, §3.12 or §10.1 forbids and only the Super Admin can undo; and no team
      scoping, because `users` has no team column
- [x] **3.3** `SEC-04` — the emailed verification code `3.1` owed. `POST
      /api/v1/auth/change-password/challenge`, and `change-password` now refuses without a
      `verification_code`. *(2026-08-25)*
      **§9 Flow 0 now reads end to end** — "password change → **verification code by email** → new
      password → log in again". 3.1 shipped steps one, three and four and pinned step two as an
      open gap; that pin has been **inverted** rather than deleted, into a test that fails if the
      requirement is ever quietly dropped again.
      **Six digits is only defensible with the four things around it, and each has a test.** 20
      bits is not a secret on its own. It holds because: the store keeps an **Argon2id/bcrypt
      hash**, not a digest — `SessionToken` uses `sha256` and is right to, because 32 CSPRNG bytes
      is 2^256, whereas a six-digit code is a **million** candidates a laptop sweeps through
      SHA-256 in under a second; the challenge dies in **15 minutes**; generation is limited to
      **3 per 15 minutes per account** (`SEC-11`); and **5 wrong codes destroy it**, so the
      million-guess sweep never gets a sixth try. Drop any one and the number stops being enough.
      **The order of the checks is load-bearing.** Current password first, then the code. Reversed,
      a caller guessing passwords would burn the account's own challenge attempts as a side
      effect — a denial-of-service on somebody else's recovery flow. A test asserts a wrong
      password leaves `failed_attempts` at zero and the code still usable.
      **Single use.** The challenge is destroyed inside the same transaction as the password
      write, not left to its TTL: a code that still works after the change it authorised is a
      second change nobody asked for.
      **The refusals are recorded outside the transaction** — Point 2.2 measured what happens
      otherwise, when five wrong passwords left no audit trail because the refusal rolled its own
      row back. `PASSWORD_CHALLENGE_REQUESTED` and `PASSWORD_CHALLENGE_FAILED` (`AUD-01`,
      `SEC-16`'s reasoning); neither row holds the code, its hash, or a six-digit run.
      **Stored in the cache, not in a table.** A fifteen-minute secret fits neither `DB-01`'s soft
      delete nor `DB-02`'s four audit columns — keeping it for ever, which is what soft delete
      means, would preserve a credential hash long after the credential. Redis gives the same
      durability the session layer runs on plus a native TTL, so no sweeper job. The suite runs on
      the `array` driver (`phpunit.xml`), so one test drives the store against **real Redis** on
      the forced `REDIS_CACHE_DB=15` — a store proven only against `array` is exactly the gap this
      project has been bitten by.
      **Verified live over TLS through nginx:** no code → 422; challenge → 202 and a six-digit
      code in the mail log; wrong code → 422 `invalid_verification_code`; correct code → 200 with
      `sessions_revoked: 4`; old password → 401, new → 201; the **same code again** → 422; a
      fourth challenge in the window → **429** with `retry-after: 857`; audit shows 3
      `PASSWORD_CHALLENGE_REQUESTED`, 2 `PASSWORD_CHALLENGE_FAILED`, and **zero** rows containing a
      six-digit run. The development password was restored afterwards and re-confirmed by a 201.
      ⚠️ **Two numbers the documentation does not give.** `SEC-04` says "mandatory email
      verification" and stops; §9 Flow 0 says "verification code by email" and stops. The
      **15 minutes** and the **5 attempts** are the owner's instruction of 2026-08-25, held in
      `config/identity.php` for the reason `D-75` records for `lockout_minutes` — an undocumented
      limit is a setting, and Module 2 moves it into the `settings` table. The **3 per 15 minutes**
      is the same.
      **Also not covered:** the mail is sent **synchronously**, deliberately — the event carries
      the plaintext code, and a queued listener would write it into a job payload in Redis — so a
      dead mail server surfaces as a failed request rather than a code that never arrives, and
      nothing retries it; there is still **no password-reset flow** for somebody who has forgotten
      their password (`password_reset_tokens` remains a dead scaffold table); no re-use history, no
      forced rotation, and no "your password was changed" notice to the user; and the challenge is
      **not** in `BK-01`'s backup set, which is correct but means a Redis flush invalidates every
      outstanding code
- [x] **3.4** `SEC-10` — Login As. `POST /api/v1/auth/impersonate/{user}` and
      `.../impersonate/leave`, with the dual-identity audit trail. *(2026-08-25)*
      **The hard part is not impersonating; it is that the record still names the right person.**
      An impersonation session runs with the target's id, role and grants — that is the feature —
      so every row it writes would otherwise read "the Indoor Sales employee did it". §3.12 rule 4
      makes Login As mandatory to log precisely so the real human is named. Two columns were added
      for it: `user_sessions.impersonator_id` and `audit_log.impersonated_user_id`. `user_id` stays
      the **actor** — during a Login As that is the Super Admin — and the new column holds who they
      acted as. A test performs an ordinary audited action through an impersonation and asserts
      both ids land on the row.
      **`SEC-10` is asked twice, and the second ask is the real one.** The route carries
      `permission:admin.login_as`, which enforces §3.11's matrix row — and §3.12 rule 5 makes the
      matrix **configuration**, so an administrator can grant that row to another role with an
      `INSERT`. `SEC-10` is a sentence they cannot change that way, so `StartImpersonation` asks
      §3.1's `hasUnconditionalAccess()` where no row reaches it. A test grants `admin.login_as` to
      the Manager, watches the request pass the middleware, and asserts it is still refused 403.
      **Four refusals about the target, none of them a technicality.** A **hidden** account is 404
      and indistinguishable from one that does not exist, so Login As cannot enumerate what §3.12
      rule 6 conceals. A **deactivated** account is 422 `business_rule_blocked` — §10.1 blocks it
      from signing in, and becoming it would be the way around the one switch `D-34` provides.
      **Self** is the session the caller already holds. **Nesting** is refused because
      `impersonated_user_id` has one slot and a chain leaves "who was really acting" without a
      single answer.
      **`leave` carries no permission middleware, deliberately.** While impersonating, the
      authenticated user is the target, who holds no `admin.*` grant; requiring one would make the
      impersonation impossible to exit through the API and the only way out would be waiting out
      `D-29`'s eight idle hours. Authorisation is *being in an impersonation session*, which only
      the guard can establish. It is also registered **before** `impersonate/{user}` — measured
      with the two swapped, the answer is **403**, not the 404 that looked obvious, because the
      wildcard route's permission middleware refuses first. A test pins the order.
      **The Super Admin's own session is never touched**, so leaving is a client-side switch back
      to the token it still holds rather than a second login.
      **Verified live over TLS through nginx:** impersonate → 201 as `indoor_sales`; `/me` on the
      new token answers as the employee; `GET /users` → **403** with it and **200** with the Super
      Admin's own token, so §3.1's exemption does not travel; a Manager → 403; an audited action
      through the impersonation left a row whose `user_id` **is** the Super Admin and whose
      `impersonated_user_id` **is** the employee; nesting → 422 `already_impersonating`; leave →
      200, the impersonation token then 401 and the original still 200; audit shows one
      `IMPERSONATION_STARTED` and one `IMPERSONATION_ENDED`.
      **Migration `2026_08_25_000000_add_impersonation_tracking`** round-trips (`DEV-03`): up →
      rollback → up, run in this session. `ALTER TABLE … ADD COLUMN` on the partitioned `audit_log`
      propagates to every partition and the `AUD-03` append-only trigger does not block DDL — both
      checked against this database rather than recalled. `after()` was **removed** from the
      column definition: it is a MySQL hint PostgreSQL ignores, and four schema-shape guards
      caught the appended order.
      **Also not covered:** there is **no time limit on an impersonation** — it lives until the
      Super Admin leaves or `D-29`'s eight idle hours expire it, and nothing in `SEC-10` gives a
      shorter one; **no notification to the impersonated employee** that it happened, which the
      documentation does not ask for and some companies expect; **no §13 screen** listing active
      impersonations (the admin screens are a later module); and the SPA is not built, so the
      "you are impersonating" banner the response body exists to feed has no consumer yet

#### Step 4 — role and permission management *(breakdown approved 2026-08-25)*

- [x] **4.1** §3.11 "create / edit role · permissions" — `GET /api/v1/roles`,
      `GET /api/v1/roles/{role}`, `GET /api/v1/permissions` and
      `PATCH /api/v1/roles/{role}/permissions`. *(2026-08-25)*
      **The endpoint is not the point; §3.12 rule 5 is.** "Permissions live in the database —
      changing this matrix is a configuration change, **not a deployment**." The only way to check
      that sentence is to take a request that was refused, grant the permission through the API,
      re-issue the identical request in the same process, and watch it succeed — then revoke and
      watch it fail again. Both directions are tested, and both were run live over TLS.
      Immediate effect holds because `EloquentPermissionRepository` memoises **within a request and
      nowhere longer**; a cache outliving a request would turn rule 5 into a wait, and one
      outliving a deploy would turn it back into a deploy. `RoleDirectoryInterface` is bound with
      `bind`, not `singleton`, for the same reason.
      **"System role protection" is *not* `is_system`, and reading it that way breaks rule 5.**
      All eight §3.1 roles are seeded `is_system = true`, so a guard on that flag makes the entire
      matrix uneditable and the feature meaningless. Measured: swapping the check for `is_system`
      on purpose failed **11** tests, including both rule-5 tests. What is protected is the
      **Super Admin role alone**, and not out of caution — §3.1's unconditional access is answered
      by `Actor::hasUnconditionalAccess()` *before* any grant row is read, so revoking its grants
      would change 20 rows and change nothing at all. A no-op reporting success is worse than a
      refusal, because the administrator then believes something false about who can do what. A
      test deletes every Super Admin grant directly in the table and shows the account still
      authorised, which is the evidence for the refusal rather than the assertion of it.
      **§3.12 rule 3 is derived from the transcription, not re-listed beside it.**
      `PermissionMatrix::forbiddenKeys()` returns the rows whose grant array is empty — the
      document's merged "❌ Forbidden for every role" cells, which are `customer.delete` and
      `catalog.delete`. A test counts those rows in the mounted master documentation and asserts
      the derived list is the same length, so the two cannot drift. §3.5's "delete (Draft only)"
      is granted to four roles and is explicitly **not** caught.
      ⚠️ **Rule 3 was enforced only by accident until this point.** The seeder writes a
      `permissions` row per *granted* cell, so the two forbidden triples have no row and cannot be
      referenced by id. That is absence, not enforcement. The test inserts one of them by hand and
      then attempts the grant, so it proves the guard fires on the **name**; without that the test
      would pass with the guard deleted.
      **Audit.** `ROLE_PERMISSIONS_UPDATED` (`AUD-01`), written inside the same transaction as the
      grant change (`DB-11`), with the sorted triple list before, the sorted list after, and the
      granted/revoked diff. **Triples, never permission ids** — `AUD-03` makes the row permanent
      and a permanent record built out of primary keys stops being readable the first time a row is
      retired; a test asserts the id is absent and the triple present. A submission identical to
      the stored set writes **no** row and reports `changed: false`, the same reasoning
      `SetUserActivation` applies to an idempotent deactivation.
      **Grants are soft-deleted and restored, never removed** (`DB-01`). `role_permissions` carries
      a *partial* unique index on `(role_id, permission_id) WHERE deleted_at IS NULL` — read off
      the live schema — so re-granting restores the original row instead of inserting a second, and
      `created_at` keeps recording when the grant was **first** made (`DB-02`). A test asserts the
      row id and `created_at` survive a revoke/re-grant cycle.
      **All three endpoints carry `admin.manage_roles`, and that is narrower than it looks.**
      §3.11 gives that row to the Super Admin alone. Guarding the two **reads** with
      `admin.create_user` instead would have let a Manager read the whole authorisation matrix,
      which no row in §3.11 grants — and `D-78` was defensible precisely because it *could not
      widen access*. The same reasoning that permitted `D-78` forbids it here. A test refuses every
      one of the seven non-Super-Admin roles on all three routes, and breaking just the `GET
      /roles` guard back to `admin.create_user` failed two tests.
      **Both listings are paginated** (`OpenAPI §4.2`: "an endpoint must never return an unbounded
      collection"), default 25, maximum 100, `400 invalid_request` on a bad page size, an unknown
      sort or an unknown filter — never a silent ignore (§6.2). A test pins the two shared limits
      against `UserListCriteria`'s so the two resources cannot drift apart.
      **Verified live over TLS through nginx:** `/roles` → 8 roles; `/permissions` → 143 total,
      25 per page; Procurement refused on both (403 `permission_denied` / `unauthorized_action`);
      grant `admin.create_user.all` → `GET /users` as Procurement **403 → 200** with nothing
      restarted; revoke → **200 → 403**; the Super Admin role → 422 `role_is_immutable`; an
      unchanged submission → `changed: false`; and the audit log holds exactly two rows, both
      attributed to `super.admin@example.test`, one granting and one revoking.
      **Found by `AuditEnforcementTest`, and the register was wrong before it ran.** The point was
      first registered as `SyncRolePermissions`; the scanner does not see it, because it calls
      `->transaction(` and that is not a DML verb. The class that actually writes is
      `EloquentRoleDirectory`, now registered with a reason pointing at the use case that owns the
      audit. It is deliberately **not** marked `AUDITED`: that disposition asserts the class names
      the recorder, and a persistence adapter must not.
      **What this does NOT cover.** There is **no create-role, rename-role, delete-role or
      describe-role endpoint** — §3.11's row says "create / edit role · permissions" and only the
      permissions half is built, so §3.12 rule 5's ninth role cannot yet be added through the API.
      There is **no `If-Match` / optimistic locking**: two administrators editing one role's grid
      concurrently means last-write-wins, and `DB-12` names quotations rather than roles, so no
      version column was invented. There is **no bulk or per-cell endpoint** — the whole desired
      set is submitted each time. And a permission **removed from the matrix** stays granted until
      an administrator resubmits the grid; nothing sweeps orphaned grants.
      ❓ **Owner question — the Manager cannot list roles.** §3.11 lets a Manager create users, and
      `POST /api/v1/users` needs a `role_id`, but no endpoint a Manager may call returns one. The
      gap is **pre-existing** (Point 3.2 shipped the create endpoint with no role listing at all)
      and is not widened here; closing it needs either a new §3.11 row — `admin.view_roles` — or a
      narrow assignable-roles endpoint scoped to `admin.create_user` and returning
      `RoleAssignmentPolicy::assignableBy()` rather than the matrix. **Not decided here**
- [x] **4.2** Custom-role CRUD, the Manager's role picker, and system-role immutability.
      *(2026-08-25)* `POST /api/v1/roles` · `PATCH /api/v1/roles/{id}` · `DELETE /api/v1/roles/{id}` ·
      `Application/RoleAdministration/{CreateRole,UpdateRole,ArchiveRole}` ·
      `resources/js/components/roles/RoleFormModal.vue` ·
      `IdentityAuditEvents::{ROLE_CREATED,ROLE_UPDATED,ROLE_ARCHIVED}`.
      **§3.12 rule 5 is now performable end to end.** Rule 5 says a matrix change is "a
      configuration change, not a deployment"; Point 4.1 made that true of an existing role's
      grants, and until this point there was no way to bring the ninth role into existence at all.
      `CustomRoleManagementTest::test_a_role_created_through_the_api_authorises_on_the_next_request`
      is the load-bearing one: create a role over HTTP, grant it `admin.create_user.all`, create a
      user on it, and the endpoint that refused them answers `200` — then revoke and it is `403`
      again, in the same process, with nothing restarted.
      ✅ **The Manager's role picker — the owner question open since Point 3.2 is closed.**
      `GET /api/v1/roles` now carries `permission:admin.create_user`, and `ListRoles` narrows the
      page to `RoleAssignmentPolicy::assignableBy()` for any caller who does not *also* hold
      `admin.manage_roles`. §3.11 gives the Manager "create user … Out.Sup · Out.Sales · Sales ·
      Procurement **only**" and until now that grant could not be exercised, because no endpoint a
      Manager could call returned a `role_id`. The narrowing runs **inside the query**, before the
      count, because `OpenAPI §6.1` requires pagination after authorisation scoping — a `total`
      counting rows the caller may not see is itself a disclosure. Everything else on `/roles`
      keeps `admin.manage_roles`: `GET /permissions`, `GET /roles/{id}`, and all three writes.
      §3.12 rule 1 is untouched — `CreateUser` and `UpdateUser` re-ask rule 7 about whatever
      `role_id` comes back, and a Manager who guesses the Manager role's id is still refused
      `role_not_assignable`.
      **All eight system roles are immutable in identity, not only the Super Admin.**
      `is_editable` (the Super Admin alone) governs *grants* and is unchanged; the new
      `system_role_cannot_be_edited` / `system_role_cannot_be_deleted` govern the *row*, and they
      fire for all eight — `RolePermissionSeeder` rewrites `name` from `Role::label()` and restores
      a trashed row on every run, so a rename or an archive here is a change that quietly undoes
      itself at the next deployment.
      **The slug is not editable, and that is a rule.** `Role::tryFrom()` matches a database row to
      §3.1 **by slug** and `RoleAssignmentPolicy::permitsSlug()` decides rule 7 from it, so renaming
      `procurement` to `buying` would make `tryFrom` answer null, drop `permitsSlug` into its
      Super-Admin-only branch, and silently un-assign a role the Manager could confer yesterday —
      with nothing in the audit log about permissions to explain it. `PATCH` takes the two labels
      and the description; a submitted `slug` is dropped by `validated()` rather than obeyed.
      **A role somebody holds cannot be archived.** `users.role_id` is NOT NULL and §3.1 gives every
      user exactly one role, so archiving an assigned role would leave accounts pointing at a row
      no listing returns and `AuthorizeAction` answering *denied* for everything they try, with
      nothing on screen to explain it. Counted over **live** users only — an archived account
      (`DB-01`) does not hold a role open — and re-asked **inside** the transaction, because a
      concurrent `POST /users` between the check and the write is exactly the state the refusal
      exists to prevent. `D-34`'s deactivation stays the documented way to stand somebody down.
      **The archive is an archive.** `deleted_at` on the role *and* on every `role_permissions` row
      it held — a live grant pointing at an archived role is one no screen shows and no listing can
      revoke, and a restore would bring the role back holding permissions nobody reviewed. No
      `forceDelete()` anywhere; the response says `archived`, not `deleted`.
      ➕ **`roles.name_ar` — a new nullable column** (`2026_08_25_100000_add_arabic_role_label`,
      with a tested `down()` and a partial unique index for the reason the original RBAC migration
      gives). The 2026-08-23 migration's own comment promised a translatable display name and
      shipped only the English half. §3.12 rule 5 changes the shape of that gap: a custom role's
      label is **data** created at runtime, so there is no lang file it could live in and nobody to
      translate it later — the person creating the role is the only one who can supply both.
      `RoleView::label($locale)` and `AdministeredUser::roleLabel($locale)` resolve it **server**-side
      and the payloads carry `label` alongside the raw columns, because the fallback is a rule
      rather than formatting and a client that decided it would be a second implementation.
      ⚠️ **Two deliberate divergences from the brief, both stated rather than absorbed.** (1) The
      archive event is **`ROLE_ARCHIVED`**, not the requested `ROLE_DELETED`: nothing is deleted,
      `AUD-03` makes the string permanent and uncorrectable, and `D-34` already established
      "deactivate"/"archive" as this system's word for exactly this. One line changes it back, and
      it must change **before** any production row carries it. (2) The system-role refusal is
      **`system_role_cannot_be_edited`**, not the requested `role_is_immutable`: that code already
      means a different rule with a different scope (the Super Admin's *grants*), is pinned by
      `RolePermissionManagementTest` and matched by name in `RolesMatrixView.vue`, and one code
      standing for two rules is a code the SPA cannot map to one sentence. Status, error class and
      behaviour are exactly as specified. A third, smaller one: the create endpoint takes
      **`permission_ids`** rather than the brief's "permission triples", because
      `PATCH /roles/{id}/permissions` already established ids as this module's grant vocabulary and
      the SPA holds ids — two vocabularies for one collection is how they drift.
      **Nine deliberate failure proofs**, each restored and verified byte-identical with
      `shasum -a 256 -c` across eight files: dropping the system-role archive guard ("`200` is
      identical to `422`" across all eight roles); dropping the assigned-users guard (same);
      deleting the `ROLE_CREATED` audit call ("actual size 0 matches expected size 1"); making
      `ListRoles` never narrow (the Manager's page grows `manager` and `super_admin`, and offers a
      custom role rule 7 has no entry for); putting `GET /roles` back on `admin.manage_roles`
      ("`403` is identical to `200`" — the closed gap reopening); archiving a role without its
      grants ("1 is identical to 0"); minting a role with `is_system = true` ("true is identical to
      false"); drifting the client slug pattern to `[A-Za-z]` (the mirror test and a vitest case
      both fail); submitting the create form without its shape check (three vitest cases); a
      hard-coded heading (`NoHardCodedTextTest`); and `padding-left` in the modal's stylesheet
      (`LogicalPropertiesTest` — *"takes a physical side"*).
      **What this does NOT cover.** There is still **no `If-Match` / optimistic locking** on roles
      (`DB-12` names quotations, and no version column was invented) and **no restore** for an
      archived role — the row is there and nothing reaches it, so an accidental archive needs a
      database repair. `RoleSlugMirrorTest` pins the client copy of the slug pattern; the modal has
      **no focus trap**, which it shares with every other dialog in this module. ❗ **§3.1's eight
      roles carry `name_ar = NULL` and render English in Arabic**, unchanged from before this
      point: the master documentation names them in English only, and writing Arabic for them here
      would be inventing documentation rather than reading it. `/auth/me`'s `role.name` is **not**
      locale-resolved, because nothing renders it — only its slug is used, for landing routes and
      permission checks. ❗ **`DELETE /roles/{id}` is a capability §3.11 does not name** — that row
      reads "create / edit role · permissions" and says nothing about retiring one. It is the
      owner's Point 4.2 instruction, it archives rather than deletes, and it carries the same
      Super-Admin-only `admin.manage_roles` as the edit routes so it cannot widen who administers
      roles. **It needs a `D-xx` number in §2.8**, which this point did not have authorisation to
      write

**Endpoints**
- [x] `POST /api/v1/auth/login` · `logout` — 2.2 · `change-password` — 3.1, **`SEC-04`'s emailed
      verification code closed by 3.3** · `change-password/challenge` — 3.3
- [x] `GET /api/v1/auth/me` — 2.2. Returns the role and the `SEC-07` triples read from
      `role_permissions`; the list is a menu, not authorization (§3.12 rule 1)
- [x] `POST /api/v1/auth/impersonate/{user}` · `impersonate/leave` — 3.4. `SEC-10`, Super Admin
      only, with the dual-identity audit trail
- [x] CRUD `/api/v1/users` — 3.2. §3.12 rules 6 and 7 enforced; permission names mapped onto
      §3.11's two documented rows per **`D-78`**
- [x] `GET /api/v1/roles` · `roles/{role}` · `/api/v1/permissions` ·
      `PATCH /api/v1/roles/{role}/permissions` — 4.1. §3.11's `admin.manage_roles` on all four;
      §3.12 rule 3 and the Super Admin exception enforced; `ROLE_PERMISSIONS_UPDATED` audited
- [x] `GET /api/v1/auth/sessions` · `DELETE /auth/sessions/{id}` · `DELETE /auth/sessions` — 5.4.
      `SEC-05` for the caller's own account; no permission, because §3.11 has no row for it and
      none of the three takes a target
- [x] `GET /api/v1/users/{id}/sessions` · `DELETE /users/{id}/sessions/{session}` — 5.5. §13
      screen 2's administrative half: `admin.create_user` to read (`D-78`), `admin.deactivate_user`
      to end one, `ADMIN_SESSION_TERMINATED` audited, §3.12 rule 6 answering `404` for the hidden
      account
- [ ] `POST` / `PATCH` / archive on `/api/v1/roles` itself — §3.11's "create role" half, still
      unbuilt, so §3.12 rule 5's ninth role cannot be added through the API yet

#### Step 5 — frontend SPA screens *(breakdown approved 2026-08-25)*

- [x] **5.1** Authentication store, login view, and role-based navigation guards. *(2026-08-25)*
      `resources/js/stores/auth.ts` · `resources/js/router/index.ts` ·
      `resources/js/pages/auth/LoginView.vue` · `resources/js/pages/ForbiddenView.vue`.
      **None of this is authorization, and saying so is the point.** §3.12 rule 1 and `SEC-09`:
      "Enforcement happens at the API — hiding a button is not the same as blocking an action."
      A guard decides which screen renders; a person who edits the route table in a debugger
      reaches a component that immediately asks `/api/v1` and is refused there. The tests say this
      in their own headers so that nobody later reads a green guard suite as an access-control
      proof.
      ⚠️ **The landing table follows §8, not the point's brief.** The brief proposed `/deals` for
      Manager and Team Leader and `/requests` for both Sales roles. §8 opens Manager and Team
      Leader with *Dashboard*, Outdoor Supervisor and Outdoor Sales with *Today's Visits*, Indoor
      Sales with *Customers*, Procurement with *Assigned Deals*, CEO with *Dashboard*, and Super
      Admin with §13's administrative screens. `CLAUDE.md` puts the master documentation above the
      brief, so `LANDING_ROUTE` transcribes §8 — and `RoleLandingTest` reads §8 back out of the
      mounted documentation rather than trusting the transcription. Pasting the brief's table in
      was tried on purpose and the test failed with "§8 opens Manager with 'Dashboard'".
      **None of those eight screens exists yet** — Dashboard is Module 14, Customers Module 3,
      Deals Module 5, Visits Module 12, §13 later still. `landingRouteFor()` resolves an
      unregistered target to `home` rather than redirecting into a blank page, which is the defect
      `navigation.ts` already names: "a dead link is not a permission problem, it is a lie." A
      third test asserts the gap directly, so it fails — and gets updated — in the same commit that
      registers each module's route.
      **`D-74`, read correctly.** The brief called the stored value a "SHA-256 Bearer"; the SHA-256
      in `D-74` is what the **server** keeps in `user_sessions.session_id`. The client holds the
      opaque 64-hex token and hashes nothing — a client that hashed it would present a credential
      the server has never seen. A test asserts the outgoing header is `Bearer <the same 64 hex>`.
      **`localStorage`, with `D-74`'s trade-off already recorded** — "a token the SPA must hold is
      reachable by script in a way an HttpOnly cookie is not". `sessionStorage` does not change
      that (script reads both); it only makes a second tab a second sign-in, against `D-29`'s
      eight-hour day. What bounds the damage is what that decision names: per-device revocation
      (`SEC-05`), the idle expiry, and a database holding only the digest.
      **A 401 clears the session — except on the login request itself.** `D-29`'s expiry,
      `SEC-05`'s revocation and §10.1's mid-shift deactivation all arrive as a 401, and the
      transport clears state and replaces the route with `/login`. But a wrong password is a 401
      too, and treating it as an expired session would clear state the person never had and bounce
      them to the page they are already looking at. The handler runs only when a credential was
      actually attached; both branches are tested.
      **Three refusals, three sentences** (§10.1, `SEC-03`, `OpenAPI §5.1`). §10.1's wording is an
      acceptance criterion, so the test asserts it character for character — "Account suspended,
      please contact administration." A locked account (**423**) is a state retyping cannot fix and
      says so; a network failure says the server could not be reached rather than blaming the
      password. `D-28` is **not** re-implemented client-side (`D-67`): the form only checks that
      two boxes are not empty.
      **`SEC-01` is stated on the screen, not left as an absence.** No sign-up link, no reset link
      (§9 Flow 0's reset is the authenticated emailed code from Point 3.3), and a test asserts the
      page renders **zero** anchors.
      **The open redirect was closed before it shipped.** The guard leaves `?redirect=` behind;
      `safeRedirect()` accepts only a same-origin absolute path and rejects `//evil.test`, which a
      browser resolves to another host. Dropping the second character from that check failed the
      test.
      **`vitest` was added, and wired into CI in the same commit.** Point 5.1 is the first branching
      logic in the SPA, and `vue-tsc` proves types while saying nothing about behaviour (`DEV-07`).
      48 unit tests across the store, the guards and the login component; the CI step now runs
      `npm run test:unit` between `npm ci` and `npm run build`, because a check CI does not run is
      a check nobody reads. `vitest@3` was replaced with `@4` after `@3` shipped Vite 6 types that
      cannot be reconciled with the project's Vite 8 under `exactOptionalPropertyTypes` — measured,
      not guessed.
      **Verified against the running server:** `/login` returns **200** through nginx, and the
      server-side first paint is `<html lang="ar" dir="rtl">` for Arabic, `lang="en" dir="ltr"` for
      English, and Arabic for an unsupported locale (§1 is Arabic-first). The built bundle carries
      both languages' strings and the storage key.
      **What this does NOT cover.** There is **no idle-timeout timer in the client** — `D-29` is
      enforced server-side and the SPA learns about it only when the next request 401s, so a person
      who leaves a tab open sees a working-looking screen until they touch it. There is **no
      real 404 view**: an unknown path redirects to the landing chain, which is better than a blank
      page and is not the same as saying the record does not exist. **No impersonation banner** —
      Point 3.4's response body feeds one and this point does not draw it, so a Super Admin inside
      a Login As still has no visual signal. **No active-device screen** (`SEC-05`'s list), **no
      password-change screen** (Points 3.1/3.3's endpoints have no UI), and **no user or role
      management screens** — those are the remaining Step 5 points. And the guards are **not** an
      access-control boundary; the API is.
      ❓ **Owner question — the landing table.** §8 is followed here. If the brief's `/deals` and
      `/requests` were a deliberate product change rather than a paraphrase, it needs to be
      recorded as a decision and §8 amended; until then the router follows the document

- [x] **5.2** Employees screen, user form, status actions, and the impersonation banner.
      *(2026-08-25)* `resources/js/pages/users/UsersView.vue` ·
      `components/users/UserFormModal.vue` · `components/users/ConfirmDialog.vue` ·
      `components/identity/ImpersonationBanner.vue` · `services/identity.ts` ·
      `domain/roleAssignment.ts`.
      **The banner is the most important part of Point 3.4, delivered a point late.** An
      impersonation session runs with the target's id, role and permissions — that *is* the
      feature — so every screen looks exactly as it does for that employee. The audit trail records
      the truth either way (`impersonated_user_id`), and this is what keeps the Super Admin from
      being the last to know. It is deliberately **not dismissible**: a banner with a close button
      is a banner that is closed, and the hazard lasts until `D-29`'s eight idle hours do. It reads
      from `localStorage`, so a page reload mid-impersonation still draws it — without that, a
      refresh loses both the warning and the parked Super Admin token and the only way out is
      waiting the session out. Leaving sends the request on the **impersonation** token, because
      that is the session the endpoint revokes, and only then restores the parked one; local state
      is restored even when the call fails, for the same reason `logout()` does.
      **§3.12 rule 6 needed nothing from this screen, and that is the design.** `scopeListable()`
      filters inside `EloquentUserDirectory` and `UserPayload` sends no `is_hidden` at all — a
      field telling a client which account to omit is a field telling it the account exists.
      Verified live: `GET /users` returned **8 rows, 8 total, super_admin absent, no `is_hidden`
      anywhere in the payload**.
      **The role dropdown mirrors §3.11, and the mirror is pinned.** `assignableBy()` offers a
      Manager exactly *Out.Sup · Out.Sales · Sales · Procurement* — `D-78`'s reading, so a Manager
      may not create a Team Leader — and `RoleAssignmentMirrorTest` reads both
      `RoleAssignmentPolicy`'s constants and the TypeScript back and asserts they are identical.
      Re-reading rule 7 as a denylist on purpose failed **9** tests; drifting the mirror failed the
      PHP guard. The filter runs against **what the server returned**, not a hard-coded eight, so
      a ninth role §3.12 rule 5 adds is handled.
      **`D-28` is mirrored in the form and is not the rule.** `PasswordPolicy` decides and
      `CreateUser` refuses regardless (`D-67`); the client check exists so nobody is told after a
      round trip. A `422` is rendered on the field its `details[].code` names (`OpenAPI §5.1`),
      not in a banner that says "something was wrong". **Editing has no password field**: §9 Flow 0
      gives that to the account holder behind `SEC-04`'s emailed code, and an administrator
      resetting somebody's password is a different documented action that does not exist yet.
      **`D-34` asks before it acts.** The confirmation names what deactivation does — every device
      signed out (`SEC-05`), customers and deals left where they are, the account never deleted
      (§10.1) — and the list is reloaded rather than patched in place, because the row is not the
      only thing that changed.
      **The sidebar now filters by permission** (§5.1, `SEC-09`), and `navigation.spec.ts` asserts
      every item's `permission` is exactly its route's `meta.requiredPermission` — a link stricter
      than its route hides a reachable screen, a link looser than its route sends the person to the
      denial screen, and either way the menu and the guard describe different products.
      **Verified live over TLS:** `/users` serves **200** with `lang="ar" dir="rtl"` and
      `lang="en" dir="ltr"`; `GET /users` and `GET /roles` return what the screen consumes;
      `POST /auth/impersonate/{id}` answers `{token, impersonating{id,name,role}, impersonator_id}`
      — and **no `token_type`**, which the TypeScript interface had copied from the login shape and
      was corrected against the running server.
      **Found by the tests, fixed in this point:** the bearer-token provider was installed only by
      `installAuthTransport()` in `app.ts`, so any other entry point signed in and then sent no
      `Authorization` header at all. A component test caught the impersonation refetch going out
      bare. The store now registers it at module load — a two-step wiring whose first step is
      required for correctness is a step somebody will forget.
      **`NoHardCodedTextTest`'s tag stripper was wrong**, and on real markup: `<[^>]*>` stops at
      the first `>` **inside an attribute value**, so `v-if="pagination.total_pages > 1"` left the
      rest of the tag standing and the scanner reported `class="…"` as prose. The alternation now
      consumes quoted runs whole.
      **What this does NOT cover.** There is **no text search**: `UserListCriteria` declares
      `is_active` and `role` and deliberately omits free text, because `OpenAPI §6.2` routes `q`
      through `SearchService`, which is **Module 3**. A box that sent `?q=` would be silently
      ignored by the parser — a search that does nothing is worse than none. There is **no sort
      control** (the API supports `name`, `email`, `created_at`); **no bulk actions**; **no user
      detail page** — the row is the whole record here; **no active-device list** (`SEC-05`'s
      force-logout per device); and **no `SEC-04` password-change screen**. The guards and the
      hidden buttons are **not** an access-control boundary; the API is.
      ❗ **The Manager cannot create a user through this screen, and that is a real gap, not a
      styling one.** §3.11 gives `admin.manage_roles` to the Super Admin alone, so a Manager's
      `GET /roles` is `403` and there is no `role_id` for the form to submit. The screen says so
      and disables Create rather than offering a form that cannot be sent. This is the **same owner
      question raised with Point 4.1** and still unanswered: it needs either a new §3.11 row
      (`admin.view_roles`) or a narrow assignable-roles endpoint scoped to `admin.create_user`
      returning `RoleAssignmentPolicy::assignableBy()`. **Adding that endpoint was out of this
      point's scope** — the brief said Identity UI — so it was not built

- [x] **5.3** Roles and permissions matrix screen, scope toggles and the diff confirmation.
      *(2026-08-25)* `resources/js/pages/roles/RolesMatrixView.vue` ·
      `components/roles/PermissionDiffModal.vue` · `domain/permissionMatrix.ts` ·
      `services/identity.ts` · `RolePayload::permissions()` · `PermissionView::isGrantable()`.
      **One role at a time, with §3.2's five scopes as the columns.** The brief allowed
      role-per-column; the cells are not booleans. §3.2 makes a permission a
      `resource.action.scope` triple, so a role holding `customer.view.team` and one holding
      `customer.view.all` are two different grants of the same row, and a role-per-column grid
      would have to collapse the scope into the cell and stop being readable at the first row
      where two roles differ by scope. Rows are `resource.action`, grouped into §3.3–§3.11's
      **nine** sections (measured: `admin · catalog · customer · deal · procurement · quotation ·
      report · supplier_quotation · visit`).
      **The grid never draws a triple it invented.** A cell is a checkbox only where
      `GET /permissions` returned a row for that key *and* that scope; everywhere else it prints
      §3.2's own `—`. The client cannot mint a permission id, so an enabled box on a scope with no
      row would be offering a `422 permission_not_found`.
      ❗ **`GET /permissions` cannot return the matrix in one request, and that is measured, not
      defensive.** `ReferenceListCriteria::MAX_PER_PAGE` is **100** and a larger `per_page` is a
      `400`, not a clamp; the seeded matrix holds **143** rows. Live over TLS: page 1 returned
      **100 rows**, page 2 returned **43**, `total 143 · total_pages 2`. `PATCH` takes the full
      desired set, so a screen that read one page would have drawn 100 permissions and silently
      **revoked every grant in the missing 43** on its first save. `listAllPermissions()` follows
      `has_next_page`, bounded at 50 pages.
      **§3.12 rule 3 is now sent, not re-typed.** The permission payload carries
      `is_grantable`, derived from `PermissionMatrix::forbiddenKeys()` — the same question
      `SyncRolePermissions` asks to refuse the grant, which was two copies of one `in_array` for
      exactly one point. ⚠️ **All 143 seeded rows report `true`**, because a rule 3 cell has no
      `permissions` row at all; the flag is what stops that from being load-bearing when a later
      module adds `customer.delete.all`. Both halves are tested: every row grantable, *and* a
      hand-inserted forbidden row reported `false` — the second is the one that fails when the
      derivation is replaced by a literal `true`.
      **Two locks, two different rules.** `is_editable === false` is §3.1's Super Admin, and it is
      **not** `is_system`: live, all eight roles are `is_system=true` and exactly one is
      `is_editable=false`. A screen reading `is_system` would freeze the whole matrix and delete
      §3.12 rule 5.
      **The diff modal exists because the request is a set, not a delta.** An unchecked box halfway
      down the grid and a box never checked are identical to the endpoint, so the one thing the
      grid cannot show is what changed. The listed triples are sorted the way `RoleView::triples()`
      sorts them, so the confirmation and the `AUD-02` old/new values read against each other.
      **Verified live over TLS** (Super Admin session minted through `SessionStoreInterface::open()`
      and revoked after): `/roles` serves **200** with `lang="ar" dir="rtl"` and `lang="en"
      dir="ltr"`; the served bundle carries `الأدوار والصلاحيات`, `غير قابل للتعديل`,
      `is_grantable`, `permission_ids`, `grant_forbidden` and `has_next_page`. §3.12 rule 5 round
      trip on the CEO role: grant → `200 {granted:["admin.create_user.all"]}`, revert → `200
      {revoked:[…]}`, and the role came back to its original **13** grants exactly. Super Admin →
      `422 business_rule_blocked / role_is_immutable`. An unchanged submission → `200` with
      `changed: false`, so `AUD-03` gets no row recording a click. ⚠️ That round trip wrote **two
      permanent audit rows** in the development database; `AUD-03` means they cannot be removed.
      **What this does NOT cover.** §13 screen 3 also says *"create new roles"* — **there is no
      create-role endpoint**; Point 4.1 shipped index, show, permissions and the sync, and nothing
      else. The screen therefore edits the eight seeded roles and cannot add a ninth, which is a
      capability §3.12 rule 5 explicitly contemplates. There is **no rename, no description edit
      and no role delete**; **no per-role search or filter** across 55 permission keys — long
      resources are scrolled; **no keyboard shortcut** for bulk grant/revoke, and **no
      select-all-in-row**; **no focus trap** in either modal (`ConfirmDialog` shares the gap — the
      dialogs move focus in and close on Escape, but Tab still reaches the page behind them);
      **no optimistic-lock header** — two administrators saving the same role concurrently is
      last-write-wins, because `DB-12`'s `If-Match` is scoped to quotations and `roles` carries no
      version column. The grid and the locked checkboxes are **not** an access-control boundary;
      §3.12 rule 1 puts that at the API, which `RolePermissionManagementTest` proves separately

- [x] **5.4** Account security screen, `SEC-04`'s email challenge and `SEC-05`'s device
      management. *(2026-08-25)* `resources/js/pages/profile/AccountSecurityView.vue` ·
      `components/profile/EmailChallengeModal.vue` · `domain/passwordPolicy.ts` ·
      `SessionController` · `SessionPayload` · `Application/SessionManagement/*` ·
      `DeviceSession` · `SessionRefusal` · `SessionStoreInterface::devicesFor/revokeDevice/revokeOtherDevices`.
      **Three endpoints, no permission on any of them, and that is §3.11 read rather than
      skipped.** The section has no row for managing your own password or your own devices; the
      account is taken from the bearer token and none of the three takes a target, so there is
      nothing an authorisation check could narrow. Somebody *else's* devices are §13 screen 2,
      which has its own permission and is not this screen. Naming an `admin.*` ability here would
      hide a security screen from every employee; inventing a `user.*` one would invent a
      permission the seeded matrix does not contain.
      ❗ **A Login As session is not one of the account's devices.** §3.1 makes the Super Admin
      "completely hidden from all users", so `devicesFor()`, `revokeDevice()` and
      `revokeOtherDevices()` all filter `impersonator_id IS NULL` through one private scope —
      hidden from the list *and* from the command, or the list would only be hiding the id from
      somebody who has not tried guessing it. `SEC-10`'s mandatory audit is the control on Login
      As; a device list is not. ⚠️ **The cost, stated:** a live impersonation is a session the
      account owner can neither see nor end.
      **Revoking the calling session is refused, not half-done.** `422 business_rule_blocked /
      session_is_current`, pointing at `POST /auth/logout` — which writes the `LOGOUT` event and
      lets the SPA drop the token. A `200` here would kill the credential the client keeps using.
      **`DELETE`, where `D-34` chose `PATCH`, and the difference is `DB-01`.** §7.2 names `POST`
      and `PATCH` and is silent on `DELETE`; a user account is business data that may never be
      deleted, so deactivation is a state change. A session is not business data, its lifecycle is
      create-and-destroy, and the row is soft-deleted underneath exactly as every other revocation
      in this module — no `user_sessions` row is ever physically removed.
      **`SEC-04` is a two-step flow because §9 Flow 0 is.** The form collects the three passwords,
      `POST /auth/change-password/challenge` mails the code (no body, no target — a password sent
      to a mail-sending endpoint would be a credential in a request that exists to send mail), and
      the dialog collects six digits. The countdown is the server's `expires_in_minutes`, not a
      hard-coded fifteen: `AP-08` and §3.12 rule 5 make limits configuration.
      **`D-28`'s checklist is a hint, pinned to the rule.** `passwordPolicy.ts` mirrors
      `PasswordPolicy::MINIMUM_LENGTH` and `VerificationCode::LENGTH`, and
      `PasswordPolicyMirrorTest` reads both — including that the letter test is `\p{L}` with `/u`,
      because a Latin-only class would refuse an Arabic passphrase the API accepts, in the
      first-release language, on a security screen.
      **The change signs this device out, and the screen says so first.** `ChangePassword` revokes
      every session including the caller's, so the store gains `forgetSession()` — dropping local
      state without posting a revoked token to `/auth/logout` and reaching the same place through
      a `401`.
      **Verified live over TLS** (two sessions minted through `SessionStoreInterface::open()`, all
      revoked afterwards): `GET /auth/sessions` → `200`, four rows, exactly one `is_current`, the
      six `OpenAPI §4.2` pagination keys, and **no `session_id` and no SHA-256 digest anywhere in
      the body**; `DELETE` own session → `422 session_is_current`; `DELETE` a remote one → `200`,
      after which that token answers `401` and the caller's still answers `200`; `DELETE
      /auth/sessions` → `200 {revoked: 2, current_session_kept: true}`; `per_page=500` → `400
      above_maximum`; `filter[user_id]` → `400 unknown_filter`; an already-revoked id → `404
      session_not_found` (Arabic: «هذا الجهاز غير مسجَّل الدخول إلى حسابك.»); unauthenticated →
      `401` on all three. `/account/security` serves `200` with `lang="ar" dir="rtl"` and
      `lang="en" dir="ltr"`, and the served bundle carries `أمان الحساب`, `تسجيل الخروج من كافة
      الأجهزة الأخرى`, `/auth/sessions`, `new_password_confirmation` and `session_is_current`.
      ⚠️ Those probes wrote **three permanent `SESSION_REVOKED` audit rows** in the development
      database; `AUD-03` means they cannot be removed.
      **What this does NOT cover.** The `User-Agent` is shown **raw** — no "Chrome on Windows"
      parsing, because that is a dependency and a lookup table that goes stale; §13 screen 2 asks
      for "browser" without saying who reads it. There is **no geolocation** and no "new device"
      notification. `SEC-05`'s eight-hour expiry is enforced server-side by `IdleTimeout` and is
      **not** shown as a per-row countdown, and there is still **no client-side idle timer** for
      `D-29` — a tab left open discovers the expiry on its next request. **No focus trap** in
      either dialog (`PermissionDiffModal` and `ConfirmDialog` share the gap). **No rate-limit
      countdown**: `SEC-11`'s three-per-fifteen-minutes is reported when the `429` arrives and not
      predicted, because a client-side counter and the server's limiter disagreeing is a button
      that lies in both directions. Nothing here is an access-control boundary — §3.12 rule 1 puts
      that at the API, which `SessionManagementTest` proves separately with 27 tests

- [x] **5.5** Employee details drawer, administrative device inspection and force logout.
      *(2026-08-25)* `resources/js/components/users/UserDetailsDrawer.vue` ·
      `UserController::sessions/terminateSession` ·
      `Application/Administration/{ListUserSessions,TerminateUserSession}` ·
      `IdentityAuditEvents::ADMIN_SESSION_TERMINATED`.
      **Two permissions, and both are §3.11's own rows rather than new ones.** The read carries
      `admin.create_user` — `D-78`'s mapping, the same row `GET /users/{id}` already names, because
      §3.11 has no "view user" row. The termination carries `admin.deactivate_user`, which is the
      narrower reading and not the convenient one: that row is the authority that already ends
      **every** session an account holds (`D-34`), so ending one of them is strictly less than it
      permits. Neither can over-grant — §3.11 gives both rows to exactly the same two roles, which
      is the argument that made `D-78` defensible.
      ❗ **The target is resolved through `UserDirectoryInterface::find()`, not through the session
      store.** The store is keyed on `user_id` and knows nothing about who may be listed, so
      reading it directly would have handed an administrator who guessed the hidden id the one
      account §3.12 rule 6 exists to conceal — with its addresses and its browsers. Live: a
      Manager asking for the hidden Super Admin's devices gets `404 user_not_found`, the same
      answer `GET /users/{id}` gives, and the account stays signed in.
      **A Login As session is still not a device**, in the administrative list as well: `SEC-10`'s
      session belongs to the administrator running it, and the Manager reading this screen is one
      of the "all users" §3.1 hides them from. Hidden from the list *and* from the command.
      **The drawer re-reads instead of trusting the row it was opened from.** It takes a user id,
      not the list's copy: that copy is a page that may be minutes old, and a force logout aimed at
      a session that has already gone is a `404` the administrator cannot explain.
      **`ADMIN_SESSION_TERMINATED` is its own event**, distinct from `SESSION_REVOKED` (the owner
      signing their own device out) and from `USER_DEACTIVATED` (`D-34` taking every session down
      at once). An auditor asking "who was forcibly logged out, by whom" needs a name to filter on;
      `AUD-02`'s actor is the administrator and the entity is the account. Never the fingerprint.
      ⚠️ **A wording defect the live probe caught and the tests did not.**
      `identity.session.session_not_found` read "That device is not signed in **to your
      account**", which is right on `SEC-05`'s own screen and names the wrong person on this one.
      Both wordings are grammatical, so no assertion could see it. Reworded to "That device is not
      signed in" in both languages.
      **Verified live over TLS** (four sessions minted through `SessionStoreInterface::open()`, all
      revoked afterwards): Manager reads the target's two devices, six pagination keys, **no
      `session_id` and no digest in the body**; hidden Super Admin → `404 user_not_found`;
      Procurement → `403 permission_denied` on both routes; `DELETE` the target's phone → `200`,
      that token then `401` while the target's other token still answers `200`; the Manager's own
      current session → `422 session_is_current`; a third party's session id under the target's
      path → `404 session_not_found` and that person stays signed in; `per_page=500` → `400
      above_maximum`; unauthenticated → `401`. `/users` serves `200` with `lang="ar" dir="rtl"` and
      `lang="en" dir="ltr"`, and the bundle carries `تفاصيل الموظّف`, `تسجيل خروج هذا الجهاز`,
      `user-details-drawer` and `admin.deactivate_user`. ⚠️ The probe wrote **one permanent
      `ADMIN_SESSION_TERMINATED` audit row** in the development database; `AUD-03` means it cannot
      be removed.
      **What this does NOT cover.** ❗ **"Last login" is shown as "last activity", derived from the
      newest live session.** `users` has no `last_login_at` column and the honest source is
      `audit_log`'s `LOGIN_SUCCEEDED`, which Identity may not read — `deptrac.modules.yaml` allows
      Identity → AuditContract and that contract is a recorder with no reader. A person signed in
      nowhere shows no time at all rather than a fabricated one. Closing it needs either a column
      or an audit-read interface, and both are decisions rather than edits. There is **no
      "sign out every device" for a target** — `D-34`'s deactivation is the only bulk revocation,
      and adding a second one is a new endpoint; **no reset-password** control (§13 screen 2 lists
      it and no endpoint exists); **no geolocation** and no user-agent parsing; **no focus trap**
      in the drawer, which shares the gap with every other dialog in this module. The drawer is
      **not** an access-control boundary — §3.12 rule 1 puts that at the API, which
      `AdminSessionInspectionTest` proves separately with 20 tests

**Frontend**
- [x] login page · role-based redirect · protected routes — 5.1
- [x] roles and permissions matrix · scope toggles · diff confirmation modal — 5.3
- [x] user management screen · create/edit modal · deactivate/reactivate · Login As ·
      impersonation banner — 5.2
- [x] password change screen (`SEC-04`) · active devices (`SEC-05`) — 5.4
- [x] user detail drawer · administrative device list · per-device force logout — 5.5
- [x] create-role form · archive with confirmation · the Manager's role picker — 4.2
- [ ] text search (blocked on Module 3's `SearchService`) — **carried to Module 3 by design**

**Acceptance criteria**
- [x] Valid credentials → redirect to the role's default screen *(5.1 — the map is §8's, read back out of the documentation by `RoleLandingTest`; every target falls back to `home` until its module registers a route)*
- [x] 5 failed attempts → account locks and an email is sent *(2.2 — `LockoutPolicy::MAX_ATTEMPTS = 5`, `ACCOUNT_LOCKED` audited and `AccountLockedNotification` queued to the Super Admin; `AuthenticationTest` proves four failures do **not** lock, five do, and a locked account is refused even with the right password. `D-75` sets the 30-minute duration the documentation does not give)*
- [x] Session idle 8 hours → automatic logout *(2.2 — `IdleTimeout::HOURS = 8`, measured against `last_activity_at` by `BearerSessionResolver`, which deletes the row rather than leaving it to be re-checked; `AuthenticationTest` proves an eight-hour-idle session stops working and that activity pushes the clock forward.* ⚠️ **Server-side only:** an idle tab discovers the expiry on its next request — the `401` handler then clears the session and returns to the login screen. There is no client-side countdown, which is a standing gap on the debt register, not a hole in `D-29`.)
- [x] Permission removed from a role → direct API call returns **403** *(4.1 — proved in both directions, through the API and live over TLS: grant → 200, revoke → 403, nothing restarted)*
- [x] Password under 8 characters or digits only → rejected with a clear message *(3.1 and 3.2 — `PasswordPolicy` is asked by the change-password use case, by `CreateUser` behind the Form Request, and by the seeder; `ChangePasswordTest` covers seven characters, letters only, digits only and empty, and asserts the refusal exists in **both** languages. 5.4 adds the three-condition checklist in the SPA, mirrored to the same constant and pinned by `PasswordPolicyMirrorTest` — including the `\p{L}` letter class, so an Arabic passphrase is not refused by the client the API would accept)*
- [x] Deactivated employee → "Account suspended, please contact administration" *(2.2 at the API; 5.1 renders §10.1's sentence character for character, in both languages)*
- [x] Super Admin is hidden from every user list, for every role *(3.2 at the API; 5.2 confirmed live — `GET /users` returned 8 rows with `super_admin` absent and no `is_hidden` field in the payload at all)*
- [x] Manager cannot create Manager, CEO, or Super Admin accounts *(3.2 enforces it; 5.2's dropdown mirrors §3.11 and `RoleAssignmentMirrorTest` pins the two lists equal — and per `D-78` a Manager may not create a **Team Leader** either)*
- [x] Login As is Super Admin only and always writes an audit entry *(3.4 at the API, asked twice; 5.2 draws the button for the Super Admin alone and the banner names who is being impersonated)*

### Module 1 sign-off — **granted 2026-08-25**

Every step is complete, all nine acceptance criteria are ticked, and every gate is green:
**1116 PHP tests (7996 assertions)**, **234 vitest across 16 files**, Pint clean across 275 files, PHPStan level 10
clean, deptrac `Violations 0 · Uncovered 0` on both rulesets, `vue-tsc` clean and a successful
production build.

**The item that blocked the previous attempt is closed.** On 2026-08-25 the sign-off was refused
because `POST` / `PATCH` / archive on `/api/v1/roles` was unbuilt — §3.11's row reads "create /
**edit** role · permissions" and §13 screen 3 says "create new roles", and Point 4.1 had shipped
only the read and the permissions sync, so §3.12 rule 5's ninth role could not be added through
the API. Point 4.2 builds all three, and the rule-5 round trip is now proved end to end rather
than argued: a role created over HTTP authorises the very next request and stops authorising the
moment its grant is revoked.

**The Manager's role picker is closed too** — the owner question raised at Points 3.2, 4.1, 5.2 and
5.3. `GET /roles` carries `admin.create_user` and narrows to `RoleAssignmentPolicy::assignableBy()`,
so §3.11's create-user grant is finally exercisable by the role the document gives it to.

**What is carried forward, deliberately and by name:**

1. **Text search over the employee list** belongs to Module 3. `OpenAPI §6.2` routes `q` through
   `SearchService` and `CLAUDE.md`'s delivery order builds that service in Module 3. It is
   scaffolding for a later module, not an unfinished part of this one, and it stays unticked so it
   cannot be forgotten.
2. **`DELETE /roles/{id}` needs a `D-xx` number.** §3.11 names "create / edit"; archiving a role is
   the owner's Point 4.2 instruction and is not in the matrix. The endpoint archives rather than
   deletes and carries the same Super-Admin-only permission as the edits, so it cannot widen
   access — but the decision belongs in §2.8 and this point had no authorisation to write there.
3. **`ROLE_ARCHIVED` diverges from the brief's `ROLE_DELETED`**, for the `AUD-03` reason recorded
   with Point 4.2. It must be settled before any production row carries either string.
4. **§3.1's eight roles have no Arabic label.** `roles.name_ar` exists and is null for all of them,
   so the Arabic screens show English names — unchanged from before this module, and not
   inventable from the documentation.
5. The standing module-wide gaps: **no focus trap** in any dialog, **no client-side idle countdown**
   for `D-29`, **no optimistic locking** on roles, **no restore** for an archived role, and **no
   reset-password endpoint** for the control §13 screen 2 lists.

**Owner questions still unanswered:** §8's landing screens disagree with the brief's `/deals` and
`/requests` (raised at 5.1); §13 screen 2 lists a **reset password** control for which no endpoint
exists (raised at 5.5). Neither blocks this module — the first is a routing table that falls back
to `home`, the second is a control that is not drawn.

🚀 **First deployment point — deploy to the real server here, not at the end.**
