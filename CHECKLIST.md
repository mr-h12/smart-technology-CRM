# MVP Build Checklist

Derived from [`docs/MVP_Build_Plan_EN.md`](docs/MVP_Build_Plan_EN.md). Every acceptance criterion
below is quoted from that plan or from the decision it implements — this file tracks progress, it
does not create requirements. If a box here disagrees with the build plan, the build plan wins.

**Legend** — `[ ]` not started · `[~]` in progress · `[x]` done and verified

---

## Phase 0 — Week Zero (before any code)

### Mandatory prototypes

- [x] **P-01 — Arabic PDF** · risk `R-02`
  - [x] Full Arabic paragraph renders with correct shaping, no broken glyphs
  - [x] Item table renders correctly
  - [x] Numbers render correctly and are not reversed in RTL
  - [x] Fonts are embedded, not referenced from the OS
  - [x] Verified visually, not merely produced
- [ ] **P-02 — Deploy to the real server** · ⏸ **deferred under `D-66`**, not cancelled
  - [ ] Hello page runs on the on-premise server
  - [ ] Page opens from a phone over Cloudflare Tunnel + Access (D-59)
  - [ ] Fonts render correctly on the Linux server (not just macOS)

> P-01 has passed. `P-02` waits for the server; development runs on a production-matched Docker
> environment in the meantime (`D-66`). `P-02` must still pass before the pilot rollout.

### Sign-off — required before writing code 🔴

- [x] **OD-01** — Are additional items taxable? **No** — delivery and installation are outside the tax
      base (`D-62`, confirmed 2026-08-19)
- [ ] **OD-03** — Server specifications — **Server administrator** · no longer blocks the start of
      development (`D-66`); still blocks the server, `P-02`, and everything on the deployment-debt list
- [x] **VAT ordering** — tax is calculated **after** the discount (`D-64`, confirmed 2026-08-19).
      Supersedes `D-60`; the company's PO #226 is no longer a reconciliation target for tax ordering
- [x] **Rounding** — optional per currency, can be switched off entirely (`D-65`)
- [ ] **«إشعار خصم»** — is the PO line a sale discount or a separate credit note? Still open — **Accountant**
- [x] Stack decision (Laravel) recorded as **D-57** in `§2` and `§14.2`

### Required during the build 🟡

- [x] ~~**OD-02** — PDF template~~ — **confirmed 2026-09-13, recorded as `D-79`**: the `P-01` prototype
      template *is* the approved design. Three items it carried forward stay open as Module 9 build
      work, not as decisions — live page numbering, the one-page re-check on every template change,
      and a customer-view model that structurally cannot hold supplier, cost or margin fields
- [x] ~~**OD-04** — VPN type and concurrent capacity~~ — closed by `D-59`: Cloudflare Tunnel + Access, 5 named users
- [ ] **OD-06** — Company holiday calendar *(before Module 13)*
- [ ] **OD-05** — Expected daily workload *(queue and storage sizing)*

### One-time documents

- [x] Functional and non-functional requirements
- [x] Architecture document
- [x] Permission matrix
- [x] User personas — 8 roles
- [x] Design system
- [x] OpenAPI contract
- [—] Arabic companions — **no longer a deliverable.** Arabic translations exist in `arabic/`
      as reading copies for the project owner only. They are not part of the system and are not
      kept in sync. Five English headers still declare them; see README open questions.

---

## Definition of done — applies to every module

Copy this block per module. A module is not complete until all seven pass.

- [ ] All acceptance criteria pass
- [ ] Permission checks enforced at the API, not just the UI
- [ ] Audit log records this module's operations
- [ ] Loading / empty / error states on every screen
- [ ] Screen works in both RTL and LTR
- [ ] Migration runs and reverses cleanly (up + down)
- [ ] Passes on the production-matched local environment, and its deployment debt is recorded (`D-66`)

> While `P-02` is deferred, the seventh item is the local environment. It reverts to "deployed to the
> server and the smoke test passes" the moment the server exists — and every module already marked done
> gets that item re-opened, not grandfathered.

---

## Two developers — module ownership and the shared trunk

From 2026-08-30 this repository has two people working in it, each driving their own agent. Nothing
below changes a requirement; it records who is where, and the rules that keep two parallel branches
from fighting over the same eleven files.

### Ownership

| Module | Owner | State |
|---|---|---|
| **4 — Catalog & Suppliers** | Yousef | **finished** — 28 of 30 boxes, archived in `checklist/module-04.md` |
| **5 — Requests / Deals** | second developer | **finished** — Steps 1–6 closed 2026-09-09; one criterion at `[~]`, its missing clause (§4.3 visibility column) owed a `D-xx` |
| **6 — Supplier Quotations** | Yousef | **finished** — 31 of 32 boxes, archived in `checklist/module-06.md` |
| **7 — Customer Quotations** | Yousef | **finished** — 57 of 57 boxes, closed 2026-09-14 (#129), archived in `checklist/module-07.md`. *Row added 2026-09-12; the module had been built since 2026-09-07 without one.* |
| **8 — Approvals** | Yousef | **finished** — 17 of 17 boxes, Steps 1–4 on #131–#141, closed 2026-09-16, archived in `checklist/module-08.md`. Reassigned to Yousef 2026-09-13 by owner direction (#94, the second developer's draft list, closed unmerged and superseded by #131). |
| **9 — PDF Generation** | second developer | in progress — Step 1 approved 2026-09-13, Point 1.0 closed (#116); `OD-02` closed by `D-79` (#111) |

Claim a module here **before** the first commit in it, not by whoever pushes first. A module not
listed above is unowned, and picking it up means adding a row.

> ⚠️ **This runs two modules in parallel against a documented sequence — recorded as a decision
> awaiting a `D-xx`.** `CLAUDE.md` and `docs/MVP_Build_Plan_EN.md` require modules in the strict order
> `… 3 Customers → 4 Catalog/Suppliers → 5 Deals → 6 Supplier Quotations …`. Running 4 and 5 side by
> side is a deliberate owner decision (2026-08-30) on the reading that **Deals depends on Customers,
> which is complete, and not on Catalog**. It is not a reinterpretation of the build plan and it does
> not license further reordering. `docs/` is untouched.

> ⚠️ **Module 6 is not parallel with Module 5 — it depends on it.** A supplier quotation carries a
> nullable `deal_id` (Business Invariants), so Module 6's schema cannot put that foreign key anywhere
> until Module 5 has created `deals`. Module 6 may start on the parts that do not touch the column,
> but its migration is blocked until Module 5's schema point is merged into `main`.

### Working rules

1. **Every branch starts from `main`.** This supersedes the single-developer rule of branching from
   the tip of the previous point's branch. With two people, a personal tip is not a shared base, and
   stacking on one is what put twenty-two pull requests in a single chain behind an untouched `main`.
2. **One point → one pull request → merged → then the next point.** No stacking. A point waiting on
   review is not a reason to start another on top of it.
3. **In a shared file, append inside your own block. Never reorder, never reformat, never re-indent
   a neighbouring block.** Every new module endpoint touches these, so both branches will edit most
   of them on most points:

   `crm/routes/api.php` · `crm/bootstrap/app.php` · `crm/app/Providers/AppServiceProvider.php` ·
   `crm/app/Support/Http/ApiExceptionRenderer.php` · `crm/app/Support/Search/SearchIndex.php` ·
   `crm/deptrac.modules.yaml` · `CHECKLIST.md` (each person inside their own module's section)

   A reformat here turns a three-line append into a whole-file conflict.
4. **Migrations are ordered by their timestamp, not by who merged first.** Before adding one, pull
   `main` and check the newest migration already there, so the two developers' filenames interleave in
   the order the tables actually depend on each other.
5. **`composer.lock` and `package-lock.json` change only in a point that deliberately adds a
   dependency**, and that point carries nothing else.
6. **Run this once per clone**, before the first push:

   ```
   git config core.hooksPath githooks
   ```

   It enables [`githooks/pre-push`](githooks/pre-push), which refuses a direct push to `main`.
   ⚠️ **This is an automated agreement, not enforcement.** Server-side branch protection is the
   right tool and is unavailable: the repository is private on a free plan, and both the rulesets
   and branch-protection endpoints answer `403 Upgrade to GitHub Pro` (measured 2026-08-30). The
   hook lives in each clone, so it protects nobody who skipped the command above, and
   `git push --no-verify` walks past it. It stops the accident, not a determined push. If `main`
   ever needs real protection, GitHub Pro on the repository's account is the only thing that gives it.

---

## Deployment debt — `D-66`

Everything below is unverifiable without the real server. Nothing here counts as done until it has
actually run there. This list is the price of starting locally, and it is meant to be read before
every "are we ready to ship" conversation — not at the end.

- [ ] **P-02 itself** — hello page on the server, reachable from a phone
- [ ] **Arabic fonts on Linux** — `P-01` proved the server has none; container parity is evidence, the
      server is proof
- [ ] **Cloudflare Tunnel + Access** (`D-59`) — the whole external path, 5 named users, outbound-only
      tunnel, ≥8h Access session
- [ ] **Module 12 connection-unavailable message** — cannot be exercised without real external access
- [ ] **Boot order** `ST-01`…`ST-09` — locally, `scripts/verify-boot.sh` asserts the `ST-02`
      order with `ST-03` health gating, a restart-survival check (`D-54`, `ST-06`), and records
      each run (`ST-04`, `ST-09`). Still owed on the server: `ST-01` starting at power-on,
      `ST-05` missed-job catch-up, `ST-07` transactional integrity, `ST-08` `/health` per service
- [ ] **Missed-job catch-up on startup** (`D-55`, `ST-05`)
- [ ] **Restart recovery** (`D-54`) — power down, power up, everything returns on its own
- [ ] **Backups** `BK-01`…`BK-08` — daily set, checksums, off-server copy, and a **real restore test**
- [ ] **External heartbeat** (`OBS-07`) — a down server cannot report itself down
- [ ] **ClamAV daemon** (`SEC-15`, `§17`) — added 2026-08-22 with Point 5.5. The application
      speaks clamd's INSTREAM protocol over TCP and refuses to serve anything whose status is
      not `clean`, but **there is no clamd in this stack**: `docker-compose.yml` has no such
      service and the image has no such package. What runs locally and in CI is
      `EicarSignatureScanner`, which knows one signature — the EICAR test file — and calls
      everything else clean. It proves the wiring, not the protection. The server still owes:
      the daemon itself, `freshclam` signature updates and their schedule, a real detection
      against EICAR through `ClamAvScanner`, and a decision on where clamd runs — the adapter
      streams the bytes precisely so it need not share the attachment volume
- [ ] **`STORAGE_PATH` is read by two programs and reconciled by neither** — recorded 2026-08-22
      with Point 5.5. `docker-compose.yml` mounts the `crm-storage` volume at the root `.env`'s
      value; `config/filesystems.php` defaults its disk root to the same literal out of
      `crm/.env`. The php service injects no environment on purpose (two sources of truth once
      silently defeated `phpunit.xml`), so the two files can disagree and nothing notices: the
      volume moves, the application keeps writing to the old path inside the container layer,
      and the attachments vanish on the next `--force-recreate`. Needs either a boot assertion
      or a single source on the server
- [ ] **`J-15` belongs on the `maintenance` queue once Queue Monitor exists** — recorded
      2026-08-22 with Point 6.3, **premise corrected 2026-08-23 with Point 8.5**. `§15` says
      every job appears in Queue Monitor, and `§15.1` files cleanup work on `maintenance`.
      `J-15` sits in the scheduler instead, and the reason is now only that **Horizon is not
      installed**. This entry also used to say the worker services carried a `workers` compose
      profile that was off by default, so a queued job would have sat in Redis unexecuted —
      **Point 8.1 removed that profile.** `docker compose up -d` now starts all four workers,
      and a queued job would be drained. The conclusion is unchanged, but half of what
      justified it stopped being true, and a debt register that argues from a false premise is
      worse than a short one. The server owes Horizon, the move onto `maintenance`, and an
      alert on the job's non-zero exit (`OBS-06`) — today the only signal is the scheduler's
      own output
- [ ] **A non-superuser database role for the application** (`AUD-03`, `D-30`) — recorded
      2026-08-22 with Point 6.2. The application connects as `crm`, and `rolsuper` is `t` —
      measured. The append-only triggers on `audit_log` refuse `UPDATE`, `DELETE` and
      `TRUNCATE`, but a superuser can `ALTER TABLE ... DISABLE TRIGGER` or `DROP TABLE` and
      no trigger can object, so what is enforced today is protection from accidents and
      application bugs rather than from a deliberate hand. The server owes an application
      role that owns nothing, has `INSERT` and `SELECT` on `audit_log` and no more, and a
      separate migration role used only during deployment
- [ ] **Queue and storage sizing** — blocked on `OD-03` and `OD-05`
- [ ] **Nginx + SSL, process manager, `/health` per service** — nginx, TLS termination and the
      HTTP→HTTPS redirect now work locally against a self-signed certificate (point 0.6). What the
      server still has to prove: a real certificate, the process manager starting everything on
      boot, and `/health` reporting each service, which belongs to the application (`ST-08`)
- [ ] **CPU architecture** — the local stack is `linux/arm64` (Apple Silicon); the server is almost
      certainly `linux/amd64`. Decided 2026-08-19 to continue on arm64 and switch when `OD-03`
      names the architecture. Container parity therefore covers the OS, libraries, locale and
      fonts, **but not the instruction set**. Specifically unproven until the server runs it:
      natively-compiled PHP extensions, the headless Chrome build behind Browsershot (`§14.6`),
      and any performance figure measured locally (`PRF-01`…`PRF-03` are not comparable).
      Rebuilding with `--platform linux/amd64` is the switch, at the cost of emulated build speed.
- [ ] **opcache and error display in production** — `docker/php/zzz-production.ini` exists and is
      verified to take effect (`opcache.validate_timestamps` flips to `0`, `display_errors` to
      `Off`). What remains is a deployment actually mounting it: written, not yet proven in place.
      Affects `PRF-01`, and for `display_errors` also `Coding_Standards §9` — a fatal before
      Laravel boots prints raw to the response
- [x] ~~**Container runs as root**~~ — closed in point 0.6, and `storage/` and `bootstrap/cache`
      ownership closed in point 1.5. The image now sets `USER www-data`;
      php-fpm runs its master as www-data too, verified over a real request (`"user":"www-data"`).
      Ownership of `storage/` and `bootstrap/cache` still has to be set when step 1 creates them
- [ ] **Inter embeds as Type 3 in PDF output** — Debian's `fonts-inter` ships OTF/CFF outlines
      (`OTTO`), and Chrome converts CFF to unnamed Type 3 fonts when writing PDF. Verified not to
      be a functional defect: Latin, Arabic and every money figure render correctly and extract and
      search correctly. The costs are a larger file, no font name in PDF metadata to audit against
      `§4.1`, and weaker handling by strict PDF processors. Module 9 embeds its faces base64 per
      P-01 using TrueType-flavoured files, so the production path likely avoids this entirely —
      confirm when the real template lands, and switch the system Inter to a TTF build if not
- [ ] **Tests run as different users locally and in CI** — `www-data` under compose, `root` on the
      runner. File ownership is exactly what broke point 1.5, so a suite that writes files could
      pass in one and fail in the other. Not yet exercised, because nothing writes files yet
- [ ] **Image hardening** — the official `php:*-fpm-bookworm` base ships `gcc`, `make` and
      `autoconf`. Harmless in development, but a compiler inside a production container is
      avoidable attack surface. Strip it in the production image build.

---

## Debt the server does not gate — `Point 8.5`

The register above opens by saying everything in it is unverifiable without the real server.
These are not. They are blocked on an owner decision or on ordinary work, they were each
created by work already merged, and keeping them under a heading that says "wait for the server"
would hide them behind `OD-03` indefinitely.

- [ ] **The quotations table clips its money column at 375 px in Arabic RTL** — *found by
      `rtl-ui-verifier` during F-06 · 1.2 (2026-09-21, #158); pre-existing, not created by that
      point, so it was registered rather than swept into an unrelated diff.* At 375 px the
      `/quotations` table's wrapper overflows its container (`scrollWidth 539` vs `clientWidth 519`,
      20 px), and in RTL the overflow lands on the Total column, so `EGP 57500.000` renders as
      `GP 57500.000` — the leading `E` cut mid-string. Scrolling the table reveals it but then
      clips the quotation-code column instead. In LTR at the same width the column is merely
      off-screen until scrolled, not clipped mid-character. **Root cause is not the money column:**
      the app-wide off-canvas sidebar contributes ~46 px of `document.scrollWidth` overflow on
      *every* screen at 375 px, including the diagnostics page that F-06 never touched — so the fix
      belongs to the shell, not to a quotations point. **The cost, stated:** a phone user in Arabic
      reads a currency code one letter short on the list screen. No figure is wrong; `D-82`'s three
      decimals are correct underneath. Nothing else on the screen is affected.

- [ ] **`team`, `out` and `asgn` row scopes resolve to no rows** — *owner decision, 2026-08-29:
      deferred as debt rather than invented.* §3.2 defines five scopes and only two have a mechanism
      in the system: `all` needs no predicate and `own` is `customers.sales_owner_id`. The other
      three have nothing behind them — §4.1's entity map has **no team entity**, §4.2 has no team
      field and `users` has no team column; `D-44` scopes `out` to "visits, visit customers" and
      `visits` is Module 12; `asgn` is "deals handed over to them" and `deals` is Module 5.
      `CustomerRowScope` therefore fails them **closed**, which is the safe half of an undefined
      rule: guessing that a team means "everyone sharing a role" would invent an authorisation rule
      no source states, and it fails by handing one person's customers to a role the matrix never
      granted them to. **The cost, stated:** the Module 3 acceptance criterion *"Team Leader sees
      team"* cannot pass, and a Team Leader, Outdoor Supervisor and Procurement user currently
      resolve to **no customers at all**. Closing it needs an owner answer to *what defines a team*
      and then either a new migration on `users` or a `D-xx` recording a derivation. The resolver's
      shape already anticipates it — `ownerIds` is a set, not a boolean — so `team` and `out` become
      a wider set later without changing a single caller. `asgn` will not fit that shape (it is a
      join on `deals`, not an owner) and is left for Module 5.

- [ ] **`GET /users` still has no free-text `q`** — *owner decision, 2026-08-29: this stays debt, and
      Identity is not touched from a Customers task.* `UserListCriteria` records the gap in its own
      docblock: `q` was left out of Module 1 because §6.2 says the parameter "always passes through
      `SearchService`", and `CLAUDE.md` builds that seam in Module 3. Module 3 now builds it — and
      wiring it back into Identity would be a cross-module edit that module isolation forbids
      without exactly this decision. **The cost, stated:** `D-48` — "every search call in the
      codebase goes through `SearchService`" — is holed in one known, documented place, and the
      employees list has no search box until a dedicated pass gives it one. The seam itself is not
      at risk: it is being built for Customers either way, so closing this later is wiring, not
      design.

- [ ] **The `AuditEnforcementTest` signal hole** — *deferred by the owner 2026-08-28, to a
      dedicated pass.* The guard classifies a class as a database writer only when it carries one of
      four signals — `ConnectionInterface`, `->table(`, `DB::`, `Eloquent\Model`. A repository
      writing purely through a **module-aliased** Eloquent model carries none of them and is
      invisible to it. Measured at five classes in Point 3.2 and **six** after Point 3.4:
      `EloquentCurrencyRepository`, `EloquentManagedListRepository`, and four Identity adapters
      shipped with Module 1 (`BearerSessionResolver`, `EloquentSessionStore`,
      `EloquentAccountDirectory`, `EloquentUserDirectory`). **Nothing is unrecorded today** — every
      one is audited a layer out — but `AUD-01`'s enforcement is weaker than it reads. It is its own
      point because widening the signal re-classifies four already-shipped Identity classes, and
      each needs its disposition decided rather than guessed.
      **Grown to eleven by Module 6 Point 2.1, and the newest one is the clearest illustration yet:**
      `CreateSupplierQuotation` is the class that *does* the auditing — it owns the transaction and
      records `SUPPLIER_QUOTATION_CREATED` — and the scanner cannot see it, because it calls
      `->create(`, `->record(` and `->transaction(` and none of those is a DML verb. Its persistence
      adapter, which records nothing, **is** seen. Measured: the test passes with the use case
      unlisted, and listing it fails the identity assertion instead. The second signal family this
      pass owes is therefore not only "module-aliased Eloquent model" but "owns a transaction around
      a writer" 
- [x] **`ApiEnvelope` now lives in a shared layer** — *closed 2026-08-29, as its own point, on the
      trigger this entry itself named: "before a third module needs one".* Module 3's customers
      endpoints were that third module. `App\Support\Http\ApiEnvelope` is now the single copy;
      both module copies are deleted, and `deptrac` names it through `SharedContracts` in **both**
      configs with `Presentation` and `IdentityDriver` gaining that one edge.
      ⚠️ **The two copies had already drifted, which is what settled this by measurement rather than
      taste:** Admin's copy carried **no `error()` at all**, so `OpenAPI §5`'s error envelope existed
      for one module and silently not the other. It also fixes a dependency pointing the wrong way —
      `App\Support\Http\ApiExceptionRenderer` had to reach *into* `Identity\Presentation` to build
      an error body.

- [ ] **The list-query contract is still duplicated, and it cannot follow the envelope** — *the
      remaining half, and the reason is measured rather than assumed.* `InvalidListQuery` (Identity)
      and `InvalidListingQuery` (Admin) both live in **Domain**, whose `deptrac.layers.yaml` ruleset
      is empty on purpose — "may depend on nothing", the load-bearing rule of that file. A shared
      base in `App\Support` would be a Domain → SharedContracts edge. **Probed, not inferred:** a
      real reference was added to `Admin\Domain\Listing\InvalidListingQuery` and deptrac answered
      `DependsOnDisallowedLayer — App\Modules\Admin\Domain\Listing\InvalidListingQuery must not
      depend on App\Support\Http\ApiEnvelope`; the probe was then reverted and the file confirmed
      byte-identical with `shasum -a 256 -c`. So Customers gets a third small copy in Point 3.2, and
      closing this needs either a decision to weaken Domain's empty ruleset or a different shape
      entirely — both bigger than a Customers point. **Module 6 Point 4.1 makes it the fifth
      copy** (`InvalidSupplierQuotationListQuery`), with `SupplierListCriteria`'s parser copied
      beside it minus the three parsers this resource does not declare.
      **Point 4.3 makes the renderer side six** — `grep -n "public static function invalid.*ListQuery"
      crm/app/Support/Http/ApiExceptionRenderer.php` → 6 — and the audit that measured it found the
      same forced repetition in three more places, all pre-existing and none introduced by 4.3:
      `Payload::pagination()`'s identical six-key body in **8** payloads, `Payload::many()`'s
      `array_map(static fn … self::of …)` in **6**, and the controller `index()`
      `::fromQuery($request->query())` shape in **6**. Same root cause as this row — a module's
      Presentation layer cannot share code across the deptrac boundary — so this row now covers all
      four shapes rather than the exception alone.
      **Point 5.3 adds a fifth shape and the starkest one yet.**
      `SupplierQuotationDocumentPayload::of()` and `DealDocumentPayload::of()` differ by their
      parameter's type name and **nothing else** — `diff` on the two method bodies returns one line —
      and `UploadSupplierQuotationDocumentRequest` repeats `AttachDealDocumentRequest`'s two rules
      and its `document()` accessor. Both were written rather than shared because sharing them means
      Module 6's Presentation importing Module 5's, which deptrac refuses and `CLAUDE.md` forbids
      ahead of it. **This is the architecture's stated price, not an oversight**, and it is recorded
      here so the price stays visible: a shared `app/Support` home for a payload shape that four
      modules will eventually need is a decision the owner should take deliberately, not one that
      should arrive by accident on the day a fifth module copies it

- [x] **The live permission matrix could drift from §3 with nothing to notice** — found 2026-09-05
      by the owner, who saw the Manager holding the RBAC screen. Confirmed and measured: the live
      grant set differed from §3 in **four grants beyond it** (`manager | admin.manage_roles.all`,
      `manager | admin.system_settings.all`, `team_leader | customer.view.all`,
      `team_leader | customer.edit.all`) and **one missing from it** (`manager | customer.import.all`).
      **No code was at fault and no branch caused it.** `PermissionMatrix` grants `admin.manage_roles`
      to the Super Admin alone and no committed version of that file has ever said otherwise;
      `git diff origin/main...HEAD` on it is empty. The grants were made **through the application's
      own role-administration endpoints** and `audit_log` names them exactly — `ROLE_PERMISSIONS_UPDATED`
      on 2026-08-31 19:53:58 by `super.admin@example.test` from `172.18.0.1`, `granted:
      ["admin.manage_roles.all", "admin.system_settings.all"]`. `SEC-07` puts the matrix in the
      database and §3.12 rule 5 makes changing it a configuration change, so this is a **supported
      operation**, not a defect.
      **The real gap was that nothing could see it.** `RbacMatrixDataTest` makes the same comparison
      and was green throughout, because `RefreshDatabase` only ever shows it a database the seeder
      has just written — it proves the seeder agrees with the matrix and can say nothing about a
      running system. `RolePermissionSeeder`'s docblock promises "divergence is reported by the
      test", which is true of a clean database and of no other.
      **Closed by `php artisan rbac:verify`** — `VerifyPermissionMatrix` (Application) over
      `PermissionRepositoryInterface::roleIdsBySlug()` and `scopesFor()`, reporting a
      `MatrixDivergence` of `extra`/`missing`, rendered by a thin command whose **non-zero exit is
      the alarm** (`OBS-06`, `EnsureAuditPartitionsCommand`'s reading). **Read-only by design:**
      repairing drift means an audited actor (`AUD-01`) and a soft delete (`DB-01`), which is
      Module 1's role administration, not a maintenance command.
      **Stated ceiling:** 9 roles × 57 permissions, one memoised query each — a manual command, not
      a request path. It compares **documented** roles against **documented** permissions only: a
      `resource.action` §3 never declared, and a role an administrator created after seeding, are
      both legitimate under `SEC-07` and are deliberately not reported, so the command cannot cry
      wolf on its first honest use.
      ⚠️ **The comparison now exists twice** — in `RbacMatrixDataTest` and in the use case. Folding
      the test onto the use case is the obvious simplification and is **not** done here: it rewrites
      an existing test outside this point's scope. Registered below.
      ⚠️ **A live revocation happened mid-investigation.** At 2026-09-05 07:37 `super.admin@example.test`
      revoked six CEO grants §3 *does* declare (`quotation.view`, `quotation.view_cost_and_margin`,
      `quotation.export_pdf`, `catalog.view`, `supplier_quotation.view`,
      `procurement.view_negotiation_log`), which is why a second reading of the same database
      reported seven missing rather than one. The command's first real run caught it. **The four
      grants beyond §3 are still in place** — nothing here revoked anything.

- [ ] **A role change made outside an HTTP request is recorded as a system action, not a user's** —
      measured 2026-09-05 while revoking the four grants beyond §3. The two `ROLE_PERMISSIONS_UPDATED`
      rows at `08:12:10` carry `user_id = NULL`.
      ⚠️ **The first diagnosis written here was wrong and is corrected.** It said
      `RequestAuditContext` reads `$request->user()` and that setting a user resolver failed to reach
      it. Reading the class settles it: the gate is the **request-id attribute**, which `AddRequestId`
      sets in HTTP and no console request has. Missing it, the class returns `AuditContext::system()`
      and **never looks at the user at all** — so no amount of setting the guard user could have
      changed the outcome. Console actions are recorded as system actions **by design**, and its own
      docblock names J-15 as an existing such caller.
      **The application is not at fault**, and the endpoint attributes correctly — the rows at
      `2026-08-31 19:53:58` and `2026-09-05 07:37` both name `super.admin@example.test`. **The two
      rows are not repaired:** audit records are immutable and retained permanently.
      **The open question is therefore narrower than it first looked.** Not "why did attribution
      fail" but "is `system` an acceptable actor for a permission change?" `AUD-01` names role changes
      among what must always be audited, and §3.12 rule 4 exists so a real person is named. A console
      role change satisfies the first and not the spirit of the second. Whether to give the console an
      explicit actor option, or to refuse permission writes outside a request, is Module 1's decision
      and is not taken here.

- [x] **The live grant set was returned to §3** — 2026-09-05. The four grants beyond §3 were revoked
      through `SyncRolePermissions`, the use case the roles screen itself calls, so the removals are
      soft deletes (`DB-01`) with an audit event. The seven missing were restored by running
      `RolePermissionSeeder`, **not** by another hand-written script: the seeder is idempotent by
      construction, sets `deleted_at => null` on a grant that already exists, and only ever touches
      what `PermissionMatrix` declares — so it could restore exactly the seven and could not re-add
      the four. `php artisan rbac:verify` now exits `0` with "the live grant set matches §3".
      ⚠️ **The restore writes no audit row**, because a seeder is not a user action. That is the same
      question the row above leaves open, seen from the other side.

- [ ] **The §3-versus-live comparison is written twice** — `RbacMatrixDataTest` builds it inline
      across six `grants()` loops, and `VerifyPermissionMatrix` now builds it again as a use case.
      Only the second can run against a real database, so the first is the one that should collapse
      onto it. Not done inside the point that revealed it, on the standing rule that a point does not
      widen past its approved scope.

- [ ] **Four `…Page` classes carry arithmetic no test reaches** — `SupplierPage`'s own docblock
      says the arithmetic lives in the page rather than in the serialiser "because arithmetic in a
      serialiser is arithmetic no unit test reaches", and then nothing reached it there either: a
      grep for `totalPages` or `hasNextPage` across `crm/tests` returned **no file** before Module 6
      Point 4.1. Customers, Suppliers, Catalog and Deals each hold an untested copy; their
      `meta.pagination` is asserted only through endpoint fixtures that happen to be one page.
      Module 6's copy has `SupplierQuotationPageTest`; the other four are one small file each and
      belong to their own modules
- [ ] **`OD-08`'s similarity threshold is declared and unseeded, so `D-35`'s warning never fires** —
      *owner decision, 2026-08-30.* §10.2 asks for a warning when similarity is *"above the
      threshold"* and `OD-08` gives the threshold no value, saying only *"Empirical — tuned after
      the first 100 customers"*. `SystemLimit::CustomerSimilarityThreshold` is therefore the enum's
      **seventh** case where §13 names six, and it is left unvalued for the reason
      `SystemSettingsSeeder` already records: *"a default nobody wrote would arrive as configuration
      and be read as fact"*. **The cost, stated:** until an administrator types a number into §13
      screen 6, `POST`/`PATCH /customers` never returns `meta.similar_customers`, and §10.2's
      acceptance criterion cannot pass. Nothing is unsafe — `D-35` is *"warning only; the employee
      decides"*, so an absent warning is a save that goes through, which is what it does today
      anyway. Closing it is one row in `system_limits`; the measured gap the value sits in is
      **0.09 for two unrelated Arabic company names against 1.00 for a folded duplicate**
- [ ] **The duplicate warning is row-scoped, so two callers with disjoint scopes can each create the
      same customer** — *decided in Point 3.3, and it is a trade, not an oversight.* §10.2 wants the
      warning to **list** the similar customers; `SEC-08` says a caller may not see a row outside
      their scope. Listing one would leak another owner's customer name through a convenience, so
      the probe runs inside the module's one `scoped()` builder factory, like every other read. What that costs:
      an Indoor Sales user is not warned about a duplicate owned by somebody else, and will create
      it. **Open owner question** — is an unscoped *count* ("2 similar customers exist, ask your
      manager") an acceptable middle, or does the leak-free version stand? A count still discloses
      existence, which is why it is asked rather than assumed
- [ ] **The audit scanner's blind spot is now seven classes** — *unchanged in kind, one wider.*
      `EloquentCustomerDirectory` gained `->save(` in Point 3.3 and `AuditEnforcementTest` cannot
      see it: a repository writing purely through a module-aliased Eloquent model carries none of
      the four signals `scan()` looks for, exactly as `EloquentManagedListRepository` does. `AUD-01`
      is satisfied one layer out — `SaveCustomer` owns the transaction, is registered `AUDITED`, and
      **is** seen by the scanner (`->update(` beside an imported `ConnectionInterface`) — but that is
      the register being right by luck of a method name, not the scanner being right. Still owed its
      own point. ⚠️ **Point 3.4 makes it worse and proves the point:** `ArchiveCustomer` owns the
      archive/restore transaction and writes §3.12 rule 4's `ARCHIVE_RESTORED`, and the scanner does
      **not** see it — measured, the register test passes with it unlisted, because `setArchived(`,
      `find(` and `record(` are none of them DML verbs. ⚠️ **Point 3.5 measured the hole's exact edge:** `AssignCustomer`
      writes the same kind of transaction as `ArchiveCustomer` and **is** seen — it calls `->update(`
      beside `ConnectionInterface` where its sibling calls `->setArchived(`. Two classes doing one
      kind of work, one visible on the spelling of a method name. Found by this test failing on the
      unlisted class **after a comment claimed it would be invisible**; it is now registered `AUDITED`. ⚠️ **Point 3.6 puts it back on show:** `ImportCustomers` writes one `CUSTOMER_CREATED` per
      imported row and is **not** seen (`->create(`, `->record(`, `->handle(` are none of them the
      scanned verbs), and neither is `EloquentImportBatches`. Listing them would fail the register's
      identity assertion instead of satisfying it — which is the clearest statement yet that this
      hole needs its own point
- [ ] **Bulk restore is documented and not built** — Flow 7 grants restore *"individually or
      select-all"* and `OpenAPI §7.3` gives the exact shape: a resource-specific action, a bounded
      `{"ids": [...]}` list, per-record result data, every record authorised and audited, and **no
      bypass of the row scope**. Point 3.4 built the singular routes only, because the approved
      point list names those. **Point 4.4's screen needs it** ("archive, individually and
      select-all"), so it is owed before that screen — as its own point, or as 4.4's first half.
      Bulk *archive* is a separate question: Flow 7 puts "individually or select-all" in the
      **Restore** column only, so it is not clearly documented and was not assumed
- [ ] **Who may *view* an archived customer is undocumented** — *raised in Point 3.4, and left
      open rather than closed by invention.* Flow 7 restricts **archiving and restoring** to
      Manager and Team Leader; it says nothing about reading an archived row, and §3.3's `view` row
      carries no archived carve-out. So `filter[is_archived]=true` remains available to every holder
      of `customer.view`, within their own scope. ⚠️ This **corrects** Point 3.2's note, which said
      the rule was 3.4's to enforce. Restricting the filter would be safer and would also hide a
      customer from their own owner with no source saying to — an owner decision, not an
      implementation one
- [x] **`D-73` has a row in the master decision log** — *closed 2026-08-23 with Point 1.1, under
      the owner's explicit authorisation.* It is in `§2.8 Operations & Scope`; `grep -n "D-73"
      docs/CRM_Documentation_EN.md` returns line 153, re-checked 2026-08-24. `CRM_Documentation_EN.md`
      is hook-protected (`.claude/settings.json` blocks `Edit`/`Write` on it), so the edit went
      through a script rather than the blocked tools — **disclosed at the time, not worked around**
- [x] **The `D-72` note under `§15.1` has been corrected** — *closed 2026-08-23 with Point 1.1,
      same authorisation.* It read "the worker services sit behind a compose profile that is off
      by default"; Point 8.1 deleted that profile. `grep -c "compose profile that is off by
      default" docs/CRM_Documentation_EN.md` returns **0**, re-checked 2026-08-24. The note's
      conclusion was never in question — `J-15` stays in the scheduler while Horizon does not
      exist — so this was a correction, not a reversal
- [ ] **`.env.testing` governs the suite locally and does not exist in CI** — recorded 2026-08-23
      with Point 8.5, after Point 8.2's narrative claimed it was "now on the debt register" while
      it was not. It is `.gitignore`d under `.env.*`, so in CI `APP_ENV=testing` falls back to
      `.env`. A Redis password worked on this machine and an empty one worked on the runner, and
      the divergence cost a debug cycle. **To reproduce a CI failure locally, remove it first.**
      Owed: either commit a checked-in testing environment both sides read, or delete the file
      and make the two paths identical
- [ ] **The latency probe is exercised by no automated check** — recorded 2026-08-23 with Point
      8.5. `LatencyBudgetTest` covers the percentile and the verdict, but CI starts no web server,
      so `ApiLatencyProbe`'s curl loop is proven only by the runs recorded by hand under Point
      8.4. It will stay that way until something in CI serves HTTP
- [ ] **Two scaffolded tables are now unused: `password_reset_tokens` and `sessions`** — recorded
      2026-08-23 with Point 1.2. Both come from `0001_01_01_000000`, the migration whose `users`
      table that point replaced. `sessions` is inert because `SESSION_DRIVER=redis` (§14.2), and
      it is now actively confusing beside `user_sessions`, which is the `SEC-05` device list and a
      different thing. `password_reset_tokens` has no flow: `§3` lists `change-password` and there
      is **no public sign-up and no password-reset endpoint** in Module 1's contract, and `SEC-04`
      puts email verification inside the password-change flow rather than behind a token table.
      They were left in place rather than dropped in passing, because dropping a table is its own
      decision and Point 1.2 was given `users` and `user_sessions`. Owed: confirm neither is
      wanted, then one correcting migration that drops both
- [x] **`docker-compose.yml` can change without CI running** — found 2026-08-23 while closing
      Point 8.5, by noticing that this point's own commit triggered no run. `php-image.yml` filters
      on `docker/php/**`, `crm/**` and itself. **`docker-compose.yml` is in none of them**, and yet
      `QueueConfigurationTest` parses that file to assert the worker `--queue=` flags match
      `config/queue.php` — the check Point 8.1 built precisely because those two live where neither
      can see the other. A commit that edits only compose would skip the one test that validates
      it. Owed: add `docker-compose.yml` to the `paths` filter. Not done here, because it is a
      change to CI behaviour and this point is a sign-off, not a fix
      *(2026-09-12, CI sharding PR — `docker-compose.yml` and `.env.example` added to both `paths` filters)*
- [ ] **`php-image.yml` test shards are balanced by hand** — created 2026-09-12 by the CI
      sharding PR, which split the 545 s serial test step (70 % of a 13-minute run) into three
      matrix jobs, each running its own migrate up/down/up and a fixed list of test directories.
      Two things a reader must know. **(1) A new directory under `tests/Feature` runs in no
      shard until it is added to the matrix** — the sharding check in the PR compared the three
      `Tests:` lines against the local full suite, and that comparison is owed again by any
      point that adds a directory. **(2) The split is by local wall time, which is not the
      runner's** (74 / 66 / 57 s on 2026-09-12, after moving `Catalog` once); rebalance when one
      shard runs more than 30 % longer than the others (`gh run view <id> --json jobs`). Paratest (`--parallel`) was not added — a new
      dependency for a gain the shards already give; the day the shards exceed 4 minutes each is
      the day to reconsider. Runner minutes rose: three test jobs plus four cache restores per
      run, against one serial job before.
- [ ] **The `AUD-01` writer scanner cannot see an Eloquent adapter** — found 2026-08-25 while
      closing Point 3.2, by noticing that `EloquentUserDirectory` writes three ways and the scanner
      never named it. `AuditEnforcementTest` calls something a database write only when a DML verb
      appears **beside** one of four signals — `ConnectionInterface`, `->table(`, `DB::` or
      `Eloquent\Model`. Measured, in this session: `EloquentSessionStore`, `EloquentAccountDirectory`
      and `EloquentUserDirectory` each contain `->save(`/`->delete(`/`->update(` and **none of the
      four signals**, because they type-hint a concrete model class rather than the base. So three
      writers are invisible to the guard that exists to make an unaudited write a build failure.
      Their callers do audit today, which is why nothing is currently unrecorded — but the guard is
      not guarding them. Owed: add a signal that matches a model import (or the `Infrastructure\Eloquent`
      namespace), then register whatever it finds. Not done in Point 3.2: two of the three classes
      are Point 2.2's, widening the scanner is its own change with its own failure proof, and
      `CLAUDE.md` says to mention an unrelated issue rather than change it
- [ ] **Three copies of the managed-list label ternary remain under `pages/customers/`** —
      revealed 2026-08-31 by Point 6.4. `locale.startsWith('ar') ? entry.label_ar : entry.label_en`
      is written out in `CustomersView.vue`, `CustomerFormModal.vue` and `CustomerDetailView.vue`.
      Point 6.4 removed the fourth copy it was about to add by exporting `entryLabel(entry, locale)`
      from `services/admin.ts`, which is now the shared one. Owed: three one-line swaps to the
      import. Not done in 6.4 — the files are outside its approved list and a cleanup buried in an
      unrelated point is unreviewable
- [ ] **`company` is required on the server but is not in the catalog form's `REQUIRED` mirror** —
      recorded 2026-08-31 with Point 6.4. `SaveCatalogItemRequest` has made it `required` on POST
      and `sometimes|required` on PATCH since Point 5.2, but `CatalogItemFormModal`'s `REQUIRED` map
      still lists only the three `required_if` rules. So the field carries **no required marker**
      (§6.3 asks for an explicit one) and a blank company costs a round trip, coming back as the
      server's sentence instead. Owed: one entry in each `REQUIRED` list plus a `catalog.form.required.company`
      key in both locales. Not done in 6.4: the approved point list names the controls, not the
      validation mirror
- [ ] **Five more schema tests still roll back with `migrate:rollback --path`, and each is one
      foreign key from red** — revealed 2026-09-02 by Module 6 Point 1.1. `--path` does **not**
      select which migrations roll back: `Migrator::rollback()` takes the whole last batch from the
      repository and uses the path only to resolve each entry to a file, skipping what it cannot
      resolve. Under `RefreshDatabase` the schema is a single batch, so a one-file path means "run
      this `down()` while every later table still stands" — which is why four such tests went red
      the moment `supplier_quotations` added foreign keys onto `suppliers`, `deals`, `customers` and
      `currencies`, none of their `down()` methods having changed (`SQLSTATE[2BP01]`). Those four
      were converted to `migrate:reset` in 1.1 because they were failing. Still on the old idiom and
      still green only because nothing references them yet: `TrigramExtensionMigrationTest`,
      `ImportBatchSchemaMigrationTest`, `ManagedListSchemaMigrationTest`, `DealLostReasonMigrationTest`,
      `SettingsSchemaMigrationTest`. Owed: the same four-line swap in each. Not done in 1.1 — they are
      green, they belong to four other modules, and converting a passing test on suspicion is a change
      with no failure to prove it. **`CatalogItemSchemaMigrationTest` was the sixth and is no longer
      owed**: Point 1.2 gave `catalog_items` its first child (`supplier_quotation_items`) and the test
      went red exactly as predicted, so it was converted there — with a failure to prove it
- [ ] **`SQ-` and `DL-` allocation is the same twenty lines in two modules** — created 2026-09-02 by
      Module 6 Point 1.3. `EloquentSupplierQuotationDirectory::nextCode()` and
      `EloquentDealDirectory::nextCode()` differ only in a two-letter prefix and the string in their
      `RuntimeException`; `QT`, `PO` and `RPT-*` (§4.7) will each want a third, fourth and fifth copy.
      **Not extracted to `App\Support`, and the reason is architectural rather than effort:**
      `AuditEnforcementTest::test_nothing_outside_a_module_writes_to_the_database` forbids
      `app/Http`, `app/Support` and `routes` from writing to the database at all, and a sequence
      allocator is a write. Its scanner would miss this one — the DML is a raw SQL string passed to
      `->selectOne(`, not a `->insert(` call — so putting it there would pass the gate on a spelling
      technicality while breaking exactly what the gate protects. Owed: a decision on where a
      cross-module *write* helper is allowed to live, before §4.7's third prefix needs one

- [ ] **`Idempotency-Key` is required by `OpenAPI §9.1` and exists nowhere** — recorded 2026-09-02
      by Module 6 Point 2.2, the first point to publish an endpoint that the contract actually names.
      §9.1 lists "deals, quotations, supplier quotations, purchase orders, reports, versions"; every
      earlier module read that list and correctly found its own resource absent, so the header's
      absence was a *reading* each time. It is not one here, and **`POST /deals` is in the same
      position without saying so** — Module 5's routes do not mention the header at all. Owed: the
      persisted store §9.1 describes (actor · route · key · request hash · final status · response),
      replay without repeating the side effect, `409 idempotency_conflict` on a reused key with a
      changed payload, and authorisation re-checked on each replay. Proposed as Module 6 Point 2.2b;
      **needs an owner decision on where it lives**, because a persisting middleware is a database
      writer and `app/Http`, `app/Support` and `routes` are forbidden from writing

- [ ] **`OpenAPI §9.2`'s optimistic concurrency exists nowhere, and Module 7 cannot ship without
      it** — recorded 2026-09-02 by Module 6 Point 2.4, which read §9.2 and correctly found supplier
      quotations *outside* it. §9.2 owes a version token on a quotation read (`"etag":
      "quotation:uuid:7"`), `If-Match` on a quotation mutation, and `409 concurrency_conflict` with a
      safe refresh reference and no silent merge. `DB-12` and `API-12` say the same. Nothing in the
      codebase carries a version column, an `If-Match` reader, or that error code. **Not owed by
      Module 6**: §9.2's closing sentence allows adopting the pattern for other resources "only
      through a documented contract update", and the owner confirmed on 2026-09-02 that 2.4 builds
      none. It becomes due with Module 7, and if supplier quotations are ever to have it, that needs
      a `D-xx` first

- [ ] **Five modules each carry a private `changedFrom()` that limits `AUD-02`'s old values to the
      fields an edit touched** — the fifth was added 2026-09-02 by Module 6 Point 2.4. `SaveCustomer`,
      `SaveSupplier`, `SaveCatalogItem`, `SaveDeal` and `UpdateSupplierQuotation` implement the same
      four lines over five different summary types. 2.4 originally named its copy `replaced()`, which
      made the duplication ungreppable; it was renamed to match its siblings inside that point, but
      the duplication itself is a cross-module edit with no failing test behind it, so it is recorded
      rather than extracted. Owed: one generic helper — the obstacle is that each takes a different
      readonly summary class, so extraction needs either an interface or an array projection

- [ ] **`catalog_items.name` carries no uniqueness, so `D-22`'s reuse is a convention the lookup
      enforces rather than a constraint** — recorded 2026-09-03 by Module 6 Point 3.1. The owner
      ruled that a name already present is the same product, and `findProductIdByName()` implements
      that with `lower(name) = lower(?)` plus `orderBy('id')` for determinism. Nothing stops two live
      products sharing a name: the column is nullable, most existing rows may have one, and a unique
      index would refuse the rows that do not. Two consequences are accepted rather than hidden —
      two genuinely different products that share a name become one catalog item, and a name typed
      with different surrounding whitespace makes a second row, because 3.1 deliberately normalises
      only case and leaves trimming to the boundary in Point 3.3. Owed: a decision on whether
      product names are meant to be unique at all, which is a `D-xx` and not a migration

- [ ] **`supplier_quotation_items` records no line order, so §7.2's "+ to add more" cannot be read
      back in the order it was typed** — revealed 2026-09-02 by Module 6 Point 2.3, the first point
      to read the lines. Point 2.1 writes the whole batch with one `insert()` under a single `now()`
      and gives each row a random UUID, so `created_at` ties and `id` sorts arbitrarily; without an
      `ORDER BY` PostgreSQL may return two calls differently. The read orders by `id` — deterministic,
      but not the user's order — and its test asserts the lines by content rather than by position,
      because asserting position would pass or fail on a coin toss. Owed: either a `sort_order`
      column on the child table (a new migration, `DEV-03`'s `down()` included) or a documented
      decision that line order is not preserved. **Not fixed in 2.3**: adding a column to satisfy a
      read point would widen the approved point list, and §7.2 does not state the requirement
      outright — it is implied by "+ to add more". Point 2.4 (`PATCH`) has to answer it anyway,
      because editing lines without identity or order is a set replacement

- [ ] **A test's HTTP client stays authenticated across requests, so a header-less call after an
      authenticated one is not anonymous** — revealed 2026-09-02 by Module 6 Point 2.3, whose 401
      test created an offer first and then got **404** (the Manager's answer for an absent row)
      instead of 401. Measured, not inferred: with no login at all the same call is 401; after a
      Manager request it answers as the Manager; after an Outdoor Supervisor request it answers 403.
      A request presenting a *different* bearer token does re-resolve correctly, so assertions made
      **with** a token are sound — the leak only affects a call that deliberately sends none.
      `Illuminate\Auth\RequestGuard::user()` caches the resolved user and nothing in the framework
      calls `AuthManager::forgetGuards()` between requests. **Not a production defect** — a real
      caller gets a fresh process per request — but it is a live trap for every endpoint test in the
      repository: any "unauthenticated is refused" case written after a login in the same method
      passes or fails for the wrong reason. 2.3's own test now makes that assertion its only request.
      Owed: a sweep of the existing 401 tests, and a `forgetGuards()` in the base `TestCase` so the
      trap cannot be stepped in again

- [ ] **Ten schema tests each carry their own private `refusedWith()`** — revealed 2026-09-02 by
      Module 6 Point 1.2, which added the tenth. The helper is identical in all of them: run a write,
      catch `QueryException`, return `errorInfo[0]`, and fail if the database accepted the row. It
      spans six modules (`Customers`, `Suppliers`, `Catalog`, `Admin`, `Deals`, `SupplierQuotations`),
      so extracting it is a cross-module edit with no failing test behind it — which is why 1.2
      recorded it instead of doing it. Owed: one trait under `tests/`, ten call sites deleted. The
      four `SQLSTATE` constants beside it duplicate the same way

- [ ] **A customer+deal fixture is now copied byte-for-byte between two test files, and the
      per-file test-helper convention is what makes it unavoidable** — revealed 2026-09-04 by
      Module 6 Point 4.4, which needed a real deal for `filter[deal_id]` and copied
      `EloquentSupplierQuotationDirectoryTest::deal()` into `SupplierQuotationListEndpointTest`.
      `diff <(sed -n '/private function deal(): string/,/^    }/p' …)` between the two is **empty** —
      identical customer name, identical `DL-YYYY-9001`, identical field set. ⚠️ **4.4's waste audit
      called this "created by this point", and the point disagreed and recorded it here instead.**
      The reason is the same one the `refusedWith()` row gives: there is **no shared test-helper
      location in this repository** — `find crm/tests -name '*.php' -not -name '*Test.php'` returns
      `TestCase.php` and one fixture migration, nothing else — and the convention is per-file
      helpers, measured at **25** copies of `bearerFor()` and **31** of `userWith()`. Inventing the
      first `tests/Concerns/` trait for one 20-line fixture, inside a point whose approved list is two
      acceptance criteria, is a structural decision about 31 existing call sites that a test-only
      point does not get to make. Owed together with the `refusedWith()` row and the four `SQLSTATE`
      constants: **one trait, one decision, all three squashed at once.**
      *(The audit's second finding — `offerFor()` re-implementing `created()`'s POST/assert/extract
      body — was genuinely created by 4.4 and needed no scaffolding, so it was removed inside the
      point: `created()` now takes the overrides and `offerFor()` is gone.)*

      ⚠️ **It happened a second time, and that is the point of writing this line.** Module 6 Point
      5.2's audit found `offer()` — the supplier + currency + offer fixture — **byte-identical**
      between `AttachSupplierQuotationDocumentTest` and `SupplierQuotationAttachmentPermissionTest`,
      *both written by this branch*, 5.2 and 5.1, in the same directory and the same namespace.
      `diff` on the two bodies is empty. It was registered rather than removed for the same reason
      as the `deal()` copy above, and registering it twice is the argument that the reason is
      wearing out: **two points in a row have each created a copy that only a shared location could
      have prevented.** The measured convention is 32 copies of `userWith()` and 9 of `deal()`,
      so the trait is not a new idea, it is a deferred one.
      **Concrete proposal for the owner, one decision:** one `Tests\Concerns` trait carrying
      `userWith()`/`bearerFor()`, and one `Tests\Feature\SupplierQuotations\Concerns` trait
      carrying `offer()`. That is a point of its own — it touches dozens of files and cannot ride
      inside a feature point — and until it is approved every new test in this module will keep
      adding to this row.
      **Point 5.3 did exactly that, as predicted, and made it three points in a row.** Measured after
      it: `grep -rl 'private function userWith' crm/tests | wc -l` → **33**, `bearerFor()` → **26**.
      It also added a **sixth** copy of the minimal `%PDF-1.4` fixture — `grep -rl '%PDF-1.4'
      crm/tests` now lists `UploadValidationTest`, `VirusScanningTest`, `FileDownloadTest`,
      `DealDocumentUploadTest`, `AttachSupplierQuotationDocumentTest` and this point's endpoint test.
      The offer fixture itself was **not** copied a third time: the endpoint test creates its offer
      through `POST /supplier-quotations`, which is the module's own public surface, so only a
      supplier row is seeded.
      **Module 7 Point 4.2 (2026-09-12) added another copy** — `QuotationSubmitEndpointTest` carries
      the 129-line fixture set (`currency`, `currencyId`, `customer`, `deal`, `supplierLine`,
      `userWith`, `bearerFor`) byte-identical to `QuotationUpdateEndpointTest`'s (`diff` empty).
      Measured after it: `userWith()` → **38** files, `bearerFor()` → **31**. Same reason, same
      proposal; the trait is still one owner decision away.
      **Point 4.3 (2026-09-12), one more:** `QuotationNewVersionEndpointTest` — `userWith()` → **39**,
      `bearerFor()` → **32**, `supplierLine()` → **7**. Three Step 4 endpoint tests now carry the
      same 129 lines; the fourth (4.4) will too unless the trait is approved first.
      **Point 4.4 (2026-09-12), the fourth:** `QuotationDeleteEndpointTest` — `userWith()` → **40**,
      `bearerFor()` → **33**, `supplierLine()` → **8**. Step 4 has no fifth endpoint test (4.5 is a
      read-side change), so the next copy is Step 5's.

- [ ] **`DealAttachmentPermission`'s parent guard is inert, and so was the mirror of it** —
      revealed 2026-09-04 by Module 6 Point 5.1, which wrote the mirror, defended it in a comment,
      then probed it: deleting `if ($link->parent !== SupplierQuotation) return false;` reddened
      **no test**, because a foreign parent's id is not an offer's id and `find()` already returns
      `null`. Module 6's copy was deleted inside that point. **Module 5's is untouched** — it is
      another module's code and a Module 6 point does not edit it on a finding made in passing.
      Owed: delete the branch in `DealAttachmentPermission::mayView()`, or give it a test that fails
      without it. The behavioural assertion in `DealAttachmentPermissionTest` stays either way; what
      is in question is only whether the branch does anything

- [ ] **Thirteen test helpers mint a "unique" code from four hex characters, and CI goes red at
      random because of it** — revealed 2026-09-02 by Module 6 Point 1.1's own CI run, which failed
      on a test the diff does not touch. `'DL-2026-'.substr(str_replace('-', '', $id), -4)` takes the
      last 16 bits of a UUID, so a test inserting three deals draws three values out of 65,536 and
      `deals_code_unique` eventually loses the birthday bet:
      `SQLSTATE[23505] duplicate key value ... Key (code)=(DL-2026-b328) already exists`, in
      `DealListEndpointTest::test_that_pagination_splits_the_result`. **Proven a flake, not a
      regression:** re-running the identical commit `0c20822` turned the same job green. The pattern
      is in `DealListEndpointTest`, `DealLostReasonMigrationTest`, `CustomerStatusRecomputeTest`,
      `DealDocumentUploadTest`, `DealSchemaMigrationTest`, `DealAssignEndpointTest`,
      `DealWriteEndpointTest`, `DealStatusEndpointTest`, `DealApprovalEndpointTest`,
      `RecomputeStaleCustomerStatusesTest`, `FilesMigrationTest` (twice) and
      `SupplierQuotationSchemaMigrationTest` — the last two written to the same convention rather
      than against it, because a lone exception would have hidden the shared defect. Owed: one
      expression, once, that cannot collide (the whole uuid, or a per-test counter). Not done in 1.1
      — it spans four modules and a random red is exactly the defect that must be reproduced
      deliberately before it is called fixed

- [x] **An offer's currency is unreachable from the SPA, and that now costs a *field* rather than a
      column** — created 2026-09-02 by Point 1.1's `currency_id`, revealed as a display gap by
      Point 6.2, and **measured as a write blocker by Point 6.3**. ⚠️ **Point 6.2's own entry says
      "Registered below against Module 2's payload" and no such row existed** — `grep -n
      "CurrencyController::payload" CHECKLIST.md` returned exactly one line, 6.2's own. That is the
      second time this file has recorded a claim of registration with nothing behind it (see the
      Point 8.2 note above), and this row is the correction, not a new finding.
      **Two independent blockers, both read from the source in the session that wrote this:**
      1. `crm/app/Modules/Admin/Presentation/CurrencyController.php` `payload()` publishes `code`,
         `rounding_unit`, `rounding_enabled`, `is_base` — **no `id`**. `FxRateController::payload()`
         names its currencies by code too and its own `id` is the rate's. `grep -n "currenc"
         routes/api.php` finds exactly two currency routes, so there is no third place to ask.
      2. `routes/api.php:288-291` puts `GET /currencies` behind `permission:admin.system_settings`,
         which `PermissionMatrix.php:481-483` grants to the **Super Admin alone** — while
         `supplier_quotation.create` (`:332-338`) is the Manager, Team Leader, Outdoor Sales, Indoor
         Sales and Procurement. So even a payload carrying an `id` would 403 for every role that
         needs it.
      **The two columns are one pair**, not two fields: Point 1.1's
      `CHECK ((total_price IS NULL) = (currency_id IS NULL))` and
      `SaveSupplierQuotationRequest`'s mutual `required_with`. So the blocker costs §7.2's **total**
      as well as its currency. Owner's ruling 2026-09-05: Point 6.3 ships the rest of the header and
      states the ceiling in the dialog itself. Owed, and it is an owner decision before it is work:
      publish an `id` and a currency-read route the operational roles hold, **or** let Module 6
      accept a currency code. Both are cross-module and neither belongs inside a Module 6 point.
      *(2026-09-16, #145 — closed by fix-pass item F-01: `currency.view` for §3.6's roles + `id`
      on the payload, D-80 proposed; the dialog now carries the pair)*

- [ ] **Nothing in the API can be asked which files an entity has** — revealed 2026-09-05 by Module 6
      Point 6.5, and it is not a Module 6 gap: it is the shape of §17's surface. Measured in that
      session: `SupplierQuotationPayload::detail()` returns the nine header fields and `items`;
      `SupplierQuotationController` publishes `uploadDocument` and no document read; and
      `grep -c "/files" routes/api.php` is **1** — `GET /files/{file}/download`, which needs an id
      the caller must already hold. So a file attached to an offer is **write-only from the UI's
      point of view**: it is stored, scanned and audited, and then invisible. The same is true of
      `POST /deals/{id}/documents` (Module 5), so Module 9's PDF snapshots and Module 11's
      procurement documents will each hit it. Owner's ruling 2026-09-05: 6.5 ships a panel listing
      this dialog's own uploads, saying so in the panel itself. Owed, and it is a contract change
      (`OpenAPI §8`) before it is work: a `documents` array on the detail payload, or a
      `GET /{id}/documents` per parent. Not startable inside a frontend point

- [ ] **A fifth Vue form modal now carries the same 126-line shape** — created 2026-09-05 by Point
      6.3's `SupplierQuotationFormModal.vue`. `grep -rln "function applyServerErrors"
      resources/js` returns five files (`UserFormModal`, `CatalogItemFormModal`,
      `CustomerFormModal`, `SupplierFormModal`, and the new one), and `grep -rln "modal-scrim"`
      returns the same five — the `<style>` block is copied verbatim. `diff` of the two `<script>`
      bodies against `SupplierFormModal` is 126 differing lines out of 501, so the majority is
      shared: `blank()` / `values` / `opened` / `dirty`, `fieldId`, `testId`, `errorFor`,
      `applyServerErrors`, `requestClose`, `discard`, and the unsaved-change dialog. ⚠️ **This is
      waste the point created, and it was recorded rather than removed** on the same reasoning the
      `refusedWith()` row above gives: extracting a shell touches four components outside Module 6
      with no failing test behind it, which `CLAUDE.md`'s module-isolation rule and its ban on
      opportunistic cleanup inside an unrelated point both forbid. Recording it a second time is the
      argument that it is now large enough to schedule. Owed: one `FormModalShell` (or a composable
      for the error mapping plus a shared stylesheet), five call sites

- [ ] **Editing `docker-compose.yml` stales the php container's single-file mount** — *revealed while
      verifying the `spa-build` watch service, 2026-09-05.* The `php` service bind-mounts
      `./docker-compose.yml:/opt/crm/docker-compose.yml:ro` as a **single file**, so any host edit that
      replaces the inode — `git stash`/`checkout`, an editor's atomic save — leaves the in-container path
      pointing at the deleted inode. `QueueConfigurationTest` then fails **every** compose-reading case
      with `file_get_contents(...): No such file or directory`, which reads like a broken test rather than
      a stale mount. **Workaround, not a fix:** `docker compose up -d --force-recreate php` after editing
      the file. CI is unaffected — it checks the file out fresh. Closing it means bind-mounting the
      directory instead of the file (or dropping the mount and reading the repo copy), a Module 0 infra
      decision, not this point's.

- [ ] **`resources/js/services/identity.ts` is imported both statically and dynamically** — *revealed by
      the `vite build` in the `spa-build` service, 2026-09-05.* Rollup warns `INEFFECTIVE_DYNAMIC_IMPORT`:
      `stores/auth.ts` imports it dynamically while five components (`UserDetailsDrawer`, `UserFormModal`,
      `AccountSecurityView`, `RolesMatrixView`, `UsersView`) import it statically, so the dynamic import
      never splits into its own chunk and the lazy-load it implies does not happen. Harmless to
      correctness; a bundling inefficiency in Module 1 code. Closing it is a one-way choice — make it
      consistently static, or make the five call sites lazy too — inside Identity, not Module 6.

- [ ] **Six `Page<T>` interfaces and eight `URLSearchParams` list-query builders in
      `resources/js/services/*.ts`** — *revealed by Module 7 Point 6.1, 2026-09-13.* `grep -rn
      "interface Page<T>" crm/resources/js` → `suppliers`, `customers`, `identity`,
      `supplier-quotations`, `deals`, `catalog`, and now `api.ts`, where 6.1 put the one the
      quotations client imports because `collection()` already returns exactly that shape unnamed.
      `grep -rn "parameters.size === 0" crm/resources/js` → 8 copies of the same omit-empty-filter
      loop, 6.1's `queryString()` the eighth. Each copy is correct; the count is the defect. The fix
      is mechanical — every service imports `Page` from `@/api`, and one `listQuery(pairs)` helper
      replaces the loop — and belongs to its own point, not to a Module 7 screen.

- [ ] **Six list screens carry the same table boilerplate** (seven since 8·3.1's `ApprovalsView`) — *revealed by Module 7 Point 6.3,
      2026-09-13.* `grep -rl "function sortIndicator" crm/resources/js` → 6 (`CustomersView`,
      `SuppliersView`, `CatalogView`, `SupplierQuotationsView`, `DealsView`, `QuotationsView`): the
      same `load()`/`applyFilters()`/`sortBy()`/`goToPage()`/`ariaSort()`/`sortIndicator()`/`onDate()`
      set, the same prev/next `<nav>`, the same scoped `.table-frame/.table-head/.table-row/
      .form-field/.row-action` CSS, and — in three of them — the same best-effort name `Map` from a
      `perPage: 100` lookup. 6.3 copied it because that is the house pattern and extracting it is
      the first refactor, not a screen point. One `useServerList()` composable plus one
      `ListPagination.vue` would replace the six; its own point, after Module 7's screens.

- [ ] **The context bar overflows a 375px viewport by 36–46px, in both directions** — *revealed by
      Module 7 Point 6.3's mobile check, 2026-09-13; not created by it.* On `/deals` and
      `/quotations` alike, `document.documentElement.scrollWidth` is 411 (LTR) / 421 (RTL) against
      a 375 client width, and the overflowing element is `AppContextBar`'s sign-out button
      (`data-testid="sign-out"`, right edge 411). The page body scrolls sideways, which
      `Design System §4.3`'s "< 640px" row forbids for anything but tables. The shell belongs to no
      module (the row above on the sidebar says why); the fix is the context bar's — wrap or collapse
      its right cluster under 640px — and is not a Module 7 screen's to make.

- [ ] **Eleven schema tests carry a byte-identical `refusedWith()` helper** — *revealed by Module 7
      Point 1.1, 2026-09-07.* `grep -rln "private function refusedWith" crm/tests/` returns eleven
      files across Customers, Suppliers, Catalog, Admin, Deals, SupplierQuotations and now Quotations.
      Each is the same six lines: run a write, catch `QueryException`, return `errorInfo[0]`. The
      per-test `insert()` factories differ legitimately (different required columns), but the
      exception-state reader does not. Not fixed here — it touches ten test files in six modules, and
      widening a schema point into a cross-module test refactor is not the agent's call. Closing it is
      one trait in `tests/`, adopted as each suite is next edited.

- [ ] **`quotation_files` was never pre-created, and nothing will remind anyone** — *revealed by
      Module 7 Point 1.1, 2026-09-07.* `2026_08_22_000000_create_files_and_attachment_pivots` created
      four pivots against an owed list — deals, supplier quotations, purchase orders, reports — and
      `FilesMigrationTest` fails until each parent closes its foreign key. There is no quotation row in
      that list, so Module 9, which stores the generated PDF against the quotation (`D-71`, §17), must
      create the pivot itself and **no existing test will notice if it forgets**. Recorded now because
      the moment to notice it is while `quotations` is being built, not while a PDF is failing to save.

- [ ] **Six domain drafts carry the same `array_key_exists` allow-list filter** — *revealed by Module
      7 Point 1.7, 2026-09-07.* `grep -rl "array_key_exists" crm/app/Modules | grep Draft` returns
      `CatalogItemDraft`, `CustomerDraft`, `DealDraft`, `SupplierDraft`, `SupplierQuotationDraft` and
      now `QuotationDraft`. Each keeps the same loop for the same documented reason — `array_key_exists`
      and not `??`, so an explicit `null` is honoured as an erasure. Five copies pre-date this point
      and the sixth was written knowing that, because **the obvious fix is currently forbidden**:
      `deptrac.layers.yaml` gives `Domain` an empty ruleset — *"may depend on nothing"* — so a shared
      helper in `App\Support` is exactly what a draft may not reach. Closing it is therefore not a
      refactor but a layering decision (a `DomainSupport` layer, or a trait duplicated by
      construction), and that decision is the owner's, not a point's.

- [ ] **`CustomerStatusDerivation`'s docblock says Module 7 does not exist** — *revealed by Module 7
      Point 1.7, 2026-09-07.* `crm/app/Modules/Deals/Domain/CustomerStatus/CustomerStatusDerivation.php:41`
      reads *"needs Module 7, which does not exist yet (`app/Modules/Quotations` is …)"*. As of this
      point the directory holds a domain layer, so the sentence is false. It is one comment in Module
      5 and this point owns no file there; the accurate replacement also depends on what Step 3
      actually publishes, so it is better written by the point that makes the claim true than by the
      one that makes it false.

- [ ] **`D-68`'s money scale is now stated in three places** — *created knowingly by Module 7 Point
      2.1, 2026-09-07.* `grep -rn "SCALE = 6" crm/app` returns `Precision::MONEY_SCALE`,
      `RoundedTotal::SCALE` and now `PricedLine::SCALE`. The third copy was unavoidable under the
      layering the owner approved on 2026-09-07: a `Domain` class may not reach
      `App\Support\Database\Precision` (`deptrac.layers.yaml`'s empty ruleset) and Quotations may
      not reach `Admin\Domain\Money` without the `AdminContract` crossing that decision declined to
      make. Each copy is pinned to `Precision` by a test, which is the drift guard `RoundedTotal`
      already documented — so this is a stated ceiling rather than a defect. It is recorded because
      **it grows**: every further pure-Domain money module adds a fourth. Its root cause is the one
      the drafts' allow-list debt above already names, and one `DomainSupport` layer would close
      both at once.

- [x] **The quotation-create screen's "add a new supplier item" button is ruled, not built** — *owner's
      ruling 2026-09-11, recorded by Module 7 Point 3.3.* A quotation line always references a
      `supplier_quotation_item_id`; the screen carries a button atop the supplier-item list that jumps
      to adding a new supplier item and returns. Step 3 is the API, so this belongs to the
      quotation-create **screen** (a later Module 7 frontend point) and is parked here so the ruling
      is not lost between the point that heard it and the point that draws it. *(2026-09-14, #127 —
      Point 6.6 mounts `SupplierQuotationFormModal` in the builder; the saved offer becomes the next
      block. Its `deal_id` is not pre-filled: the modal's props allow none.)*

- [ ] **A quotation line is unnamed on the wire** — *revealed by Module 7 Point 6.7, 2026-09-14.*
      `QuotationPayload::detail()` writes `items[].supplier_quotation_item_id`, `quantity`, prices and
      costs, and nothing that says *what* the line is: no product name, no catalog id, no
      `supplier_quotation_id` (`quotation_items` has no such column; the offer is reachable only
      through the item). So 6.5 lists lines by number and 6.7 edits them by number. One field on the
      detail — the catalog label through `SupplierItemPricingInterface`'s join, which already touches
      `supplier_quotation_items` — names the line on both screens. Not built here: a cross-module
      contract widening is its own point.

- [ ] **Three controllers carry a byte-identical `heldScopes()`; nine carry `actorId()`** — *created
      knowingly by Module 7 Point 3.4, 2026-09-12.* `grep -rl 'private static function heldScopes'
      crm/app` → `CustomerController`, `DealController`, `QuotationController`; `actorId()` → nine.
      Each `heldScopes()` is the same read of `PermissionDecision` off the request attribute with the
      same "the route lost its middleware" throw. Point 3.4 copied rather than extracted because a
      shared `app/Support/Http` helper is an edit to two other modules' controllers, outside a
      one-route point's approved scope. The same point's waste audit (the `waste-auditor` agent)
      also counted `userWith()`/`bearerFor()` at **35 / 28** (the Module 6 row above, up from 33 / 26),
      a **third** byte-identical `supplierLine()` test fixture, and a second occurrence of
      `RecordFxRateRequest`'s `DB-07` regex triple in `CreateQuotationRequest::decimal()` — two is not
      yet a helper; the third Form Request that needs a decimal string moves it to `app/Support/Http/`.
      Closing the controller copies is one small class there, adopted in three files — its own point,
      or the first Presentation point that touches all three.

- [ ] **Six `*NotFound` renderers in `ApiExceptionRenderer` are byte-identical bodies** — *revealed by
      Module 7 Point 3.5, 2026-09-12.* `customerNotFound`, `supplierNotFound`, `supplierQuotationNotFound`,
      `catalogItemNotFound`, `dealNotFound` and now `quotationNotFound` each call
      `ApiEnvelope::error($request, 404, 'resource_not_found', __($e->messageKey()))` and differ only in
      the parameter type; four of the six docblocks already say "Identical in shape to". Point 3.5 added
      the sixth rather than extracting, because the extraction is a `NotFound` interface in
      `app/Support` adopted by five other modules' exceptions — outside a one-route point. The fix is
      that one interface plus one renderer; `bootstrap/app.php`'s six `render()` closures collapse with it.
      Also counted by the same audit: `supplierLine()` test fixture at **four** copies (was three) and
      `userWith()`/`bearerFor()` at **36 / 29** — the Module 6 row above continues to hold them.
      Point 3.6 (2026-09-12) recounted: `supplierLine()` **five**, `userWith()`/`bearerFor()` **37 / 30**.

- [ ] **`idempotency_keys` never expires** — *created knowingly by Module 7 Point 3.7, 2026-09-12.*
      `OpenAPI §9.1` persists a key "for the defined retention period", and no `D-xx`, `J-xx` or setting
      defines one — `J-01`…`J-15` hold no purge. Point 3.7 keeps `created_at` and invents no constant;
      every row stays until an owner decision names the period, and the sweep is then one scheduled job
      on `maintenance` (`ST-05`'s idempotent-with-retry shape) plus one setting. **Owner question.** Also
      recorded here rather than built: §9.1 names deals, supplier quotations, purchase orders and reports
      beside quotations, and each of their POSTs still carries a "No `Idempotency-Key`" comment in
      `routes/api.php` — the alias is one word per route now, but §9.2's "through a documented contract
      update" is the rule, and each is its owning module's point, not this one's.

- [ ] **`ShowQuotation::one()` is a third copy of the quotation scope check** — *revealed by Module 7
      Point 4.2, 2026-09-12.* 4.2's waste audit found `UpdateQuotation` and the new `SubmitQuotation`
      sharing an 18-line preamble (scope → `If-Match` → stale token → re-read); that copy was created by
      the point and was extracted into `QuotationWriteAccess` inside it. The audit also found the
      **read** side — `ShowQuotation::one()` — carrying the same scope/404 decision as three separate
      `if`s rather than the combined condition. It is pre-existing (Point 3.5) and takes no `If-Match`,
      so `open()` does not fit it as written; owed: one `QuotationRowScope`-applying reader both
      `ShowQuotation` and `QuotationWriteAccess` call, when Step 5's set-based owner seam
      (`dealIdsOwnedBy()`) is built — that point touches the same lines anyway.

- [ ] **`EloquentQuotationDirectory`'s register comment in `AuditEnforcementTest` names only
      `CreateQuotation`** — *revealed by Module 7 Point 4.2, 2026-09-12.* The disposition is still
      correct (the class's DML is audited by its callers), but the comment stopped listing the callers
      at 3.3: `UpdateQuotation` (3.6) and `SubmitQuotation` (4.2) audit through the same rows and are not
      named. Cosmetic; one comment edit in a test file, owed to whichever point next touches that
      register.

- [ ] **Two response shapes for `OpenAPI §6.2`'s `group_by`** — *revealed by Module 7 Point 5.5,
      2026-09-13.* Module 4's `GET /catalog-items?group_by=` keeps `data` the flat paginated list and
      expresses the group as ordering (`CatalogItemListCriteria`'s docblock argues a nested body
      makes `per_page` count something the caller never asked about). Module 7's
      `GET /quotations?group_by=` — the shape the owner approved on #110 — nests:
      `data = [{key, label, count, items}]`, pagination still counting rows. Both are defensible
      readings of a clause that names no shape; two of them in one API is the defect. **Owner
      decision:** pick one and record it in `OpenAPI §6.2` (or as a `D-xx`), then bring the other
      resource to it in a point of its own. Nothing to change until then.
- [ ] **The quotation feature-test fixture block exists in 12 files** — *revealed by Module 8 Point 2.3,
      2026-09-15.* `currency` / `customer` / `deal` / `supplierLine` / `userWith` / `bearerFor` (and
      Module 8's `pending()`) are copied verbatim into every endpoint test under
      `tests/Feature/Quotations` — `grep -l "private function supplierLine"` counts 12, Point 2.3's
      `QuotationBadgesEndpointTest` being the latest. Each point copied rather than extracted because a
      shared trait is a change to eleven files nobody was reviewing. **Fix, one point of its own:** a
      `tests/Feature/Quotations/Support/QuotationFixtures` trait, then delete the copies; no behaviour
      changes, so the suite count is the proof.
- [ ] **The "read the detail's etag → write → 409 is the reload banner" flow exists three times** —
      *revealed by Module 8 Point 3.1, 2026-09-15.* `grep -rn "error.code === 'concurrency_conflict'"
      crm/resources/js` → `QuotationDetailView.vue`, `QuotationBuilderView.vue`, `ApprovalsView.vue`,
      each with its own `act()`/`busy`/`conflict`/`actionError` trio. 3.1 copied 6.5's because the
      first two were already copies and the extraction is a refactor, not a screen point. **Fix, one
      point:** a `useEtagWrite()` composable returning `{busy, conflict, error, act}`; the three pages
      shrink by the same twenty lines. Belongs with the `useServerList()` row above.
- [ ] **`RequestIdTest` "a rejected correlation id never appears" is a hex-collision flake** —
      *revealed by Module 8 Point 3.1's CI run 34993785991, 2026-09-15.* Data set `'a trailing newline'`
      is `"abc\n"`, the needle becomes `abc`, and the response's server-generated `request_id` was
      `…f55abc282a0b` — a UUID contains the needle about once in 4 000 runs. The test is Module 0's
      (`c5deb0d`), untouched here; re-run passed. **Fix, one line:** a needle no hex string can contain
      (`"xyz\n"`), keeping the `D`-modifier case the comment explains.


---
- [ ] **`SupplierItemPrice::$consumedQuantity` is written and never read** — *revealed by F-05
      Point 1.4's waste audit, 2026-09-20.* Point 1.2 added `consumedQuantity` and `availableQuantity`
      to Module 6's pricing contract as its approved line said; Module 7 reads only `availableQuantity`
      (`PriceQuotation.php`, the §5.6 comparison), and the supplier-quotation view reads the three
      numbers through `SupplierQuotationLine`, not this DTO. 1.4 removed `recordedQuantity` for the same
      reason because 1.4 itself orphaned it; `consumedQuantity` was orphaned at birth, so it is
      registered rather than swept. Remove it in whichever F-05 point next touches the contract, unless
      Module 10's caller turns out to read it.
- [ ] **The deal detail still names its customer through a scoped single read** — *revealed by F-07
      Point 1.5, 2026-09-21; not fixed there, by the owner's list-only ruling.* `DealDetailView.vue:164`
      calls `readCustomer(deal.customer_id)` (`GET /customers/{id}`, which applies the caller's
      `customer.view` scope — `CustomerController::heldScopes()`) and shows the id when it fails. That is
      `D-83`'s mechanism 4 on one screen: an `own`-scoped caller reading their own deal whose customer
      another user owns sees the id, not the name. Whether an **archived** customer also fails there was
      not checked. Its comment "exactly as the list's own name lookup is" went stale when 1.5 removed
      that lookup. The fix is the same port on `DealPayload::of()` for `GET /deals/{id}` — a decision for
      the owner, because it widens `D-83` past the list.
- [ ] **The supplier-quotations screen reads only the first 100 suppliers** — *revealed by F-08 Point
      1.1, 2026-09-21; not fixed there, because the owner's report named customer dropdowns only.*
      One `listSuppliers({ perPage: 100 })` (`SupplierQuotationsView.vue:146`,
      `SupplierListCriteria::MAX_PER_PAGE`) feeds three things: the rows' supplier **names** through a
      client-side join (`supplierName()`, `:98-110` — the shape `D-83` removed for customers), the
      supplier **filter** (`:263`), and the form's supplier **picker** (`SupplierQuotationFormModal.vue:613`).
      With 2 suppliers in dev data none of it can be seen yet. Two fixes, not one: the filter and the
      picker take `CustomerPicker` made generic, when a second case is ordered; the row names need a
      supplier names port, as `D-83` gave customers.
- [ ] **The `php` container mounts two single files, which go stale when git rewrites them** — *revealed
      by F-08 Point 1.2, 2026-09-21: 50 of 2900 backend tests failed with `file(/opt/crm/docs/CRM_Documentation_EN.md):
      Failed to open stream`.* `docker-compose.yml:195` mounts `./docs/CRM_Documentation_EN.md` and `:190`
      mounts `./docker-compose.yml`, each as a single-file bind mount. A single-file mount follows the
      **inode**, and git writes a changed file as a new one — so after a merge or a branch switch that
      touches the file, the container keeps the deleted inode (`stat`: 0 links) and every test that reads the
      master documentation fails. Nothing in the code was wrong; `docker compose restart php` re-attached
      it and the same classes passed. The fix is to mount the containing directory (`./docs`) instead of
      the file — a change to the dev environment, so it is the owner's call, not this point's.

## Agent guide revisions — owner-directed

Changes to `CLAUDE.md` and `AGENTS.md` themselves. They belong to no module, and they are recorded
because `CLAUDE.md` requires every point to update this file — the earlier rule change that added
the waste audit to both guides (PR #63) left no row here, which is how this section came to be
missing in the first place.

- [x] **G-01** `Current State` in **both guides** replaced with what is true. *(2026-09-02, owner's
      request.)* Both files opened with **"No application code yet"** while `main` carried 13 modules
      and 1981 passing backend tests, and both say in their own text *"Update this section whenever
      it stops being true"* — `AGENTS.md` adds that it *"must stay identical in meaning to the same
      section in `CLAUDE.md`"*. So the staleness was not a cosmetic lag: it was the one instruction
      every agent reads first, saying the opposite of the repository it describes.
      **Every figure is measured, none recalled.** On `main` at `862c0c0`: `ls crm/app/Modules/` → 13
      modules, `ls crm/database/migrations/*.php | wc -l` → 22, `php artisan test` → **1981 passed
      (11958 assertions)**, `npm run test:unit` → **582 passed (34 files)**. Module progress is
      counted from this file's own checkboxes by `awk`, not estimated: 0 → 56/56 · 1 → 38 closed, 2
      open · 2 → 16/3 · 3 → 22/6 · 4 → 26/1 · 5 → 15/5 · 6 → 0/5.
      **One claim was not stale but wrong.** The old bullet read *"OD-03 remains unresolved and still
      blocks Module 0"*, while the same file's Start Conditions said `D-66` had already lifted that
      block and Module 0 is 56/56. The two sentences contradicted each other inside one file; the new
      bullet keeps `OD-03` open against the server, `P-02` and the deployment-debt register, and
      drops the module claim.
      **Checks: none of the six gates applies, and that is read from the workflow rather than
      assumed.** `.github/workflows/php-image.yml` filters `push` **and** `pull_request` on
      `docker/php/**`, `crm/**` and itself, so a change to root `*.md` triggers **no CI run at all** —
      its absence is correct, not a pending result. `grep -rn` over `crm/tests` for a runtime read of
      either guide (`file_get_contents`, `base_path`) returns **nothing**: the ten test files that
      mention `CLAUDE.md` cite it in comments. No suite can observe this change.
      **The sync check was verified by breaking it.** The two sections are extracted with `awk` and
      compared: identical, `91adc06a…`. ⚠️ The **first** version of that check was vacuous — its
      `sed` range ran past the section and its `shasum` came back `e3b0c442…b855`, the hash of an
      **empty file**, so it would have reported "identical" for any two inputs including none. Caught
      by reading the hash instead of the word PASS. The corrected check was then proved: changing
      `22 migrations` to `21` in `AGENTS.md` alone made it report **DIVERGENCE DETECTED**, and the
      file was restored and re-compared identical.
      **Problems found: two.** (1) The vacuous verifier above. (2) A claim in the report that preceded
      this point — that the stale `Current State` was *"item 27 on the debt register"* — was wrong:
      `grep` finds no such row in this file. It was an item in a private session handoff, not here.
      Corrected to the owner in the same message, and nothing was struck from the register.
      **Waste audit.** *Dead code:* none added — this point adds prose only, and both guides render
      it. *Duplicate logic:* the section is deliberately duplicated across the two files, which is
      the documented requirement rather than waste; the `awk` comparison is the guard on it.
      *Unused components:* this section is new and holds one entry — created because there was
      nowhere to record a guide change, which PR #63 demonstrated by recording nothing. *Unnecessary
      complexity:* none; the alternative considered was filing this under *Shell revisions*, rejected
      because that section's own text scopes it to the shell.
      **Not covered:** the sync between the two guides is checked **by hand in this point only** —
      nothing in CI or the suites will catch the next divergence, and the wording outside
      `Current State` still differs by design, so a byte-comparison of the whole files would be the
      wrong guard. `README.md` was **not** examined and may carry its own stale claims. PR #63's
      missing row is named above but **not backfilled** — that is somebody's point, not this one's.
- [x] **G-02** `CHECKLIST.md` cut from 9,436 to ~1,570 lines: Modules 0–6 moved verbatim to `checklist/module-0N.md`, stubs keep counts + open boxes; new *"What a point writes into `CHECKLIST.md`"* rule in **both guides** — one line per box, narrative stays in the PR. *(2026-09-12, owner's request, PR #98.)*


- [x] **G-03** An `Agent skills` section added to **both guides**, pointing at two files under
      `docs/agents/` that describe how this repository actually tracks work. *(2026-09-10, owner's
      request; opened as PR #93 and numbered `G-02` before Yousef's archiving work took that number
      on 2026-09-12 in PR #98. Renumbered on merge rather than leaving two `G-02` rows — the
      register is only useful if an identifier names one revision.)*
      **What arrived, and why none of it survived unedited.** Three files were written 2026-09-01 by
      a skill installer and sat uncommitted in the working tree for nine days. All three described a
      repository other than this one, and each claim below is **measured, not recalled**:
      `domain.md` sent every agent to `CONTEXT.md` and `docs/adr/` before exploring — `ls CONTEXT.md`
      → no such file, `ls docs/adr` → no such directory, and `docs/` holds the seven `*_EN.md`
      masters; this project's decision record is `D-xx` in `docs/CRM_Documentation_EN.md` §2, so the
      file named a **second decision-record convention beside `D-xx`**. `issue-tracker.md` opened
      *"Issues and specs for this repo live as GitHub issues"* — `gh issue list --state all` returns
      **nothing; this repository has never had an issue**. `triage-labels.md` called its five labels
      *"the actual label strings used in this repo's issue tracker"* and cited `mattpocock/skills` as
      its source — `gh label list` returns the **nine GitHub defaults**, of which exactly one
      (`wontfix`) is among the five, and it exists only because GitHub creates it with every
      repository. Registering any of the three as written would have pointed **both developers'**
      agents at a tracker, a label set and a decision record that do not exist — and `domain.md`'s
      own text says to "proceed silently" when its paths are absent, so nothing would have reported
      the mismatch.
      **What was done instead.** `domain.md` was dropped (retained outside the repository, not
      destroyed). The other two were **rewritten against the repository as measured** and renamed to
      match what they now say: `issue-tracker.md` → `work-tracker.md`, which states that
      `CHECKLIST.md` is the tracker, sets out `Module → Step → Point`, the `[ ]`/`[~]`/`[x]` legend,
      where each register lives, that `D-xx`/`OD-xx` live in the master documentation rather than in
      ADRs, and the `gh pr` commands that are real here — including the base-ref check, because a PR
      can be open, green and `MERGEABLE` while based on another feature branch, which is how five
      PRs merged into their own stacked bases on 2026-09-01. `triage-labels.md` → `triage.md`, which
      says plainly that **there is no label workflow**, that the four canonical triage labels do not
      exist and must not be created or applied on a skill's say-so, and that triage here is the
      states `CHECKLIST.md` already writes — `⏸ deferred`, `⚠️`, *owner decision* — closing on the
      rule that an unbacked requirement fails closed and is recorded, with `CustomerRowScope` and
      `DealRowScope` as the worked example.
      **Checks: none of the six gates applies, established the way `G-01` established it, not
      assumed.** `.github/workflows/php-image.yml` filters `push` and `pull_request` on
      `docker/php/**`, `crm/**` and itself. Every path in this change — `AGENTS.md`, `CLAUDE.md`,
      `docs/agents/**` — is outside all three, so **no CI run is triggered**; its absence is correct,
      not a pending result. No suite reads either guide at runtime (`G-01` established this by
      `grep`; nothing since has added such a read).
      **The two guides' new sections are byte-identical**, extracted with `awk` and compared
      (`b412a595`, 15 lines each) — required because `AGENTS.md` is the tool-neutral twin and a rule
      differing between them is a defect by both files' own text. Verified **after** the rewrite and
      the renames rather than before, and proved non-vacuous by the line count: `G-01`'s trap was a
      range that ran past its section and reported two empty extractions as identical.
      **Problems found: three**, all of them the installed files themselves — the ADR convention, the
      non-existent issue tracker and the non-existent labels, each measured above. Two were fixed by
      rewriting, one by removal. **The first version of this change committed the second and third
      unfixed**, with the defect recorded in this row but the false files still registered; that was
      caught by reading the two files properly instead of trusting the summary line that named only
      `domain.md`.
      **Waste audit.** *Dead code:* none — prose only. *Duplicate logic:* the guide section is
      duplicated across the two files, which is the documented requirement, not waste; the real
      duplication was `domain.md`'s parallel decision record, and it is gone. *Unused components:*
      `docs/agents/` had no reader until this point registered it; the two files it now holds are
      each pointed at from both guides. *Unnecessary complexity:* none added — the change is smaller
      than what arrived, by one whole file.
      **Not covered:** nothing in CI or the suites verifies that these two files stay true as the
      repository moves. Both carry measured claims — "never had an issue", "nine default labels" —
      that a single `gh issue create` would falsify silently, and the next divergence between the two
      guides will be caught by hand or not at all. `README.md` was not examined, as in `G-01`.

## Fix pass — owner-directed, 2026-09-16

Problems the owner found in closed modules, after Module 8 closed. Each is its own `fix/…` branch
off `main`: a failing test first, the smallest fix, the six gates, the browser when a screen changed,
a seven-part report, and the owner's merge. One per turn; the list is the owner's, not the agent's.

- [x] **F-01** A supplier offer's currency is unreachable from the SPA (Module 6 ← Module 2). The
      owner's two screenshots were one chain: the offer dialog said the currency list was «غير متاحة
      لهذا الدور», so `SQ-2026-0004` carried three priced lines and no currency, and the quotation
      builder refused the line with `supplier_price_missing` — Module 7's block was correct
      (§5.6); the dead end was Module 6's. Owner's ruling: a currency-read route the operational
      roles hold + `id` on the payload, not a code in Module 6. Done as `currency.view` (D-80,
      proposed) on `GET /currencies` alone, `id` in `CurrencyController::payload()`, and the pair
      of inputs in `SupplierQuotationFormModal.vue`. **After merging, run once:**
      `php artisan db:seed --class=RolePermissionSeeder` — the grant is configuration, not a
      migration, and nothing on deploy runs the seeder. *(2026-09-16, #145 — closes the Module 6
      debt row above)*
- [x] **F-02** The quotation builder's currency is typed as a three-letter code (Module 7). Since
      D-80 every `quotation.create` role holds `currency.view`, so the builder reads
      `GET /currencies` best-effort in `load()` and draws a `<select>` of codes; when the list
      cannot be read the typed input stays, unchanged, so a refused lookup leaves a working form
      rather than an empty select that can only produce a 422. Same testid either way. The
      quotations **filter** still types its code — not asked for. *(2026-09-16, #146)*
- [x] **F-03** After «الكمية المطلوبة تتجاوز ما سجّله المورّد» the builder could not be edited until
      the page was left and reopened (Module 7). Not a crash: Q3 froze the form after a save that
      carried `quantity_exceeds_recorded` — the draft **was** saved. §5.6 / Design System §7.2 say
      "warn without blocking", and the owner's ruling applies that to the form after the save too.
      Now the form stays editable and the next save is a `PATCH` on the draft just created.
      **Found on the way:** `POST /quotations` answers `QuotationPayload::of()` — `{id, code}`, no
      `etag` — while the SPA typed it as a detail, so the first `PATCH` after a warned create went
      out with `If-Match: ""` → `400 if_match_required` (seen in the browser, hidden by a fixture
      that had invented an etag). The SPA now reads the draft once for its token and the create's
      type tells the truth. Edit-and-approve keeps the freeze: the quotation is `approved`.
      Candidate, not done: an `etag` on the 201 itself. *(2026-09-16, #147)*
- [x] **F-04** The supplier-recorded maximum of a builder line lived only in the muted text under the
      product name; the owner wanted it inside the quantity box as a hint (Module 7). One attribute:
      `:placeholder="item.quantity"` — the server's decimal string verbatim (`DB-07`), faint until the
      staff member types, gone while they do. The muted «سعر المورّد … الكمية المسجَّلة» line, its key
      and its testid are unchanged; §5.6's warning after the save is unchanged. *(2026-09-16, #149)*
- [ ] **F-05** A supplier line's recorded quantity is a balance the accepted quotation draws down
      (Module 6 ← 7 ← 10). Owner's ruling 2026-09-16 on Q0–Q7, recorded as `D-81` (proposed).
      Nothing in `docs/` supported it before D-81: §7.2 names `quantity`, §5.6 warns on it, no
      line consumed it. Split into points because it crosses three modules; each is its own
      `fix/…` branch, one per turn, seven-part report, owner's merge.

      ### F-05 point list — published 2026-09-16, approved by merging #150

      - [x] **1.1** `D-81` in §2 (proposed) + this list; `D-80` flipped to *approved by merging
            #145* with the same edit, as the handoff asked. Docs only — no CI runs on `docs/` or
            `CHECKLIST.md`. *(2026-09-16, #150)*
      - [x] **1.2** Migration: `supplier_quotation_items.consumed_quantity NUMERIC(14,4) NOT NULL
            DEFAULT 0` (`D-68`), `down()` drops it; `SupplierItemPrice` gains `consumedQuantity`
            and `availableQuantity`; `GET /supplier-quotations/{id}` publishes `consumed_quantity`
            and `available_quantity` per line. RED: migration up/down test + payload test.
            `permission-matrix-auditor` (new fields on an existing route). *(2026-09-16, #151 —
            available is `quantity - consumed_quantity` in SQL, never stored; OpenAPI has no
            per-field rows to extend, §8.1 governs)*
      - [x] **1.3** `SupplierItemQuantityInterface::consume(itemId, quantity, idempotencyKey)` in
            `SupplierQuotations/Domain/Contracts`, implemented in `Infrastructure` as one atomic
            `UPDATE … SET consumed_quantity = consumed_quantity + ?` guarded by an idempotency
            record. RED: two parallel calls with one key consume once; two keys consume twice.
            No caller yet — the caller is Module 10's `accepted` transition (D-81); say so in the
            interface's docblock so the waste audit reads it as deferred, not dead. *(2026-09-16,
            #152 — guard table `supplier_quotation_item_consumptions`, `ON CONFLICT DO NOTHING
            RETURNING`; returns the new balance via `UPDATE … RETURNING`; Module 10's gate is
            §3.5 "record customer response")*
      - [x] **1.4** `PriceQuotation`'s §5.6 warning compares the requested quantity against
            **available**, not recorded. RED: a line whose quantity is ≤ recorded and > available
            warns. `pricing-invariant-reviewer`. *(2026-09-20, #154 — one operand; the warning's
            sentence reworded ar/en, its wire code `quantity_exceeds_recorded` kept (OpenAPI §5.1);
            `SupplierItemPrice::$recordedQuantity` removed, it had no reader left)*
      - [ ] **1.5** *Deferred to Module 10:* the `sent → accepted` transition calls 1.3 once per
            line inside its transaction and carries old/new `consumed_quantity` in its audit entry.
            Listed here so the dependency is visible; built as a Module 10 point, not an F-05 one.
      - [x] **1.6** Screens: the builder's quantity placeholder (F-04) and the muted line show
            available; the supplier-quotation detail shows recorded · consumed · available. AR/EN ×
            desktop/375 px via `rtl-ui-verifier`. RED: vitest on both views. *(2026-09-21, #155 —
            the builder's muted line shows price · available (owner's choice, two figures); there
            is no read-only offer detail, so recorded · consumed · available sits under each loaded
            line of the edit modal, never on a new line; `SupplierQuotationLine` gained the two keys
            #151 published; 860 vitest)*
      - [x] **1.7** Manual test list for F-05 in Arabic, one line per check, roles named, including
            what 1.5 leaves untestable until Module 10. *(2026-09-21, #156 — 29 checks below, dev
            figures 66 / 30 / 36 read from the database; 1.5's trigger, audit entry and no-restore
            rule named as untestable; F-05 closes here, and stays in this file — a fix-pass item,
            not a module)*

      #### قائمة الاختبار اليدوي — F-05 *(النقطة 1.7، 2026-09-21)*

      > **F-05 إصلاح لا وحدة**، فالقائمة تغطّي بنود `D-81` وحدها: المتاح = المسجَّل − المستهلَك ويُحسب في
      > الخادم (1.2)؛ عقد `consume()` ذو المفتاح الواحد (1.3)؛ تحذير §5.6 يقارن بالمتاح لا بالمسجَّل (1.4)؛
      > الشاشتان تعرضان المتاح والأرقام الثلاثة (1.6). **المُشغِّل الحقيقي — انتقال `sent → accepted` — هو
      > نقطة الوحدة 10 (1.5)** ولا يوجد اليوم أي مستدعٍ لـ `consume()` داخل المنتج؛ انظر «لا يمكن اختباره بعد».
      >
      > ⚠️ **بيانات التطوير كما تركها عرض 2026-09-21:** في `SQ-2026-0004` البند ذو السعر 5000 مسجَّله 66.0000
      > ومستهلَكه 30.0000 فمتاحه **36.0000**؛ البندان الآخران مستهلَكهما 0 (50/50 و1000/1000). لو تغيّرت
      > الأرقام عدّل المتوقَّع أدناه من قاعدة البيانات لا من الذاكرة. الحسابات: `<role>@example.test`
      > (`manager`، `team.leader`، `outdoor.sales`، `indoor.sales`، `procurement`، `ceo`، `outdoor.supervisor`).

      **أ) شاشة عروض المورّدين — `/supplier-quotations` ثم «تعديل» على `SQ-2026-0004`** *(D-81: «أدوار عرض
      عروض المورّدين ترى الأرقام الثلاثة»؛ §3.6)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 1 | مدير | افتح العرض للتعديل ⇒ تحت بند الـ 5000 سطر باهت «المسجَّل 66.0000 · المستهلَك 30.0000 · المتاح 36.0000»، وتحت كلّ من البندين الآخرين «… المستهلَك 0.0000 · المتاح» يساوي المسجَّل | 1.6 · D-81 |
      | 2 | مدير | قارن حقل «الكمية» فوق السطر بالسطر نفسه ⇒ الحقل يحمل 66.0000 (عرض المورّد الأصلي) والسطر يحمل الثلاثة؛ لا يُمسّ المسجَّل بالاستهلاك | §7.2 · D-81 |
      | 3 | مدير | غيّر «الكمية» إلى 70 **دون حفظ** ⇒ يبقى السطر «المسجَّل 66.0000 …» كما فتح (لقطة من الخادم لا من الحقل)، ثم «إلغاء» | 1.6 |
      | 4 | مدير | اضغط «إلغاء» ثم «عرض سعر مورّد جديد» ثم «+ إضافة بند» ⇒ **لا** سطر أرقام تحت البند الجديد إطلاقًا | 1.6 |
      | 5 | مدير | افتح للتعديل عرضًا بلا بنود (`SQ-2026-0002`) ⇒ «لا توجد بنود بعد.» ولا سطر أرقام | فارغ |
      | 6 | مدير | افتح عرضًا للتعديل وراقب لحظة الفتح ⇒ «جارٍ تحميل بنود العرض…» ثم البنود بأسطرها | تحميل |
      | 7 | مدير | أوقف الشبكة (DevTools → Offline) ثم افتح عرضًا للتعديل ⇒ «تعذّرت قراءة بنود العرض، فلن تُعرض ولن يمسّها هذا الحفظ.» ولا سطر أرقام | خطأ |
      | 8 | قائد فريق · مبيعات خارجية · مبيعات داخلية · مشتريات | كرّر 1 و4 ⇒ النتيجة نفسها؛ الأدوار الخمسة تعدّل وترى الثلاثة | §3.6 |
      | 9 | الرئيس التنفيذي | افتح `/supplier-quotations` ⇒ القائمة تظهر **ولا** زرّ «تعديل» في أيّ صف ولا «عرض سعر مورّد جديد»؛ فلا يرى الأرقام الثلاثة من هذه الشاشة (سقف معلن: لا شاشة عرض للقراءة فقط) | §3.6 · 1.6 |
      | 10 | مشرف الخارجي | افتح `/supplier-quotations` مباشرة ⇒ صفحة الرفض `/403` لا قائمة فارغة | §3.6 · SEC-07 |
      | 11 | مدير | بدّل إلى EN ⇒ «Recorded 66.0000 · consumed 30.0000 · available 36.0000» والاتجاه LTR | EN |
      | 12 | مدير | ضيّق النافذة إلى 375 بكسل بالعربية ثم بالإنجليزية ⇒ سطر الأرقام ينزل صفًّا كاملًا تحت الحقول ولا يضغطها، والأرقام لاتينية الاتجاه داخل النصّ العربي | 375 · RTL |

      **ب) منشئ عرض السعر — `/deals` ثم `DL-2026-0001` ثم «عرض سعر جديد»، العملة EGP، «+ إضافة مورّد»، اختر
      `SQ-2026-0004`** *(D-81: «تحذير §5.6 وتلميح F-04 يقارنان بالمتاح لا بالمسجَّل»؛ §3.5)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 13 | مدير | اختر العرض ⇒ تحت بند الـ 5000 سطر باهت «سعر المورّد 5000.000000 · الكمية المتاحة 36.0000» — **36 لا 66** — وتحت الآخرين 50.0000 و1000.0000 | 1.6 · D-81 |
      | 14 | مدير | انظر حقل «الكمية» الفارغ ⇒ نصّه الباهت 36.0000 بالحروف نفسها التي أرسلها الخادم، يختفي حين تكتب | F-04 · DB-07 |
      | 15 | مدير | اكتب 36 في كمية بند الـ 5000 واحفظ ⇒ يُحفظ **بلا** تحذير | §5.6 · 1.4 |
      | 16 | مدير | افتح المسودّة، غيّر الكمية إلى 40 (≤ 66 المسجَّل، > 36 المتاح) واحفظ ⇒ يُحفظ **ومعه** تحذير أحمر تحت البند «الكمية المطلوبة تتجاوز المتاح من عرض المورّد.» — الحفظ لا يُمنع | §5.6 · 1.4 · D-81 «لا سقف» |
      | 17 | مدير | غيّر الكمية إلى 100 (> المسجَّل) واحفظ ⇒ التحذير نفسه؛ لا رسالة ثانية ولا منع | §5.6 |
      | 18 | مدير | امسح الكمية واترك الحقل فارغًا واحفظ ⇒ البند خارج العرض (F-04: الفارغ يعني «ليس على هذا العرض»)، لا تحذير | F-04 |
      | 19 | مدير | بدّل إلى EN وأعد 16 ⇒ "Supplier price 5000.000000 · available quantity 36.0000" والتحذير بالإنجليزية | EN |
      | 20 | مدير | 375 بكسل، عربية ثم إنجليزية ⇒ السطر الباهت يلتفّ دون تمرير أفقي إضافي (تجاوز الغلاف 36–46 بكسل مسجَّل في سجلّ الدين، ليس من هذه النقطة) | 375 · RTL |
      | 21 | مبيعات خارجية · مبيعات داخلية | كرّر 13–16 على صفقة تملكانها ⇒ النتيجة نفسها | §3.5 Own |
      | 22 | قائد فريق | كرّر 13–16 على صفقة في فريقه ⇒ النتيجة نفسها | §3.5 Team |
      | 23 | مشتريات · الرئيس التنفيذي | افتح `/quotations/new?deal=<معرّف DL-2026-0001>` مباشرة ⇒ `/403`؛ وفي صفحة الصفقة لا زرّ «عرض سعر جديد» | §3.5 · SEC-07 |

      **ج) الآلية نفسها — بلا شاشة، من داخل الحاوية** *(1.2 · 1.3: «مفتاح واحد يستهلك مرّة»)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 24 | مسؤول النظام | `SELECT quantity, consumed_quantity, quantity - consumed_quantity FROM supplier_quotation_items WHERE …` على البند ⇒ الفرق يساوي ما تعرضه الشاشتان حرفيًّا؛ لا عمود `available_quantity` في الجدول | 1.2 · D-81 |
      | 25 | مسؤول النظام | من `artisan tinker`: `consume(id, '10', 'k1')` مرّتين ⇒ الإرجاع متطابق، `consumed_quantity` زاد 10 مرّة واحدة، وفي `supplier_quotation_item_consumptions` صفّ واحد للمفتاح | 1.3 |
      | 26 | مسؤول النظام | `consume(id, '4', 'k2')` ⇒ يزيد 4 مجدّدًا وصفّ ثانٍ | 1.3 |
      | 27 | مسؤول النظام | أعد فتح الشاشتين (أ‑1 وب‑13) ⇒ الأرقام الجديدة نفسها، بلا إعادة بناء | 1.6 |
      | 28 | مسؤول النظام | `consume` بكمية تتجاوز المتاح ⇒ **يُقبل** ويصبح المتاح سالبًا (لا `CHECK`)؛ ثم الشاشتان تعرضان الرقم السالب كما هو | D-81 «لا سقف» |
      | 29 | مسؤول النظام | `php artisan migrate:rollback --step=2` ثم `migrate` ⇒ العمود والجدول يسقطان ويعودان؛ الصفوف المستهلَكة تعود إلى 0 (لا نسخ احتياطي للاستهلاك في `down()`) | DEV-03 |

      **لا يمكن اختباره بعد، ويجب أن تراه لا أن يُخفى:**
      - **1.5 — المُشغِّل:** لا شيء في المنتج يستدعي `consume()`. قبول عرض سعر عميل (`sent → accepted`) لا يغيّر
        اليوم أيّ رصيد؛ البنود 24–28 تُثبت الآلية يدويًّا فقط. يُبنى في الوحدة 10 (بوابة §3.5 «تسجيل ردّ
        العميل») ومعه: الاستدعاء مرّة لكلّ بند داخل معاملة الانتقال، مفتاح لكلّ بند عرض عميل، والقديم/الجديد
        من `consumed_quantity` في قيد التدقيق. لا سطر تدقيق يُختبر اليوم.
      - **الاستهلاك لا يُستردّ:** `D-81` يقول لا رجوع بعد `accepted`؛ لا يوجد ما يُختبر لأن لا انتقال يستهلك.
      - **`D-81` ما زال «مقترحًا»** في §2 حتى يقلبه المالك؛ القائمة تختبر ما بُني لا ما اعتُمد.
      - **رمز التحذير على السلك** `quantity_exceeds_recorded` يقول «المسجَّل» وجملته تقول «المتاح» — سقف معلن في
        1.4 (`OpenAPI §5.1`)؛ ليس عيبًا تراه الشاشة.

- [x] **F-06** Numbers are displayed at three decimal places (Modules 2 ← 6 ← 7 ← 8). Owner's ruling
      2026-09-21, recorded as `D-82` (proposed): storage stays `D-68`; the SPA shows money and
      quantities cut — truncated, not rounded — to three places, percentages and FX rates as stored;
      inputs send exactly what was typed; the customer PDF is Module 9's own decision (nothing exists
      yet to decide about — `app/Modules/Pdf` is empty on `main`, #118's DTOs carry plain strings).
      Nothing in `docs/` said what a person sees before D-82: Design System §6.3 says "format only
      for display" and every screen printed the stored string. Each point is its own `fix/…` branch,
      one per turn, seven-part report, owner's merge.

      ### F-06 point list — published 2026-09-21

      - [x] **1.1** `D-82` in §2 (proposed) + this list. Docs only — no CI runs on `docs/` or
            `CHECKLIST.md`. *(2026-09-21, #157 — the D-82 row pasted by the owner under a one-time
            authorization after the docs guard hook and the app's permission layer both refused the
            agent's write; `.claude/settings.json` untouched)*
      - [x] **1.2** One formatter in `crm/resources/js` (search first: only `Ping.vue:87`'s latency
            `Intl.NumberFormat` exists, and it is not one — the `ar` locale would emit Arabic-Indic
            digits, §5 forbids), a string cut after the third decimal, never `Number()`. Applied to
            every displayed money and quantity figure — the Explore inventory of 2026-09-21: money
            17 sites (`QuotationDetailView` 11, `QuotationsView` 1, `ApprovalsView` 1,
            `QuotationBuilderView` 3, `SupplierQuotationsView` 1), quantity 6 (`QuotationDetailView`,
            the builder's muted line and placeholder, the offer editor's recorded · consumed ·
            available triple). Percent (4) and FX (1) sites and every `v-model` input untouched.
            RED first: `1000.000000` displays as `1000.000`; the two tests that assert the raw
            six-decimal string today (`QuotationBuilderView.spec.ts` ~295, `SupplierQuotationsView`'s
            "digit for digit") flip to the cut form on purpose. `rtl-ui-verifier` on the changed
            screens, `waste-auditor`; no pricing or permission agent (display only, no route).
            *(2026-09-21, #158 — 18 money + 6 quantity sites; the rounding-unit label is the 18th,
            one past the inventory on the owner's ruling, and `text()` truncating free text at the
            first period was the defect the point created and fixed)*
      - [x] **1.3** Manual test list for F-06 in Arabic — one check per changed screen, the input
            digit-for-digit vs the display cut at three, AR/EN × desktop/375 px; and what the PDF's
            places are not (Module 9's). *(2026-09-21, #159 — 41 checks below covering all 24
            `displayDecimals` call sites; figures read from the database, not recalled; the approvals
            screen has no pending row today so check 31 creates one and consumes a draft; F-06 closes
            here and stays in this file — a fix-pass item, not a module)*

      #### قائمة الاختبار اليدوي — F-06 *(النقطة 1.3، 2026-09-21)*

      > **F-06 إصلاح لا وحدة**، فالقائمة تغطّي `D-82` وحده: ما يراه الإنسان من المال والكميّات **مقصوص**
      > بعد الخانة العشرية الثالثة — قصٌّ لا تقريب — بينما التخزين (`D-68`) وحمولات الـ API والحقول نفسها
      > تبقى كما هي. مُنفَّذ في دالّة واحدة `crm/resources/js/domain/displayDecimals.ts` تُستدعى من **٢٤
      > موضعًا** في ستّ شاشات (1.2، #158). كلّ سطر أدناه يذكر **المخزَّن ⇒ المعروض**، والاثنان معًا هما
      > الاختبار: لو تساويا فالقصّ لم يحدث، ولو تغيّر المخزَّن فالحقل أُفسد.
      >
      > ⚠️ **بيانات التطوير كما قرأتها قاعدة البيانات في 2026-09-21 — صحّحها منها لا من الذاكرة:**
      > `QT-2026-0011` (بند إضافي «التوصيل» 500.000000 وتقريب **سالب** −0.300000) · `QT-2026-0012`
      > (ضريبة 212382.940000، تقريب 0.060000، وحدة التقريب 1.000000) · `QT-2026-0013` (معفاة من الضريبة،
      > وشروطها الثلاثة نصوص فيها نقاط) · `SQ-2026-0004` (66.0000 / 30.0000 / **36.0000**) ·
      > `SQ-2026-0002` و`SQ-2026-0003` و`SQ-2026-0005` إجماليها **NULL** · ثلاث مسوّدات
      > (`QT-2026-0007/0008/0009`) و**لا صفّ واحد بانتظار الاعتماد**.
      >
      > ⚠️ **شاشة الموافقات فارغة اليوم.** لا يوجد `pending_approval` في القاعدة، فالفحص 31 يُرسل
      > `QT-2026-0007` للاعتماد أوّلًا — **وهذه الخطوة تستهلك المسوّدة** ولا تعود مسوّدة بعدها. نفّذ 28
      > (وضع التعديل) **قبل** 31، وإلّا فقدت مسوّدةً للفحص.
      >
      > ⚠️ **الأدوار.** فحوص الأرقام تُنفَّذ بـ **مدير** (`quotation.view.all`) لأنّه وحده يرى كلّ الصفوف.
      > مبيعات داخلية/خارجية ترى صفوفها وحدها، والمشتريات المُسنَد إليها فقط، و**قائد الفريق يرى صفحة
      > فارغة** لأنّ `quotation.*.team` لا يُحلّ إلى صفوف حتّى تُوجد كيان الفريق (`D-a`، سجلّ الدين
      > ~سطر 1677) — **الفراغ عنده نطاق لا عيب**. الحسابات: `<role>@example.test`.

      **أ) عروض المورّدين — `/supplier-quotations` ثمّ «تعديل» على `SQ-2026-0004`** *(D-82؛ §3.6)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 1 | مدير | افتح `/supplier-quotations` وانظر إجمالي `SQ-2026-0004` ⇒ **5000.000** (المخزَّن `5000.000000`) | D-82 · موضع `SupplierQuotationsView:350` |
      | 2 | مدير | انظر صفّ `SQ-2026-0002` ⇒ **«—»** لا «0.000»؛ الإجمالي `NULL` يبقى شرطة | D-82 · نفس الموضع، مسار العدم |
      | 3 | مدير | اضغط «تعديل» على `SQ-2026-0004` ⇒ تحت بند الـ5000 سطر باهت «المسجَّل **66.000** · المستهلَك **30.000** · المتاح **36.000**» (المخزَّن `66.0000`/`30.0000`/`36.0000`) | D-82 · `SupplierQuotationFormModal:349` ×3 |
      | 4 | مدير | في النافذة نفسها انظر **الحقول** فوق السطر ⇒ «سعر الوحدة» يحمل `5000.000000` و«الكمية» تحمل `66.0000` **بكلّ خاناتها** — الرقم نفسه مرّتين: الحقل كامل والسطر مقصوص | «نظام التصميم» §6.3 سطر 207 «format only for display» · DB-07 |
      | 5 | مدير | بدّل إلى EN ⇒ «Recorded 66.000 · consumed 30.000 · available 36.000» والاتّجاه LTR، والحقول كما هي | EN · LTR |
      | 6 | مدير | 375 بكسل بالعربية ثمّ بالإنجليزية ⇒ سطر الأرقام ينزل صفًّا كاملًا، والأرقام لاتينية الاتّجاه داخل النصّ العربي | 375 · RTL |
      | 7 | مشرف الخارجي | افتح `/supplier-quotations` مباشرة ⇒ `/403` لا قائمة فارغة (لا يملك `supplier_quotation.view`) | SEC-07 · §3.6 |

      **ب) قائمة عروض الأسعار — `/quotations`** *(D-82؛ §3.5)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 8 | مدير | انظر عمود الإجمالي ⇒ `QT-2026-0012` **1729404.000** و`QT-2026-0010` **545.000** (المخزَّن `…000000`) | D-82 · `QuotationsView:562` |
      | 9 | مدير | بدّل إلى EN ⇒ الأرقام نفسها والعملة بعدها، LTR | EN |
      | 10 | مدير | 375 بكسل بالعربية ⇒ **إن ظهر «GP» بدل «EGP» فهذا دَين مسجَّل** (قصّ عمود الجدول، `CHECKLIST.md` ~254) **وليس فشل F-06**؛ الأرقام نفسها يجب أن تبقى ثلاث خانات | 375 · دَين معلن |
      | 11 | قائد فريق | افتح `/quotations` ⇒ **قائمة فارغة** ورسالة الفراغ، لا `/403`؛ نطاق `team` بلا صفوف (`D-a`) — ليس عيبًا | فارغ · D-a |
      | 12 | الرئيس التنفيذي | افتح `/quotations` ⇒ القائمة تظهر كاملة **ولا** زرّ «عرض سعر جديد» (يملك `view` لا `create`) | §3.5 · SEC-07 |
      | 13 | مشرف الخارجي | افتح `/quotations` مباشرة ⇒ `/403` | SEC-07 |

      **ج) تفاصيل عرض السعر — `/quotations/:id`** *(D-82 · D-63 · D-65؛ «نظام التصميم» §7.2)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 14 | مدير | افتح `QT-2026-0013` وانظر البند الأوّل ⇒ الكمّية **10.000**، التكلفة **5000.000**، السعر **5750.000**، الإجمالي **57500.000** (المخزَّن `10.0000` و`5000.000000` و`5750.000000` و`57500.000000`) | D-82 · `QuotationDetailView:433,434,436,437` |
      | 15 | مدير | في الصفّ نفسه انظر عمود «هامش الربح» ⇒ **«—»**؛ `margin_percent` معدوم في كلّ البنود اليوم، والشرطة ليست صفرًا | D-82 · مسار العدم |
      | 16 | مدير | **حارس (i) — النصّ الحرّ:** اقرأ «شروط الدفع» و«الضمان» و«شروط التسليم» ⇒ الجمل **كاملة بنقاطها**: «50% advance. Balance on delivery.» و«1 year warranty. Parts and labor included.» و«Delivery within 3 weeks. Site access required.» — لو ظهرت «50% advance. Ba» فقد عاد عيب 1.2 | D-82 «المال والكمّيات وحدها» |
      | 17 | مدير | في `QT-2026-0013` ابحث عن سطر الضريبة ⇒ **لا سطر ضريبة إطلاقًا** (لا صفر)؛ العرض معفى | D-63 |
      | 18 | مدير | افتح `QT-2026-0011` وانظر كتلة الإجماليات ⇒ الفرعي **568095.000**، وعاء الضريبة **568095.000**، الضريبة **79533.300**، البنود الإضافية **500.000**، النهائي **648128.000** | D-82 · `…:468,476,478,482,491` |
      | 19 | مدير | في `QT-2026-0011` انظر جدول البنود الإضافية ⇒ «التوصيل» بمبلغ **500.000** (المخزَّن `500.000000`) | D-82 · `…:458` |
      | 20 | مدير | في `QT-2026-0011` انظر سطر التقريب ⇒ الفرق **−0.300** بإشارته السالبة سليمة (المخزَّن `-0.300000`) | D-82 · `…:487` · D-65 |
      | 21 | مدير | **حارس (ii) — وحدة التقريب:** افتح `QT-2026-0012` ⇒ العنوان «**التقريب (إلى 1.000)**» والقيمة بجانبه «**0.060**» — الوحدة مقصوصة مثل الفرق، لا «1.000000» | D-82 «ووسائط `t()` المُدرَجة» · `…:486,487` |
      | 22 | مدير | في `QT-2026-0012` انظر عنواني الخصم والضريبة ⇒ «(0.000%)» و«(14.000%)» **كما هي مخزَّنة**، لم تُمسّ؛ النِّسب خارج `D-82` | D-82 «النِّسب كما تُخزَّن» |
      | 23 | مدير | في `QT-2026-0012` انظر الخصم ⇒ **0.000** والفرعي **1517021.000** والضريبة **212382.940** — الخانة الثالثة `4` محفوظة لا مُقرَّبة | D-82 «قصٌّ لا تقريب» · `…:471` |
      | 24 | مدير | بدّل إلى EN على `QT-2026-0012` ⇒ «Rounding (to 1.000)» والشروط الثلاثة كاملة، LTR | EN · LTR |
      | 25 | مدير | 375 بكسل بالعربية ثمّ بالإنجليزية على `QT-2026-0011` ⇒ كتلة الإجماليات بلا تمرير أفقي، والشروط تلتفّ دون قطع | 375 · RTL |
      | 26 | الرئيس التنفيذي | افتح `QT-2026-0012` ⇒ الصفحة تُفتح بأرقامها **ولا** أزرار «تعديل» أو «إرسال للاعتماد» | §3.5 · SEC-07 |
      | 27 | مشرف الخارجي | افتح `/quotations/<معرّف QT-2026-0012>` مباشرة ⇒ `/403` | SEC-07 |

      **د) منشئ عرض السعر — ثلاثة مسارات لشاشة واحدة** *(`router/index.ts:215,224,233`)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 28 | مدير | **وضع التعديل أوّلًا** (قبل 31): `/quotations/<معرّف QT-2026-0007>/edit` ⇒ تحت البند الأوّل «تكلفة الوحدة **500.000**» بينما حقل «الكمية» فوقه يحمل **1.0000** بكلّ خاناته — الرقم نفسه مرّتين، معروضًا مقصوصًا ومُدخَلًا كاملًا | D-82 · `QuotationBuilderView:728` · «نظام التصميم» §6.3 |
      | 29 | مدير | `/quotations/new?deal=<معرّف DL-2026-0001>`، العملة EGP، «+ إضافة مورّد»، اختر `SQ-2026-0004` ⇒ سطر باهت «سعر المورّد **5000.000** · الكمية المتاحة **36.000**» (المخزَّن `5000.000000` و`36.0000`) | D-82 · `…:826` ×2 |
      | 30 | مدير | في الفحص نفسه انظر حقل «الكمية» الفارغ ⇒ نصّه الباهت **36.000** مقصوصًا؛ ثمّ اكتب `36.5555` ⇒ ما تكتبه يبقى كما هو ولا يُقصّ | D-82 «العنصر النائب» · `…:837` |
      | 31 | مدير | من `QT-2026-0007` اضغط «إرسال للاعتماد» ثمّ افتح `/quotations/<معرّفه>/edit-and-approve` ⇒ الشاشة نفسها بأرقامها المقصوصة وزرّ الاعتماد. **هذه الخطوة تستهلك المسوّدة** | `router:233` · quotation.approve |
      | 32 | مدير | افتح `QT-2026-0008` للتعديل في لسانَي متصفّح، احفظ في الأوّل ثمّ في الثاني ⇒ شريط تعارض `409` يذكر الإجمالي الأحدث **مقصوصًا عند ثلاث خانات** | D-82 · `…:634` · API-12 |
      | 33 | مبيعات داخلية · مبيعات خارجية | افتح `/quotations/new` على صفقة يملكها الحساب ⇒ الشاشة نفسها والأرقام نفسها؛ وعلى صفقة لا يملكها ⇒ `/403` | §3.5 Own |
      | 34 | الرئيس التنفيذي · مشتريات | افتح `/quotations/new?deal=<معرّف DL-2026-0001>` مباشرة ⇒ `/403` (لا يملكان `quotation.create`) | SEC-07 |
      | 35 | مدير | بدّل إلى EN ثمّ 375 بكسل وأعد 29 ⇒ «Supplier price 5000.000 · available quantity 36.000»، والسطر يلتفّ | EN · 375 |

      **هـ) الموافقات — `/approvals`** *(§6.4 «دورة الاعتماد»)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 36 | مدير | افتح `/approvals` **قبل** تنفيذ 31 ⇒ حالة فراغ «لا شيء بانتظار الموافقة»، لا جدول ولا صفر | فارغ |
      | 37 | مدير | بعد 31 افتح `/approvals` ⇒ صفّ `QT-2026-0007` بإجمالي **13784.000** (المخزَّن `13784.000000`) | D-82 · `ApprovalsView:227` |
      | 38 | مدير | راقب لحظة الفتح ⇒ حالة تحميل ثمّ الجدول؛ وبإيقاف الشبكة (DevTools → Offline) ⇒ رسالة خطأ صريحة لا جدول فارغ | تحميل · خطأ |
      | 39 | مدير | بدّل إلى EN ثمّ 375 بكسل ⇒ الرقم نفسه، LTR ثمّ بلا قصّ للرقم | EN · 375 |
      | 40 | قائد فريق | افتح `/approvals` ⇒ الصفحة تُفتح **وهي فارغة**: `quotation.approve.team` بلا صفوف (`D-a`) — ليس عيبًا | D-a |
      | 41 | الرئيس التنفيذي · مشتريات · مبيعات داخلية · مبيعات خارجية · مشرف الخارجي | افتح `/approvals` مباشرة ⇒ `/403` لكلٍّ منها (`quotation.approve` للمدير وقائد الفريق وحدهما) | SEC-07 · §6.4 |

      **ما لا تغطّيه هذه القائمة — انظره ولا تُبلغ عنه كعيب:**
      - **ملفّ العميل PDF خارج `D-82`.** `app/Modules/Pdf` فارغ على `main`، و#118 (المطوّر الثاني) يحمل
        نصوصًا عادية؛ خاناته قرار الوحدة 9 نفسها حين يوجد قالبها. لا شيء في F-06 يمسّ ما يطبعه العميل.
      - **لا يظهر سعر صرف على أيٍّ من الشاشات الستّ** بالبيانات الحالية، فنصف `D-82` الخاصّ بأسعار الصرف
        (تُعرض كما تُخزَّن) **غير قابل للفحص هنا**. النِّسب قابلة، وهي الفحوص 15 و22.
      - **`D-82` ما زال «مقترحًا»** في §2 (سطر 153) حتّى يقلبه المالك؛ القائمة تختبر ما بُني لا ما اعتُمد.
      - **قصّ عمود الإجمالي عند 375 بكسل بالعربية** (ظهور «GP» بدل «EGP») **دَين مسجَّل** سببه الشريط
        الجانبي المنزلق على مستوى القشرة كلّها — يظهر على شاشات لم تمسّها F-06 — لا فشل في `D-82`.
        الفحص 10 يذكره صراحةً كي لا يُبلَّغ مرّتين.
      - **قيمة غير صفريّة أصغر من 0.001 تُعرض «0.000»**، وسالبها يُعرض «−0.000». تحقّقتُ من الدالّة
        نفسها: `0.000500 ⇒ 0.000` و`0.000999 ⇒ 0.000` و`-0.000400 ⇒ -0.000`. هذه نتيجة «قصٌّ لا تقريب»
        في `D-82` لا خطأ حساب — المخزَّن والمجاميع في الخادم لم تتغيّر (`D-67`). لا صفّ كهذا في بيانات
        اليوم، فالفحص يبقى نظريًّا حتّى توجد قيمة كهذه.

- [x] **F-07** A quotation row shows the customer's identifier instead of their name (Module 7 ← Module 3).
      Owner's report 2026-09-21, recorded as `D-83` (proposed): Quotations resolves the name through a
      **port on Customers' contract**, not through a second scoped list request from the SPA. This
      **reverses Module 7 Step 5 Q7** (`checklist/module-07.md:300-304`), which chose the frontend lookup
      and named `namesOf(list<string>)` as the alternative it declined — so it is a new decision, not a
      reinterpretation.

      **The stated cause was not the cause.** Measured 2026-09-21: the SPA sends no `sort`, so the server
      falls back to `orderBy('customers.id')` (`EloquentCustomerDirectory.php:80`, UUIDv7 ⇒ id-ascending),
      and all four customers that own quotations sit at positions **9, 13, 19, 95** of 234 — inside the
      page of 100. `perPage: 100` is a real ceiling but it is **latent**. Four mechanisms actually break
      the name, and one port closes all four:
      1. **the cap** — `listCustomers({ perPage: 100 })` (`QuotationsView.vue:246`), latent today;
      2. **the silent catch** — `loadCustomers`' `catch { customers.value = [] }` degrades *every* row to
         an identifier on any failure, deliberately, so a 403 does not error a list that loaded;
      3. **the archive filter** — `applyFilters` applies `where('customers.is_archived', …)`
         **unconditionally**, so an archived customer's name is **permanently** unresolvable while the
         system archives rather than deletes (`DB-01`);
      4. **the scope split** — `§3.3` scopes `customer.view` `own`/`asgn`/`out` while `quotation.view` is
         scoped separately, so a caller may legitimately read a quotation whose customer is outside their
         customer scope.

      **The precedent already exists in this repo:** the roles matrix needs every permission and uses
      `allPages('/permissions')` (`services/identity.ts:229`) rather than a capped page. `DealsView.vue:209`
      makes the identical capped call and carries the identical defect — covered at 1.5 or registered as
      debt there, decided at 1.1.

      Each point is its own `fix/…` branch, one per turn, seven-part report, owner's merge.

      ### F-07 point list — published 2026-09-21, approved by merging #160

      - [x] **1.1** `D-83` in §2 (proposed) + this list. Docs only — the decision row is pasted by the
            owner, as `D-82` was (#157), because the guard hook refuses an agent write to
            `CRM_Documentation_EN.md`; `.claude/settings.json` is not touched.
            *(2026-09-21, #160 — the owner's reported cause was measured and disproved: the four
            customers owning quotations sit at positions 9/13/19/95 of 234, inside the page of 100,
            so the cap is latent and four other mechanisms carry the defect)*
      - [x] **1.2** `CustomerNamesInterface::namesOf(array<string> $ids): array<string,string>` in
            `Customers/Domain/Contracts/`, its Eloquent implementation and its binding. **Name only** —
            never another customer field — so a caller permitted a quotation is not thereby granted
            customer data (owner's ruling, 2026-09-21). RED first: a contract test that an **archived**
            customer and one **outside the caller's row scope** both still return a name, which is
            mechanisms 3 and 4 written as a test. One query for N ids, never N queries. `waste-auditor`.
            *(2026-09-21, #PR — `EloquentCustomerNames` mirrors `EloquentUserFacts` minus every filter:
            no `deleted_at` either, the owner's ruling 2026-09-21, so a soft-deleted customer's name
            still shows; the N+1 mutant failed the query-count test with "actual size 3")*
      - [x] **1.3** Quotations' list use case calls `namesOf` **once per page** and the row and the
            customer group's `label` carry the name. RED first: a fake port asserting one call per page
            (no N+1), and a row whose customer is archived. `permission-matrix-auditor` (the name-only
            rule, one permitted and one refused role), `waste-auditor`.
            *(2026-09-21, #PR — `customer_name` beside `customer_id` on the row, the `currency` precedent;
            an id the facts do not name stays the id, the owner's ruling; the per-row mutant failed the
            fake-port test with "actual size 3")*
      - [x] **1.4** The SPA stops joining: `loadCustomers`, `customerNames` and `groupLabel`'s customer
            branch go (`QuotationsView.vue`), and the row reads the name the server sent. RED first:
            vitest on the row and on the grouped heading. `rtl-ui-verifier` (AR/EN × desktop/375 px),
            `waste-auditor`.
            *(2026-09-21, #PR — the join went; `loadCustomers` **stayed** for the customer filter's
            options, the owner's ruling, because the line above named it without seeing the `<select>`
            it also fed; the join-back mutant failed the row test; four browser states passed)*
      - [x] **1.5** `DealsView.vue:209` — the same port, or the same defect registered in the debt
            register with its reason. **Decided at 1.1, not deferred silently.** *Decided 2026-09-21
            at 1.2 (the owner, after 1.1 left it open): **the same port** — Deals' list use case calls
            `namesOf` once per page and its SPA join goes.*
            *(2026-09-21, #165 — list only, the owner's ruling; `loadCustomers` stayed for the form's
            picker; the per-row mutant failed with "actual size 3"; the detail's scoped read registered
            as debt)*
      - [x] **1.6** Manual test list for F-07 in Arabic — a customer inside the page, one **archived**,
            one **outside the caller's scope**, and the 403 path where the name must still appear;
            AR/EN × desktop/375 px; roles named.
            *(2026-09-21, #166 — 30 checks below; the data was read from the database and the API
            (`/customers` answers Indoor Sales **200 with 0 rows**); the 403 path is testable by the
            owner's ruling — the tester revokes a cell and restores it; F-07 closes here and stays in
            this file — a fix-pass item, not a module)*

      #### قائمة الاختبار اليدوي — F-07 *(النقطة 1.6، 2026-09-21)*

      > **F-07 إصلاح لا وحدة**، فالقائمة تغطّي `D-83` وحده: اسم العميل في صفّ عرض السعر وفي صفّ الصفقة
      > يأتي **من الخادم** عبر عقد وحدة العملاء (`namesOf`، مرّة واحدة لكلّ صفحة)، لا من قائمة عملاء
      > يطلبها المتصفّح. أربع آليات كانت تكسر الاسم، والقائمة تمرّ على كلّ واحدة: **سقف الـ100**، **فشل
      > طلب العملاء** (403)، **الأرشفة**، و**نطاق العميل** المنفصل عن نطاق العرض/الصفقة (§3.3).
      > نُفِّذ في #162 (المنفذ) و#163 (صفّ العرض وعنوان المجموعة) و#164 (شاشة العروض) و#165 (الصفقات).
      >
      > ⚠️ **بيانات التطوير كما قرأتها قاعدة البيانات في 2026-09-21 — صحّحها منها لا من الذاكرة:**
      > ١١ عرض سعر لأربعة عملاء: `المركز القومي للمرأة` (٨: `QT-2026-0001/0002/0003/0007/0008/0009/0010/0013`)،
      > `Al Yousr Hospital` (`QT-2026-0006`)، `Plan international` (`QT-2026-0011`)، `Nisco` (`QT-2026-0012`).
      > ستّ صفقات: `DL-2026-0001/0002/0004` للمبيعات الداخلية، `DL-2026-0005/0006` للمبيعات الخارجية،
      > `DL-2026-0003` للرئيس التنفيذي. **لا عميل مؤرشف، ولا عميل له مسؤول مبيعات** (`sales_owner_id`
      > فارغ في الكلّ) — لذلك كلّ عميل هو **خارج** نطاق `customer.view.own` لدى المبيعات الداخلية
      > والخارجية، وفحص «خارج النطاق» (د) لا يحتاج أيّ تحضير.
      >
      > ⚠️ **الأدوار.** الحسابات `<role>@example.test` بكلمة المرور المعروفة. **مدير** يرى الكلّ ويملك
      > الأرشفة (`customer.archive.all`). **مبيعات داخلية** ترى ٩ عروض و٣ صفقات هي صفوفها. **المشرف
      > الأعلى** وحده يعدّل الأدوار (`admin.manage_roles`). فحص الـ403 (هـ) **يغيّر صلاحية ثمّ يعيدها** —
      > بقرار المالك قابل للاختبار؛ لا تنسَ الإعادة (الفحص 26).
      >
      > ⚠️ **375 بكسل.** تجاوز العرض (scrollWidth نحو 411–421 من 375) موجود في **كلّ** شاشة بسبب الشريط
      > الجانبي، وهو دَين مسجَّل (`CHECKLIST.md` ~253) **وليس فشل F-07**. الفحص هو أنّ **الاسم** يظهر.

      **أ) قائمة عروض الأسعار — `/quotations`** *(D-83 · 1.3/1.4)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 1 | مدير | افتح «عروض الأسعار» بطريقة العرض «قائمة واحدة» ⇒ ١١ صفًّا، عمود «العميل» يحمل **اسمًا** في كلّ صفّ (`المركز القومي للمرأة`، `Al Yousr Hospital`، `Plan international`، `Nisco`)، ولا معرّف UUID في أيّ صفّ | D-83 · عميل داخل الصفحة |
      | 2 | مدير | افتح أدوات المطوّر ⇒ تبويب الشبكة، أعد التحميل ⇒ في ردّ `/api/v1/quotations` كلّ صفّ فيه `customer_name` مطابق حرفًا لما في الخليّة | D-83 · الاسم من الخادم |
      | 3 | مدير | بدّل «طريقة العرض» إلى «حسب العميل» ⇒ أربع مجموعات، **عنوان كلّ مجموعة اسم العميل** لا معرّفه، و`المركز القومي للمرأة` تضمّ ٨ عروض | D-83 · عنوان المجموعة (1.3) |
      | 4 | مدير | افتح مرشّح «العميل» ⇒ القائمة تحمل أسماء العملاء (خيار «كل العملاء» أوّلًا) — المرشّح ما زال يُملأ من قائمة العملاء بقرار المالك (1.4) | قرار المالك 1.4 |
      | 5 | مدير | اختر `Nisco` من المرشّح واضغط «تطبيق» ⇒ صفّ واحد `QT-2026-0012` باسم `Nisco` | المرشّح يعمل |
      | 6 | مدير | في تبويب الشبكة عُدّ طلبات `/api/v1/customers` عند تحميل الصفحة ⇒ **طلب واحد** (للمرشّح)، لا طلب لكلّ صفّ | D-83 · لا N+1 |

      **ب) قائمة الصفقات — `/deals`** *(D-83 · 1.5)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 7 | مدير | افتح «الطلبات / الصفقات» ⇒ ستّة صفوف، عمود «العميل» اسم في كلّ صفّ؛ `DL-2026-0002` و`0003` و`0006` كلّها `Al Yousr Hospital` صفوفًا مستقلّة | D-83 · 1.5 · §4.5 |
      | 8 | مدير | في تبويب الشبكة افتح ردّ `/api/v1/deals` ⇒ كلّ صفّ فيه `customer_name`، وعمود «المسؤول» ما زال **معرّفًا** (دَين الهويّة، ليس F-07) | D-83 · حدود النقطة |
      | 9 | مدير | اضغط «صفقة جديدة» وافتح قائمة «اختر العميل» ⇒ أسماء عملاء؛ ثمّ «إلغاء» دون حفظ | قرار المالك 1.4/1.5 · المنتقي باقٍ |

      **ج) عميل مؤرشف — `/customers` ثمّ `/quotations` و`/deals`** *(D-83 الآلية ٣ · DB-01)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 10 | مدير | في «العملاء» ابحث عن `Plan international` واضغط «أرشفة» ثمّ أكّد ⇒ يختفي من قائمة العملاء العاملة | DB-01 · تحضير |
      | 11 | مدير | افتح «عروض الأسعار» ⇒ صفّ `QT-2026-0011` **ما زال** يحمل `Plan international` (قبل F-07 كان يظهر معرّفًا) | D-83 · مؤرشف |
      | 12 | مدير | بطريقة «حسب العميل» ⇒ مجموعة عنوانها `Plan international` | D-83 · مؤرشف · العنوان |
      | 13 | مدير | افتح «الطلبات / الصفقات» ⇒ صفّ `DL-2026-0004` يحمل `Plan international` | D-83 · مؤرشف · 1.5 |
      | 14 | مدير | افتح مرشّح «العميل» في «عروض الأسعار» ⇒ `Plan international` **غير موجود** فيه — المرشّح يقرأ العملاء العاملين فقط، والصفّ يبقى مسمّى؛ هذا هو الفرق الذي أصلحه D-83 | D-83 · الفرق مرئي |
      | 15 | مدير | **أعِد العميل:** «العملاء» ⇒ «السجلات» = «المؤرشفة»، حدّد `Plan international`، «استعادة المحدد (1)» ⇒ «تمت استعادة 1 من العملاء.» | إعادة البيانات |

      **د) عميل خارج نطاق المستخدم — `/quotations` و`/deals`** *(D-83 الآلية ٤ · §3.3)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 16 | مبيعات داخلية | افتح «عروض الأسعار» ⇒ **٩ صفوف** (عروض `DL-2026-0001` و`0004`)، كلّها مسمّاة `المركز القومي للمرأة` أو `Plan international` — مع أنّ هذا الحساب لا يملك أيّ عميل | D-83 · خارج النطاق |
      | 17 | مبيعات داخلية | بطريقة «حسب العميل» ⇒ مجموعتان مسمّاتان (`المركز القومي للمرأة` ٨، `Plan international` ١) | D-83 · العنوان خارج النطاق |
      | 18 | مبيعات داخلية | افتح مرشّح «العميل» ⇒ **«كل العملاء» وحده**؛ في تبويب الشبكة `/api/v1/customers` يردّ **200 بلا صفوف** — هذا النطاق `own` وليس عيبًا، والأسماء في الصفوف باقية | §3.3 · نطاق لا عيب |
      | 19 | مبيعات داخلية | افتح «الطلبات / الصفقات» ⇒ ثلاثة صفوف `DL-2026-0001/0002/0004` مسمّاة | D-83 · 1.5 · خارج النطاق |
      | 20 | مبيعات داخلية | «صفقة جديدة» ⇒ قائمة «اختر العميل» فيها «اختر العميل» وحده (لا عملاء في نطاقه)؛ «إلغاء» | §3.3 · المنتقي فارغ بالنطاق |
      | 21 | مبيعات خارجية | افتح «عروض الأسعار» ⇒ صفّ واحد `QT-2026-0012` باسم `Nisco`؛ و«الطلبات / الصفقات» ⇒ `DL-2026-0005` `Nisco` و`DL-2026-0006` `Al Yousr Hospital` | D-83 · دور ثانٍ |

      **هـ) مسار الـ403 — المستخدم يرى العروض ولا يرى العملاء** *(D-83 الآلية ٢ · SEC-07)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 22 | المشرف الأعلى | «الأدوار والصلاحيات» ⇒ «اختر دورًا» = Indoor Sales ⇒ في قسم «العملاء» صفّ `customer.view` أزِل العلامة من عمود «الخاصة به» ⇒ «حفظ التغييرات» ⇒ رسالة تبدأ «تم الحفظ. مُنحت 0 وسُحبت 1.» | تحضير · SEC-07 |
      | 23 | مبيعات داخلية | سجّل الدخول من جديد وافتح «عروض الأسعار» ⇒ الصفحة **تُحمَّل** (لا صفحة خطأ)، ٩ صفوف **مسمّاة**؛ في الشبكة `/api/v1/customers` يردّ **403**، ومرشّح «العميل» فيه «كل العملاء» وحده | D-83 · 403 والاسم باقٍ |
      | 24 | مبيعات داخلية | «الطلبات / الصفقات» ⇒ ثلاثة صفوف مسمّاة، والصفحة بلا رسالة خطأ رغم 403 على العملاء | D-83 · 403 · 1.5 |
      | 25 | مبيعات داخلية | افتح `/customers` مباشرة ⇒ رفض (`/403`)، لا قائمة فارغة | SEC-07 |
      | 26 | المشرف الأعلى | **أعِد الصلاحية:** الشاشة نفسها، صفّ `customer.view` أعِد العلامة في عمود «الخاصة به» لـ Indoor Sales ⇒ «حفظ التغييرات» ⇒ «مُنحت 1 وسُحبت 0» | إعادة البيانات |

      **و) اللغتان والعرضان** *(«نظام التصميم» RTL/LTR · 375 بكسل)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 27 | مدير | بدّل إلى EN وأعِد 1 و3 و7 ⇒ الأسماء نفسها حرفًا (الاسم العربي يبقى عربيًّا داخل صفحة LTR)، العناوين «Customer» والاتّجاه LTR | EN · LTR |
      | 28 | مدير | 375 بكسل بالعربية: «عروض الأسعار» و«الطلبات / الصفقات» ⇒ الأسماء ظاهرة في كلّ صفّ (قد يُقصّ عمود المال — دَين مسجَّل ~253) | 375 · RTL |
      | 29 | مدير | 375 بكسل بالإنجليزية: الشاشتان نفسهما ⇒ الأسماء ظاهرة | 375 · LTR |
      | 30 | مبيعات داخلية | EN و375 على «عروض الأسعار» ⇒ ٩ صفوف مسمّاة، ومرشّح العميل فارغ كما في 18 | دور ثانٍ · EN · 375 |

      **ما لا يمكن اختباره اليوم — ولماذا**
      - **عميل بلا اسم ⇒ المعرّف** (قرار المالك في 1.3): لا يوجد في البيانات عميل تُرجعه `namesOf` بلا اسم
        (`customers.name` NOT NULL). يغطّيه اختبار آليّ فقط: `test_that_an_unnamed_customer_is_sent_as_its_id`.
      - **سقف الـ100 على الصفوف:** عملاء العروض الأربعة في المواضع 9 و13 و19 و95 من 234 (قياس 1.1)، فلا
        صفّ اليوم يقع خلف السقف. يغطّيه اختبار الواجهة «names a customer past the hundredth». **المرشّح
        ومنتقي الصفقة ما زالا مسقوفين بـ100** بقرار المالك — عميل بعد المئة لن يظهر فيهما.
      - **تفاصيل الصفقة `/deals/:id`:** خارج F-07 بقرار «القائمة فقط» (1.5). ما زالت تسمّي العميل عبر
        قراءة مقيّدة بالنطاق، فتُظهر **المعرّف** للمبيعات الداخلية على `DL-2026-0001` — دَين مسجَّل (#165)،
        **ليس فشلًا** في هذه القائمة.
      - **عمود «المسؤول»** معرّف في الشاشتين — دَين الهويّة، خارج F-07.

- [ ] **F-08** A customer dropdown lists only the first 100 customers, so a customer after that cannot
      be chosen (Modules 5 · 7 ← Module 3). Owner's report 2026-09-21, recorded as `D-84` (proposed):
      the quotations screen's customer filter and the deal form's customer picker become one shared
      **`CustomerPicker`** that searches the server as the user types, instead of two `<select>`s filled
      from `listCustomers({ perPage: 100 })`. Design System §6.3, "Search only when the option volume
      needs it".

      **The owner's understanding was measured, and it holds** (2026-09-21, as Manager):
      `/customers?per_page=100` returns **100 of 234**; `per_page=101` is a **400**
      (`CustomerListCriteria::MAX_PER_PAGE`). The page is sorted by **name**
      (`CustomerListCriteria::DEFAULT_SORT`), so every Latin name comes first: **10 of the 18 distinct
      names never appear** — `Sadex`, `save the children` and all eight Arabic names, among them
      `المركز القومي للمرأة`, which owns 8 quotations. The two dropdowns are the only callers of the
      capped list (`QuotationsView.vue:230`, `DealsView.vue:192` → `DealFormModal.vue:291`). The server
      already searches: `q` goes through `SearchService` (`OpenAPI_Contract` §6.2) and folds Arabic
      letter variants (`ArabicNormalisation`) — no backend change. Dev data holds 234 rows but only 18
      names, each imported about 13 times, identical down to the phone. `D-84` also carries a measured
      correction to `D-83`'s evidence; it is stated there only.

      Each point is its own `fix/…` branch, one per turn, seven-part report, owner's merge.

      ### F-08 point list — published 2026-09-21, approved by merging #167

      - [x] **1.1** `D-84` in §2 (proposed) + this list. Docs only — the decision row is pasted by the
            owner, as `D-82` (#157) and `D-83` (#160) were, because the guard hook refuses an agent write
            to `CRM_Documentation_EN.md`; `.claude/settings.json` is not touched. The supplier picker's
            screen's capped supplier read (names, filter, picker) is registered as debt (revealed, not fixed).
            *(2026-09-21, #167 — the D-84 row pasted by the owner; the supplier debt corrected from "a
            picker" to the three things one capped read feeds)*
      - [x] **1.2** `components/customers/CustomerPicker.vue`, its tests and its ar/en lang keys, **wired
            into the quotations filter in the same point** — a component nothing imports is dead code.
            `QuotationsView` loses `customers` and `loadCustomers`. RED first: nothing is asked before the
            300 ms pause, then `q`; 20 results and the "more" line; the name and the muted line; the four
            state lines (403, empty by scope, no match, failure with retry); keyboard selection; the
            filter's «كل العملاء» and clear button; the chosen id sent as `filter[customer_id]`; no
            `perPage: 100` call. `NoHardCodedTextTest`, `rtl-ui-verifier` (`/quotations`, AR/EN ×
            desktop/375 px), `waste-auditor`.
            *(2026-09-21, #168 — nothing asked on load, 20 on open, `q` after 300 ms; the pause, 403 and
            sequence-guard mutants each failed their test; the first full run's 50 failures were a stale
            single-file docs mount, not the diff — registered as debt)*
      - [x] **1.3** The deal form's picker: `DealFormModal` uses `CustomerPicker` and drops its
            `customers` prop; `DealsView` loses `customers` and `loadCustomers` — both capped calls are
            gone. The `deal-form-customer-id` hook still works; the placeholder is «اختر العميل» and there
            is no clear button. RED first: a customer past the hundredth can be chosen and `customer_id`
            is sent. `rtl-ui-verifier` (`/deals`, AR/EN × desktop/375 px), `waste-auditor`.
            *(2026-09-21, #169 — no screen reads `listCustomers({ perPage: 100 })` any more; the picker
            takes `placeholder` and lets `id`/`disabled`/`aria-invalid` fall through to its input)*
      - [ ] **1.4** Manual test list for F-08 in Arabic — roles named, AR/EN × desktop/375 px, every
            state line. The search check finds `المركز القومي للمرأة` by a word that actually finds it
            (`مرأة` or `القومي`), and the list says plainly that «المرأة» does **not** find it: the stored
            word is «للمرأة», and matching is by substring — expected, not a defect.


## Shell revisions — owner-directed

Changes the owner asked for directly, outside any module's point list. They belong to no module
because the shell belongs to no module: the sidebar, the context bar and the locale plumbing were
built in Module 1's Step 5 and are used by every module after it.

- [x] **S-02.1** §13's screens 4, 5 and 6 become **one settings page**. *(2026-08-29, owner's request)*
      ⚠️ **This changes the master documentation's screen inventory and is recorded as a pending
      `D-xx`, not applied silently.** §13 names *System Settings*, *Currencies & FX* and *Limits &
      SLAs* as three separate screens. The owner merged them; `CLAUDE.md` requires a proposed change
      to a documented decision to be raised as a new decision, and this entry is that record. Nothing
      in `docs/` was edited.
      ⚠️ **The naive merge would have deleted a grant §3.11 makes, and that is the whole
      engineering content of this point.** The page's sections carry **three different rows**:
      *system settings* and *system limits* are the Super Admin's, and **FX rates is the Manager's
      too**. One page behind `admin.system_settings` — which is what `/settings` required until now —
      would have locked the Manager out of a row they hold. So the route carries the **widest** of
      the three, `admin.fx_rates`, and each section is drawn by its own permission inside the page.
      A section the caller lacks is **not rendered and not requested**: a guaranteed 403 buys nothing
      but a denial block inside a page they were invited to open.
      **The parent owns the composition, not a flag on each child.** `SystemSettingsView` renders its
      own form, then `CurrenciesView`, then `SystemLimitsView` — so neither child learned about
      permissions it did not already need, and `SystemLimitsView`'s spec needed no change beyond the
      heading level. `CurrenciesView` keeps its own internal split because its two halves genuinely
      differ.
      **One `<h1>` for the page.** Both children lost their page headers; `currencies.title`,
      `currencies.subtitle`, `nav.item.currencies` and `nav.item.limits` are deleted as keys nothing
      renders, and `settings.section.*` arrives for the first section's heading. A test asserts each
      child renders **no** `h1`, and another asserts the page renders exactly one — two headings at
      the top level is what a screen reader reads out as two pages.
      **`/currencies` and `/limits` are gone** from the router and the menu, with their imports.
      ⚠️ The first removal attempt used a regex whose `    {\n` anchor matched the **first** route in
      the file, so it would have deleted five routes including `/settings`. Nothing was written — the
      assertion that followed caught it — and the retry walks outward from the exact `path:` line
      instead. Recorded because a silent version of that edit is a very quiet catastrophe.
      **305 frontend tests · 1330 backend · four deliberate breaks:** the limits section shown to
      everyone · the company form shown to everyone · the settings read issued without the row · the
      route's permission changed so it disagrees with the menu item. The last is the one that matters
      — `navigation.spec.ts` caught it, which is the guard doing the job it was written for.
      **Not covered:** the dropdowns are **S-02.2 and S-02.3**, not this point. The settings page is
      now long and has **no in-page navigation** between its sections; Design System §5.2 says
      nothing about one, and it was not invented here.

- [x] **S-02.2 / S-02.3** Assisted input on the settings page. *(2026-08-29, owner's request)*
      **One `<select>`, five suggestion lists, and the split is the whole decision.** A `<select>`
      closes a set, which is honest only where a document closes it: §14.2 names Arabic and English
      as the two languages this product ships in, so `locale.language` is a real choice between two
      known values and is the one closed control on the form.
      **Everything else stays typeable, because nothing closes those sets — the server least of
      all.** `SystemSetting::rule()` is `numeric` for the tax and `string` for the rest, so the API
      accepts any value. A `<select>` there would be the client inventing a constraint the product
      has not made — and worse, a stored value outside the list would **vanish from the control that
      is meant to be showing it**. `<datalist>` gives the dropdown without the lie: Safari draws the
      arrow, the suggestions are offered, anything may still be typed. A test stores
      `Mars/Olympus_Mons` and asserts it is still on screen.
      ⚠️ **`من` / `إلى` on the rate form are a datalist for a permission reason, not a taste one.**
      `GET /currencies` carries `admin.system_settings`, which the **Manager does not hold** — and
      §3.11 is precisely who may record a rate. A closed dropdown would be *empty* for them: not
      stricter, unusable. The codes are offered where they are known and typed where they are not,
      and `RecordFxRate` remains the only thing that decides whether a code exists. Tested from both
      sides. No extra request: the codes come from the rounding table the same component already
      loaded.
      **Sources, each named rather than invented.** Currencies from `GET /currencies`. Languages
      from §14.2. **Time zones from the platform** — `Intl.supportedValuesOf('timeZone')`,
      feature-detected because it is ES2023 and this project's `lib` is ES2022, measured at **418
      zones** in the test runtime. Date formats are three spellings, and the tax offers `0` and `14`
      — `14` being the only tax figure anywhere in the documentation (§5.2's worked example) and `0`
      the exempt end of `D-63`. Neither is seeded and the field still starts empty: a suggestion is
      not a default.
      **314 frontend tests · 1330 backend · four deliberate breaks:** the language field stops being
      a select · every `list` binding detached · the Manager's field pointed at a list that does not
      exist · a suggestion used as a fallback value.
      ⚠️ **Break 2 caught three weak assertions of mine.** The time-zone, date-format and tax cases
      asserted the datalist's *options* and not that the list was **attached to the input** — so they
      passed with every `list` binding removed, which is an orphaned dropdown that renders nothing.
      Re-asserted on the attribute; the same break then failed three cases instead of one.
      **Not covered:** `defaults.currency` costs **a second read of `/currencies`** — `CurrenciesView`
      below makes the same call for its rounding table. Marked `ponytail:` in the source with the
      upgrade path (lift the read into the page and pass the rows down) rather than refactored here.
      The tax and date-format suggestion sets are still awaiting an owner's list; until then they are
      suggestions over an open set, not a documented enumeration.

- [x] **S-02.4** Every field on the settings page explains itself. *(2026-08-29, owner's request)*
      **The Design System names no hint pattern, so this establishes one.** §5.2 asks a Form view for
      *"clear sections, required markers, inline validation, calculated values read-only,
      unsaved-change warning"* and stops; §8 asks for *error association* and says nothing about
      explanation. Recorded rather than assumed to be house style.
      **Nineteen controls, thirty-eight strings.** Eight settings fields, two rounding columns, three
      rate fields, six limits — in both languages.
      **The hint is associated, not merely adjacent.** `aria-describedby` carries `hint-<key>`, and
      `hint-<key> error-<key>` once the server has refused something: the explanation before the
      complaint, so a screen reader hears what the field is for *and* what went wrong. §8's error
      association, doing double duty.
      **On the rounding table the hint sits on the column, not in the cell.** One sentence repeated
      down three rows is three copies to read past; the two `<th>` carry it and every row's control
      points at them.
      **Every sentence is grounded, and the ones that could not be were not written.** The tax hint
      cites `D-63` and says *digits only, no % sign* because `SystemSetting::rule()` is `numeric`. The
      time-zone hint says times are stored in UTC and converted, which is `DB-08`. The rounding hints
      are `D-65` and §5.3's own numbers. The limits hints are each their enum case's citation said in
      words. ⚠️ **The FX rate hint deliberately does not say which way the multiplier goes** —
      `ExchangeRate` calls it *"the multiplier that reaches every line of every converted
      quotation"* and no document says whether `from → to` multiplies or divides. Nothing converts
      anything yet (Module 7), so the sentence describes the field and stops. **Owner question.**
      **321 frontend tests · 1330 backend · three deliberate breaks:** the hint keys pointed one
      letter off · the association helper returning the error id alone · one rate hint dropped.
      ⚠️ **Break 1 caught a weak assertion of mine, again the same shape as the last one.** The loop
      asserted `not.toContain('settings.hint.')`, and the break pointed the template at
      `settings.hints.` — vue-i18n echoed *that* key back, which does not contain `settings.hint.`
      and sailed straight through. Only comparing against the lang file's exact string catches a key
      that resolves to itself. Both loops re-asserted that way; the same break then failed two cases.
      ⚠️ **Every one of the 38 strings was rewritten the same day, because the first version was
      written for the wrong reader.** They shipped citing `D-63`, `DB-08`, `§5.3` and *"in PHP
      letters"* — each sentence true, each sentence addressed to somebody who has read the
      documentation. The owner's correction was plain: these are for people who have not seen the
      docs, do not write code, and may be opening the system for the first time.
      **The citations were not lost, they were moved to where they belong.** Every one is still in the
      component docblock and in this entry — which is where a reviewer checks whether a sentence is
      *true*. The screen is where a person reads what to type. The date-format hint now teaches by
      example (`d/m/Y` shows the 9th of March 2026 as `09/03/2026`) instead of naming a language.
      **A guard now enforces it, in both languages** — `i18n.spec.ts` scans all 19 hints in `en` and
      `ar` for decision/section references, for machinery words (`PHP`, `API`, `UTC`, `enum`, `JSON`,
      `null`, `endpoint`, `nullable`), and for being a sentence rather than a label. It asserts the
      **count** first, because an empty scan passes everything below it. Three deliberate breaks: a
      `D-63` put back into an English hint, `UTC` into an Arabic one, and a hint cut down to a label —
      each failed on the right case.
      **Not covered:** the hints describe **fields**, not workflows — a person who does not know what
      a quotation approval SLA is for will not learn it here. No `title` tooltips: a hint that only
      appears on hover is invisible on a phone and to a keyboard. And the guard checks *vocabulary*,
      not readability: nothing here can tell whether a sentence is genuinely clear to a newcomer.

- [x] **S-01** The product opens in Arabic, and the collapse control is an icon at the top of the
      menu. *(2026-08-29, on the owner's request in one message)*
      **Two changes with one thing in common: neither was a defect.** The shell did exactly what it
      was written to do; the owner changed what it should do.
      **The language.** `welcome.blade.php` rendered `app()->getLocale()`, which
      `SetLocaleFromRequest` resolves from `Accept-Language` — so an English browser was handed an
      English product and nobody had chosen that. `APP_LOCALE=ar` was set, and correct, and never
      consulted for the shell. It now renders the **configured** default.
      ⚠️ **`config('app.locale')` could not answer the question.** `Application::setLocale()` writes
      its argument back into `app.locale`, so after the middleware has run that key reports *this
      request's* negotiated locale, not the product's default. Measured — the first implementation
      read `app.locale` and got the visitor's own `Accept-Language` back. `config/app.php` gains
      **`default_locale`**, which nothing writes to, and `SpaShellTest` pins the shell to it rather
      than to a literal `ar` so a hard-coded default is a failing test.
      **The API still negotiates, untouched.** OpenAPI §2 is a contract with clients, and `LocaleTest`
      still passes as written. The two halves keep agreeing because `api.ts` sends *this document's*
      language as `Accept-Language`.
      **Persisting the choice is part of the change, not scope added to it.** Once the shell ignores
      `Accept-Language`, that header is no longer carrying anyone's preference across a reload —
      without a stored choice, an English reader would have had to switch language on every single
      page load. `setLocale()` writes `crm.locale`, and a pre-paint script in the shell reads it
      **before the first paint**, on Design System §3.1's terms and for a worse flash than the
      theme's: the whole layout arrives in the wrong direction. `LocalePreferenceTest` pins the two
      files to one storage key, because a mismatch there is silent.
      **The collapse control.** Icon only, in both states, and it now stands **in the slot the blue
      product mark held** — the owner pointed at that mark and asked for the control instead of it,
      in a second message the same day. Two earlier positions were tried and both were wrong: at the
      foot of the aside it drifted further down the screen with every module that added a menu item,
      and as its own row under the brand it left two squares stacked in a 72px rail. §5.1 asks this
      sidebar for the screens a role may open and **never for a logo**, so the mark was decorative
      and was holding the most prominent slot in the rail to show an image that did nothing when
      clicked. `app.mark` is deleted from both lang files with it — a key nothing renders.
      The **accessible name is always present and always hidden** — dropping the visible label and
      dropping the announced name are the same edit, and a button whose only content is an
      `aria-hidden` svg is announced as "button".
      ⚠️ **Below 1024px the header now shows the product name alone.** §4.3 gives the collapsed rail
      to ≥1024px only, so the control does not exist below that width and nothing replaced the mark
      there. The drawer still closes on its backdrop and on Escape; no behaviour was lost, only a
      decoration.
      **Tests: 4 PHP (`LocalePreferenceTest`) + 2 rewritten in `SpaShellTest`, and 9 vitest
      (`AppSidebar.spec.ts`, `i18n.spec.ts`).** `AppSidebar` had no component test at all before
      this. **Sixteen deliberate breaks with real output**, restored byte-identical. One of the nine
      is the owner's instruction itself made checkable: the control replaces the mark rather than
      standing beside it, so a re-added mark is a failing test rather than a crowded rail.
      **Two of the checks could not fail as first written, and both were found by breaking them.**
      `assertStringContainsString('dir', $tag)` passed with the direction line deleted, because
      *"wrong direction"* appears in the script's own prose; and `'ar'`/`'en'` occurred elsewhere in
      the script, so "contains the code" was true of a script that trusted whatever storage held.
      Both are now spelled as `documentElement.dir` and as `=== 'ar'` comparisons.
      **One restore silently did not apply**, for the reason recorded twice already: the `&&` chain
      stopped at the failed edit and the checksum step never ran, so the next break's output carried
      two failures that belonged to the previous one. Caught by `shasum -a 256 -c`, repaired, and
      re-verified `OK` before the gates.
      **Not covered:** the pre-paint script was **not executed in a real browser** — the served HTML
      was read back over TLS (`lang="ar" dir="rtl"` for an `en-GB` request) and the script's shape is
      asserted, but nothing here observes a paint or a `localStorage` round trip in Chrome. The
      language is still **per browser, not per account**: §3.1 and §9.1 put the preference on the
      user profile, and that debt is the theme's too. No language switch on the **login** screen
      beyond the context bar it does not render. The sidebar's own **collapsed state** is still not
      remembered across a reload.

## Module 0 — Foundation

**Closed 56 of 56 boxes** · full point history: [checklist/module-00.md](checklist/module-00.md)

---

## Module 1 — Identity & Permissions (Dynamic RBAC)

**Closed 38 of 40 boxes** · full point history: [checklist/module-01.md](checklist/module-01.md)

Still open:
- [ ] `POST` / `PATCH` / archive on `/api/v1/roles` itself — §3.11's "create role" half, still
      unbuilt, so §3.12 rule 5's ninth role cannot be added through the API yet
- [ ] text search (blocked on Module 3's `SearchService`) — **carried to Module 3 by design**

---

## Module 2 — Settings, Managed Lists & Currencies

**Closed 16 of 21 boxes** · full point history: [checklist/module-02.md](checklist/module-02.md)

Still open:
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
- [ ] Rounding can be switched **off** per currency → final total stored unrounded, `rounding_diff` = `0` (`D-65`)

---

## Module 3 — Customers

**Closed 22 of 28 boxes** · full point history: [checklist/module-03.md](checklist/module-03.md)

Still open:
- [ ] ~~**4.5a** bulk restore endpoint~~ — **superseded by the owner's decision of 2026-08-30**: 4.5
      ships select-all as a client loop over `PATCH /customers/{id}/restore`, so the screen no longer
      blocks on this. **The gap itself remains open**: `API-07` and `OpenAPI §7.3` document a bulk
      archive/restore endpoint that is not built, and it stays on the register awaiting a `D-xx`
- [ ] Sales employee sees only own customers · Team Leader sees team · Manager sees all
- [ ] Incomplete records are **excluded from financial reports** until completed
- [ ] Manually archived customer → visible only to Manager and Team Leader
- [ ] Change of sales owner → customer and full history transfer + audit entry
- [ ] **Every search call goes through `SearchService`** — no direct queries

---

## Module 4 — Catalog & Suppliers

**Closed 28 of 30 boxes** · full point history: [checklist/module-04.md](checklist/module-04.md)

Still open:
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
- [ ] Deactivated product is hidden from new selection lists

---

## Module 5 — Requests / Deals ⭐

**Closed 32 of 33 boxes** · full point history: [checklist/module-05.md](checklist/module-05.md)

Still open:
- [~] Employee-entered request → "Pending Approval" for the Team Leader — **the badge and the
      approval are built; "inactive until approved" is NOT**
      *(Point 6.4, and deliberately **not** a `[x]`. This criterion has two clauses and only one of
      them is met, so a tick would claim more than the code does — the clause that is missing is in
      the row a reader scans, not only in the note beneath it. Raised by a `/code-review` spec pass
      on 2026-09-10, which was right: the previous `[x]` overstated the work while the prose under it
      disclosed the gap correctly, and a reader skimming boxes would never reach the prose.
      §6.4's amber badge with an icon **and** a word, and Approve/Reject behind
      `deal.approve`. ⚠️ **Demonstrable as the Manager only**: §3.4 grants the permission to the
      Manager (`All`) and the Team Leader (`Team`), and `Team` resolves to no rows (Point 2.1), so
      the role this criterion names holds it and reaches no deal. ⚠️ **"inactive until approved" is
      not built** — §4.3 has no visibility column, and inventing one would be inventing a documented
      field, so a `pending` deal is an ordinary row the screen badges and does not claim the server
      hides. **This is what keeps the box at `[~]` rather than `[x]`**, and closing it needs a `D-xx`
      granting §4.3 a visibility column. Both on the debt register.)*

---

## Module 6 — Supplier Quotations

**Closed 31 of 32 boxes** · full point history: [checklist/module-06.md](checklist/module-06.md)

Still open:
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

---

## Module 7 — Customer Quotations ⭐ (the hardest module)

**Closed 57 of 57 boxes** · full point history: [checklist/module-07.md](checklist/module-07.md) ·
Arabic manual test list handed over 2026-09-14 (PR of this stub).

Still open (not boxes — owner items): the `user-term-suggestions` route, `currency` on quotation
rows and `UserFactsInterface` have no `OpenAPI` row; the debt-register rows Module 7 opened
(`group_by` shape, "a quotation line is unnamed on the wire", `Payload::pagination()` copies,
`idempotency_keys` retention) live in the register above.

## Module 8 — Approvals

**Closed 17 of 17 boxes** · full point history: [checklist/module-08.md](checklist/module-08.md) ·
Arabic manual test list handed over 2026-09-16 (PR of this stub).

Still open (not boxes — owner items): the Team Leader's `team` scope (`D-a` — the user story's own
actor is refused until a team entity exists); `sla_exceeded` `null` vs `false` while the limit is
unseeded; `days_waiting` calendar vs working days; `my_quotations` = deal owner, not `created_by`;
`OpenAPI` rows for `days_waiting`/`sla_exceeded`/`returned_at`/`return_note`/`is_self_approved` on rows,
`filter[bucket]=incomplete` and `GET /badges`; the debt rows Module 8 opened (fixture block ×12,
etag-write flow ×3, `RequestIdTest` flake) live in the register above.

## Module 9 — PDF Generation

> As a sales employee, I want to produce a professional PDF quotation, so that I can send it to the
> customer.

**Acceptance criteria**
- [ ] Quotation with 3 suppliers → **no supplier name or price anywhere in the PDF**
- [ ] Generation is async on the `pdf` queue + stored against the quotation + fixed snapshot
- [ ] Generation failure → automatic retry + notification to the employee
- [ ] `show_delivery_terms = false` → that section is omitted
- [ ] CEO can **download** the existing PDF but never generate a new one
- [ ] Arabic renders correctly with embedded fonts *(proven by P-01)*
- [ ] Rendering consumes a customer-view model that **structurally cannot** contain supplier,
      cost, or margin fields
- [ ] Page numbering is dynamic — item count varies per quotation

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

- [ ] **1.1** `CustomerQuotationView` in `Pdf/Domain/View/` — the model the template may see, plus
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

- [ ] **1.2** `CustomerQuotationViewMapper` in `Pdf/Application/` — `QuotationDetail` →
      `CustomerQuotationView`, the only place the two vocabularies meet, reading through
      `QuotationDirectoryInterface` and never through an Eloquent model of Yousef's. Company
      identity (name, logo, address, phones) comes from Settings, not hard-coded — `§13` screen 4
      and `§14.6` both require it. *Verified by* a three-supplier quotation whose
      `QuotationDetail` holds three distinct `supplierQuotationItemId` values and three different
      `unitCost`s, mapped, then serialised to JSON and asserted to contain **none** of those twelve
      values anywhere in the string — the acceptance criterion "no supplier name or price anywhere
      in the PDF" tested at the model rather than by reading a rendered page.

- [ ] **1.3** `quotation_files` + `AttachmentParent::Quotation` — one migration creating the pivot
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

**Sketch of the remaining steps, so the module's shape is visible without committing to their
points.** Step 2: Browsershot behind a `PdfRendererInterface`, `P-01`'s template ported to consume
`CustomerQuotationView` only, the four faces embedded base64, live page numbers via Chrome's
`headerTemplate`/`footerTemplate`, and **the percentages taken out of the labels** — see below.
Step 3: `POST /api/v1/quotations/{id}/pdf` dispatching to the
`pdf` queue (`PRF-04`, `config/queue.php:163`), the job, retry, and the `files` write. Step 4:
`GET …/pdf` download, §3.5's two permission rows including the CEO's download-not-generate rule and
Q2's fail-closed Procurement. Step 5: the screen, then the Arabic manual test list the module-end
rule requires.

---

## Module 10 — Customer Response & Purchase Orders

> As a sales employee, I want to record the customer's response, so that the deal moves along the
> correct path.

**Tables** `purchase_orders` — auto `po_number` + free-text `customer_po_reference` + date + attachment

**Acceptance criteria**
- [ ] Partial or Counter → full copy saved automatically, employee edits the new version
- [ ] Counter or Rejected → **mandatory reason** before the status is accepted
- [ ] Rejected → quotation archived · **customer stays in the list** · deal becomes Lost
- [ ] `valid_until` passes with no reply → **Expired** automatically (J-01)
- [ ] Search works on both the internal PO number and the customer's reference
- [ ] Every version preserved via `parent_id` + `version`

---

## Module 11 — Procurement

> As a procurement employee, I want to renegotiate with the supplier and calculate the final profit,
> so that the company earns the best possible margin.

**Tables** `procurement_negotiations`

**Acceptance criteria**
- [ ] Successful negotiation 1000 → 900 → saving of 100 recorded and added to final profit
- [ ] Failed negotiation → failed attempt with a reason; deal proceeds at the original price
- [ ] Procurement opening a quotation sees **cost, margin and profit**
- [ ] Delivery Complete → any of the four roles can confirm it **with attribution**

---

## Module 12 — Outdoor Visits 📱

> As an Outdoor employee, I want to record a visit outcome from my phone with minimal typing, so that
> I can finish my day quickly.

**Tables** `visits` · `visit_areas`

**Acceptance criteria**
- [ ] Visit form has **exactly 3 mandatory fields**: company name · contact person · outcome
- [ ] Connection drops while typing → draft saved locally and not lost
- [ ] "Successful" outcome with a request → routes automatically to the Team Leader, marked "done"
- [ ] Rejection → mandatory reason, feeding the rejected-companies report
- [ ] External access unavailable (Cloudflare) → **a clear, specific message**, not a generic error (`D-59`)
- [ ] Supervisor sees visits and outdoor-scope deals, and **cannot see any deal after handover**
- [ ] Mobile-first; touch targets at least 44 × 44 px

---

## Module 13 — Reports

> As an employee, I want to submit my reports so they reach my Team Leader, so that management can
> track the work.

**Tables** `reports` · `report_recipients` · `report_versions` · **Jobs** J-04 … J-08

**Acceptance criteria**
- [ ] Submitted report → **fixed snapshot + stored PDF**, unchanged even if the data changes
- [ ] Returned report → mandatory reason + v2 version, original retained
- [ ] Acknowledged → **read-only even for the author**
- [ ] Same type, period and employee → warning and a choice
- [ ] CEO → view, export and comment only — **no acknowledge, no return**
- [ ] Server down on the weekly report day → the job **runs on startup** (catch-up)
- [ ] No deletion — automatic archiving after six months, still viewable

---

## Module 14 — Dashboard

> As a Manager, I want to see sales and profit for a period, so that I can track performance.

**Tables** summary tables (pre-aggregation) — job J-09

**Acceptance criteria**
- [ ] Selected period → figures match the underlying data exactly
- [ ] Two currencies → each shown separately, aggregation an optional toggle
- [ ] CEO → **no drill-down** on any figure
- [ ] Sales by Area stays behind a feature flag
- [ ] ❌ Not in MVP: Outstanding Payments · Cheques Due

---

## Module 15 — Search (Meilisearch)

> As a user, I want to search in Arabic quickly and still find results even if I misspell something.

**Implementation** swap `PostgresSearchDriver` for `MeilisearchDriver` inside `SearchService`

**Acceptance criteria**
- [ ] Search for "احمد" finds "أحمد" (hamza normalisation)
- [ ] Minor typo still returns results (typo tolerance)
- [ ] Sales employee searches only within own customers (permission filtering)
- [ ] Meilisearch down → automatic fallback to ILIKE; **the screen does not break**
- [ ] Create or update → indexing happens immediately via a job

📏 **Trigger:** if customer search ever exceeds **500 ms**, pull this module forward immediately.

---

## Scheduled jobs — J-01 … J-14

- [ ] J-01 `expire_quotations` — daily · catch-up ✅
- [ ] J-02 `recompute_customer_status` — nightly + event · catch-up ✅
- [ ] J-03 `detect_stale_deals` — daily · catch-up ✅
- [ ] J-04 `daily_report_deadline_check` — daily at deadline · catch-up ✅
- [ ] J-05 `generate_weekly_reports` — weekly · catch-up ✅
- [ ] J-06 `auto_submit_unreviewed_reports` — daily · catch-up ✅
- [ ] J-07 `generate_monthly_report` — monthly · catch-up ✅
- [ ] J-08 `archive_old_reports` — daily · catch-up ✅
- [ ] J-09 `refresh_dashboard_aggregates` — hourly + full nightly
- [ ] J-10 `database_backup` — daily · catch-up ✅
- [ ] J-11 `cleanup_orphan_files` — weekly
- [ ] J-12 `fx_rate_staleness_alert` — weekly
- [ ] J-13 `reindex_search` — nightly + on demand
- [ ] J-14 `storage_threshold_check` — daily

Every job must be idempotent, logged, retried a fixed number of times, and visible in Queue Monitor.

---

## After all modules

### End-to-end testing
- [ ] Full journey: Lead → Contacted → RFQ → supplier offer → customer quotation → approval → PDF →
      purchase order → procurement → delivery
- [ ] Parallel journey: one customer with two concurrent deals — confirm the separation holds
- [ ] **Restart test:** power the server down and back up → all services return on their own and
      missed jobs run

### Final server setup
- [ ] PostgreSQL + Redis + Meilisearch on the server
- [ ] Nginx reverse proxy + SSL
- [ ] Process manager, **enabled on boot**, correct order:
      PostgreSQL → Redis → Meilisearch → application → workers → Nginx
- [ ] `/health` endpoint reporting each service explicitly
- [ ] **Daily backups: database + files + Meilisearch — on a separate machine/disk**
- [ ] **A real restore test** — not just confirming the file exists
- [ ] **External heartbeat monitoring** (a down server cannot report itself down)
- [ ] Runbooks: restore · startup · deployment · rollback

### Pilot rollout
- [ ] Import legacy data from Excel + a session to complete incomplete records
- [ ] User training — one session per role
- [ ] **Two weeks running in parallel** with the current process before full cutover

---

## MVP success criteria

**Primary:** 80% of quotations produced in the system within one month of go-live.

| Criterion | Target | Measured from | Met |
|---|---|---|---|
| Quotations produced in the system | ≥ 80% | System count ÷ actual count | [ ] |
| Active customers registered | 100% | Review with the Team Leader | [ ] |
| Daily reports submitted | ≥ 70% of working days | Reports table | [ ] |
| Outdoor visits logged from mobile | ≥ 80% | Visits table | [ ] |
| Average quotation approval time | ≤ 1 working day | Quotation timeline | [ ] |
| Open critical bugs | Zero | Error centre | [ ] |
| Unplanned downtime | Zero | System health | [ ] |

Missing the criterion is not failure — it is a prompt to find the **cause**. The next step follows
from the cause, not from adding features.
