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
| **10 — Customer Response & POs** | Yousef | point list published 2026-09-23 (#206), the owner's answers to Q1–Q12 recorded under the module |

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
      *Seen again by `rtl-ui-verifier` in F-10 · 1.8 (2026-09-22):* on `/catalog` in Arabic at 375 px
      the same ~46 px shifts the import dialog's primary button and the supplier picker's labels to
      `x = -5.5`, so 5.5 px of each sits off the left edge (still tappable). Same shell root cause, not fixed there.

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

- [x] **A quotation line is unnamed on the wire** — *revealed by Module 7 Point 6.7, 2026-09-14.*
      `QuotationPayload::detail()` writes `items[].supplier_quotation_item_id`, `quantity`, prices and
      costs, and nothing that says *what* the line is: no product name, no catalog id, no
      `supplier_quotation_id` (`quotation_items` has no such column; the offer is reachable only
      through the item). So 6.5 lists lines by number and 6.7 edits them by number. One field on the
      detail — the catalog label through `SupplierItemPricingInterface`'s join, which already touches
      `supplier_quotation_items` — names the line on both screens. Not built here: a cross-module
      contract widening is its own point. *(2026-09-22, #200 — F-16 · 1.1: `CatalogItemLabelsInterface`, not the pricing join)*

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
      *The second case arrived in F-10 · 1.8 (2026-09-22):* `SupplierPicker.vue`, a many-valued copy of
      `CustomerPicker`'s search and keyboard, 137 of its 246 lines identical. It was copied, not extracted,
      so a catalog point would not rewrite the picker the quotations and deals screens use. One shared combobox
      is now three consumers' fix (customers, catalog suppliers, and these two supplier-quotation controls).
- [ ] **The supplier-offer form's deal field still takes a raw UUID** — *owner's F-13 ruling, 2026-09-22:
      the search box and the deal column move to the deal's code, the form does not.* `SupplierQuotationFormModal.vue:625-636`
      is a free-text input whose value goes out as `deal_id`; a person has to paste an internal identifier to
      attach an offer to a deal. The fix is a deal picker (the shared combobox the `CustomerPicker` entry above
      describes), or a code resolved server-side on save, when ordered.
- [ ] **Escape on `CustomerPicker`'s open list also closes the deal form** — *revealed by F-10 · 1.8
      (2026-09-22), whose `SupplierPicker` had the same defect and fixed it; not fixed here, because
      `CustomerPicker` belongs to F-08's screens.* `CustomerPicker.vue`'s Escape branch calls
      `preventDefault()` only, so the keydown bubbles to `DealFormModal.vue:258`'s
      `@keydown.escape.prevent="requestClose()"`: one Escape closes the list **and** starts closing the
      dialog (the unsaved warning, or a silent close when nothing changed). WAI-ARIA APG: Escape on an
      open popup closes the popup only. The fix is one `event.stopPropagation()` in that branch, as
      `SupplierPicker.vue` has, plus a test in the deal form.
- [ ] **`.form-field` is declared once per component, inside `<style scoped>`** — *revealed by F-08
      Point 1.5, 2026-09-21; not fixed there, because the point was one missing border.* The same three
      lines (`background-color`, `border: 1px solid var(--color-border-strong)`, `color`) live in
      `DealFormModal.vue:436`, `QuotationsView.vue:600`, `UserFormModal.vue`, `CustomerFormModal.vue`,
      `CustomersView.vue`, `CustomerPicker.vue` and (F-10 · 1.8) `SupplierPicker.vue` — and a scoped copy in a parent never reaches a
      child's nested input, which is exactly how the picker lost its border in 1.3 (the quotations
      filter had lost it in 1.2, unnoticed). One rule in `resources/css/app.css` and the scoped copies
      deleted is the fix, when ordered; the Design System §6.3 control is one thing, not six.
- [ ] **The `php` container mounts two single files, which go stale when git rewrites them** — *revealed
      by F-08 Point 1.2, 2026-09-21: 50 of 2900 backend tests failed with `file(/opt/crm/docs/CRM_Documentation_EN.md):
      Failed to open stream`.* `docker-compose.yml:195` mounts `./docs/CRM_Documentation_EN.md` and `:190`
      mounts `./docker-compose.yml`, each as a single-file bind mount. A single-file mount follows the
      **inode**, and git writes a changed file as a new one — so after a merge or a branch switch that
      touches the file, the container keeps the deleted inode (`stat`: 0 links) and every test that reads the
      master documentation fails. Nothing in the code was wrong; `docker compose restart php` re-attached
      it and the same classes passed. The fix is to mount the containing directory (`./docs`) instead of
      the file — a change to the dev environment, so it is the owner's call, not this point's.
      **Recurred in F-10 · 1.2 (2026-09-22):** the same 50 tests, after #184 changed the master doc;
      `docker compose restart php`, then 2981 passed. Every doc-touching merge will do this until ordered.
- [x] **Nothing clears `is_incomplete` once an import sets it — customers and suppliers alike** —
      *revealed by the F-09 draft, 2026-09-21 (F-09 gap 6); not fixed there.* **Taken up by F-11 / `D-87`
      (2026-09-21); closes with it.** *(Closed 2026-09-21 with F-11: #180, #181, #182, and the 1.5 list.)* `D-31` flags an imported
      record with missing fields, and §11 excludes it from financial reports **"until completed"** — but
      only the importer writes the flag (`CustomerDraft`, and from F-09 · 1.2 the suppliers' request
      prohibits it), and no edit recomputes it. In dev data **all 234 customers** carry it. The fix is
      one rule for both: an edit that leaves every field the importer checks filled clears the flag,
      audited. Owner's call when to order it; Module 13's report exclusion depends on it.
- [ ] **`/customers` prints the sector's stored code, not its label** — *revealed by F-11 · 1.3's browser
      check, 2026-09-21; not fixed there because the diff was backend-only.* `CustomersView.vue:680`
      renders `customer.sector ?? '—'` (`medical`, `government`) while `CustomerDetailView.vue:113`
      resolves the same code through `sectorLabel()` against the managed list the list screen already
      loads for its filter (`sectors`, line 105). Both locales. One template expression and the helper
      moved or copied; `rtl-ui-verifier` at desktop only — the column is `hidden` below `md`.
- [x] **The two imports carry three identical shapes: the summary, its payload and the upload request** —
      *created by F-09 Point 1.4, 2026-09-21, and registered rather than fixed because extracting a shared
      layer was outside that point's approved list.* `Suppliers\Domain\Importing\ImportSummary` is
      field-for-field `Customers\Domain\Importing\ImportSummary`; `SupplierPayload::importBatch()` is
      `ImportBatchPayload::of()`'s body; `ImportSuppliersRequest` is `ImportCustomersRequest` but for one
      lang key. None carries a per-module rule (the columns and the flag rule live in `SupplierCsv` /
      `ImportSuppliers`), so they could move to `App\Support\Csv` beside `CsvReader`, on its
      `SharedContracts` terms. F-10's catalog import would make it three copies — the natural moment.
      **Taken up by F-10 · 1.2 / `D-86` (2026-09-21), before the catalog importer; closes with that point.**
      *Closed 2026-09-22 for two of the three:* the upload request and the payload are
      `App\Support\Csv\ImportFileRequest` / `ImportBatchPayload`. **`ImportSummary` stays one per module
      by the owner's ruling:** each module's `Domain` contracts name it, and `deptrac.layers.yaml` gives
      `Domain` no licence to depend on `SharedContracts`. A deliberate ceiling, not waste.
- [ ] **No import detects duplicates — customers, suppliers and catalog items alike** — *named in the
      "Not covered" of `D-85` and `D-87`, and made a ruling of F-10 (owner, 2026-09-21: "every row creates a
      new item"); registered here 2026-09-21 because until now it lived only in prose.* Importing one file
      twice makes two copies of every row (proved on the test database in F-09: 3 ⇒ 6). `product_code`
      carries no unique index either (`create_catalog_items`), so the database does not catch it. Owner's
      call when to order; any fix has to decide what "the same record" means per module first.
- [ ] **An import reports how many rows it rejected, never which** — *owner's F-10 ruling, 2026-09-21:
      "telling the user which rows were rejected is out of F-10"; registered here the same day.* The three
      import results (`ImportSummary` in Customers and Suppliers, and F-10's catalog one) carry
      `row_count − imported_count` and nothing per row, so a file with 40 rejected rows gives the user no
      way to find them but by eye. Owner's call when to order.
- [ ] **The catalog form accepts any text as a unit or service type; the import will not** — *revealed by
      F-10 · 1.1's measurement, 2026-09-21; registered, not fixed, by the owner's choice.*
      `SaveCatalogItemRequest.php:104,107` check `unit` and `service_type` only as strings of ≤ 64, while
      F-10's import rejects a value that is not in the `units` / `service_types` managed list (owner's
      ruling Q4). So a hand-typed value the import would refuse can be saved through the form. Existing
      rows are not measured for off-list values. Owner's call when to order.
- [ ] **deptrac reports one uncovered dependency: `EloquentSupplierItemQuantity` → `Ramsey\Uuid\Uuid`**
      — *revealed by F-09 Point 1.3, 2026-09-21; not fixed there, because the point moved the CSV
      reader.* `deptrac analyse --config-file=deptrac.layers.yaml --report-uncovered` names it on `main`
      too; it arrived with F-05 · 1.3 (`837c768`). The gates grep `Violations` (0), so an uncovered line
      never fails a build — the reason every other `App\Support` entry is named. The fix is one
      collector for `Ramsey\Uuid` (or `Str::uuid7()`, which the other adapters use), when ordered.
- [ ] **Nothing runs the scheduler, so no scheduled job fires in the stack** — *revealed by the Module
      10 point list, 2026-09-23.* `routes/console.php` registers `J-15` and `J-02`, but no service in
      `docker-compose.yml` runs `schedule:work` or `schedule:run` (`git grep -n "schedule:(work|run)"`:
      no hit in any tracked file). The `J-15` entry above ("sits in the scheduler") argues from a
      scheduler that is not running. **Taken up by Module 10 · 2.1** — `J-01` cannot meet its
      criterion without it; closes with that point.
- [ ] **`J-02` does not meet its documented catch-up** — *revealed by the Module 10 point list,
      2026-09-23; not fixed there, by the owner's ruling (Q9).* §15 marks `J-02` catch-up ✅ and `D-55` /
      `ST-05` say missed jobs run on startup. `RecomputeStaleCustomerStatuses`'s docblock and
      `routes/console.php:49` skip it "on `J-15`'s own precedent" — but `J-15`'s exemption is written
      into §15 (its ❌ and the note under the table), `J-02`'s is not. Either the startup run Module 10 ·
      2.1 builds for `J-01` gains `J-02`, or a `D-xx` records the exemption. **Owner: the second
      developer** (Module 5 owns `J-02`).
- [ ] **An expired quotation does not make its customer "No Response"** — *owner's Module 10 ruling
      (Q9), 2026-09-23.* §4.5 row 3: "a quotation went `Expired` with no reply ⇒ No Response".
      `CustomerStatusDerivation` reads deals only, and Deals has no contract that reads quotations, so
      `J-01`'s expiry changes no customer status. **Owner: the second developer** (Module 5 owns the
      derivation and `J-02`); Yousef supplies the Quotations read (a `QuotationsContract` method
      answering "does this customer have an expired quotation with no reply") when it is ordered.
- [ ] **The quotation status-move skeleton is written out four times** — *deepened by Module 10 · 1.3,
      2026-09-23; not extracted there, because it would edit three classes outside the point.*
      `SubmitQuotation`, `ApproveQuotation`, `ReturnQuotation` and now `SendQuotation` each repeat
      transaction → `QuotationWriteAccess::open` → `QuotationStatusTransition::isAllowed` → `moveStatus`
      → `reread` → audit (`grep -rln "moveStatus(" crm/app/Modules/Quotations/Application/Writing`: 4).
      `respond` (1.4–1.6) will be the fifth unless one helper takes the move and its extra columns.
      Owner's call whether 1.4 extracts it first. *Owner, 2026-09-23 (b): not before 1.4 — it stays
      debt; `RespondToQuotation` is the fifth copy.*

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
      - [x] **1.5** *Deferred to Module 10:* the `sent → accepted` transition calls 1.3 once per
            line inside its transaction and carries old/new `consumed_quantity` in its audit entry.
            Listed here so the dependency is visible; built as a Module 10 point, not an F-05 one.
            *(2026-09-23, #213 — built as Module 10 · 1.6: `RespondToQuotation::consumeLines`, keyed by the quotation line's id)*
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

- [x] **F-08** A customer dropdown lists only the first 100 customers, so a customer after that cannot
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
      - [x] **1.5** The picker's input carries the field border — ordered by the owner 2026-09-21 from
            the screenshot of 1.3's form: «اختر العميل» drew with no box while every sibling field had
            one. Root cause: `.form-field` is `<style scoped>` per page, and a parent's scoped rule stops
            at the child's root element; the picker's input is nested, so the class matched no rule
            (the quotations filter had the same gap since 1.2). The picker declares its own scoped copy.
            Numbered after 1.4 because the list was already written; it merges before it.
            *(2026-09-21, #170 — computed border on the picker's input = its sibling's, `1px solid
            rgb(120,113,108)`, on `/deals` (EN) and `/quotations` (AR); the per-component copies are
            registered as debt)*
      - [x] **1.4** Manual test list for F-08 in Arabic — roles named, AR/EN × desktop/375 px, every
            state line. The search check finds `المركز القومي للمرأة` by a word that actually finds it
            (`مرأة` or `القومي`), and the list says plainly that «المرأة» does **not** find it: the stored
            word is «للمرأة», and matching is by substring — expected, not a defect.
            *(2026-09-21, #171 — 35 checks below, merged after 1.5 (#170) whose border is check 35; the
            data was re-read from the database: 1.3's browser verification left four deals
            `DL-2026-0007…0010` (owner null); F-07's checks 6, 18, 23 are superseded here; F-08 closes
            and stays in this file — a fix-pass item, not a module)*

      #### قائمة الاختبار اليدوي — F-08 *(النقطة 1.4، 2026-09-21)*

      > **F-08 إصلاح لا وحدة**، فالقائمة تغطّي `D-84` وحده: منتقي العميل في **مرشّح عروض الأسعار** وفي
      > **نموذج الصفقة** صار مكوّنًا واحدًا `CustomerPicker` **يسأل الخادم** وهو يُكتب فيه، بدل قائمتين
      > `<select>` تُملآن من `listCustomers({ perPage: 100 })` فلا تعرضان العميل بعد المئة. نُفِّذ في #168
      > (المكوّن + المرشّح) و#169 (نموذج الصفقة) و#170 (إطار الحقل — عيب رآه المالك في لقطة 1.3).
      > **لا شاشة تقرأ صفحة عملاء مسقوفة بعد اليوم.**
      >
      > ⚠️ **بيانات التطوير كما قرأتها قاعدة البيانات في 2026-09-21 — صحّحها منها لا من الذاكرة:**
      > ٢٣٤ عميلًا عاملًا، **لا مؤرشف**، ١٨ اسمًا مميّزًا × ١٣ نسخة متطابقة حتّى الهاتف، ولا عميل له
      > مسؤول مبيعات (`sales_owner_id` فارغ) — فكلّ عميل **خارج** نطاق `own` لدى المبيعات الداخلية
      > والخارجية. **النسخ الثلاث عشرة لا تُميَّز في المنتقي**، ونسخة واحدة فقط تملك عروض الاسم؛ فترشيح
      > العروض بنسخة قد يُرجع **صفرًا** — الفحص هو أنّ `filter[customer_id]` أُرسل وأنّ كلّ صفّ ظاهر
      > باسم ذلك العميل. الصفحة مرتّبة **بالاسم**، فأوّل ٢٠ عند الفتح هي `ASPPC` ×١٣ و`Al Yousr Hospital` ×٧
      > — **اسمان لاتينيّان لا غير**؛ الاسم العربي لا يظهر إلّا بالكتابة، و`المركز القومي للمرأة` في
      > الموضع ١٨٣. البحث **بالاسم وحده** (`SearchIndex::Customers => ['name']`)، مطابقة **جزئية**
      > (ILIKE) مع طيّ الحروف أ إ آ ٱ→ا، ة→ه، ى→ي. **عشر صفقات**: الستّ القديمة (`DL-2026-0001/0002/0004`
      > مبيعات داخلية، `0005` خارجية، `0006` مشرف خارجي، `0003` الرئيس التنفيذي) وأربع تركها فحص 1.3
      > المتصفّحي (`DL-2026-0007…0010`، عنوانها «F-08 verify …»، **بلا مسؤول**، عميلها `المركز القومي
      > للمرأة`). ١١ عرض سعر لأربعة عملاء كما في قائمة F-07.
      >
      > ⚠️ **الأدوار** (`<role>@example.test`). **مدير** `customer.view.all` و`deal.create.all`. **مبيعات
      > داخلية** `customer.view.own` (⇒ `/customers` يردّ **200 بلا صفوف**) و`deal.create.own`. **الرئيس
      > التنفيذي** `customer.view.all` و**لا `deal.create`** — فلا زرّ «صفقة جديدة». **المشرف الأعلى** وحده
      > يعدّل الأدوار؛ فحص الـ403 (و) **يغيّر صلاحية ثمّ يعيدها** — لا تنسَ الإعادة (الفحص 27).
      >
      > ⚠️ **الشبكة.** افتح أدوات المطوّر ⇒ تبويب الشبكة قبل كلّ مجموعة ورشّح على `customers`. حالة
      > «الفشل» (هـ) تُصنع يدويًّا: زرّ الأيمن على طلب `/api/v1/customers` ⇒ «Block request URL»، ثمّ
      > ارفع الحظر بعدها.
      >
      > ⚠️ **375 بكسل.** تجاوز العرض (scrollWidth نحو 411–421) دَين مسجَّل في **كلّ** شاشة (`~253`) **وليس
      > فشل F-08**. الفحص هو أنّ القائمة المنسدلة تُفتح وتُقرأ وتُختار.
      >
      > ⚠️ **يحلّ محلّ فحوص F-07:** الفحص 6 (طلب واحد للعملاء عند التحميل)، و18 و23 (المرشّح فيه «كل
      > العملاء» وحده لأنّ `/customers` ردّ فارغًا أو 403) — بعد F-08 **لا يُطلب `/customers` عند
      > التحميل أصلًا**، والفراغ والـ403 يظهران **سطرًا داخل المنتقي** عند فتحه (الفحوص 21 و28 هنا).

      **أ) مرشّح العميل في عروض الأسعار — `/quotations`** *(D-84 · 1.2 · لا شيء قبل الفتح، ٢٠ ثمّ `q`)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 1 | مدير | افتح «عروض الأسعار» وانظر الشبكة ⇒ **لا طلب** إلى `/api/v1/customers` ولا طلب فيه `per_page=100` في الصفحة كلّها | 1.2 · لا `perPage: 100` |
      | 2 | مدير | انقر داخل حقل مرشّح «العميل» ⇒ طلب واحد `GET /api/v1/customers?…per_page=20` **بلا `q`**؛ تنسدل قائمة أوّلها **«كل العملاء»** ثمّ ٢٠ صفًّا: `ASPPC` ×١٣ و`Al Yousr Hospital` ×٧، وتحتها سطر «**يوجد المزيد — اكتب حروفًا أكثر**» | 1.2 · ٢٠ عند الفتح · «المزيد» |
      | 3 | مدير | اكتب `Nis` بسرعة ⇒ في الشبكة **طلب واحد** بـ `q=Nis` يصل بعد نحو ثلث ثانية من آخر حرف (لا ثلاثة طلبات)؛ ١٣ صفًّا `Nisco` تحت كلّ منها سطر باهت «`Mr.Naser Fadl · 01090907185`»، ولا سطر «المزيد» | 1.2 · مهلة 300ms · الاسم والسطر الباهت |
      | 4 | مدير | امسح واكتب `مرأة` ⇒ ١٣ صفًّا `المركز القومي للمرأة` بسطر باهت «`wafack Elsabawy مكتب المعادى · 01225049484`» | 1.4 · كلمة تجد الاسم |
      | 5 | مدير | امسح واكتب `القومي` ⇒ الصفوف نفسها | 1.4 · كلمة ثانية تجده |
      | 6 | مدير | امسح واكتب `المرأة` ⇒ **لا صفوف**، وسطر «**لا نتائج لـ «المرأة»**» — **متوقَّع لا عيب**: الكلمة المخزونة «للمرأة» والمطابقة جزئية، فـ«المرأة» ليست جزءًا من «للمرأة» | 1.4 · «المرأة» لا تجده |
      | 7 | مدير | امسح واكتب `الهندسة` (بتاء مربوطة) ⇒ `كليه الهندسه` (المخزون بهاء) يظهر — طيّ ة→ه | D-84 · طيّ الحروف |
      | 8 | مدير | امسح واكتب `01090907185` (هاتف Nisco) ⇒ «لا نتائج لـ «01090907185»» — البحث بالاسم وحده، والسطر الباهت للتعرّف لا للبحث | D-84 · الاسم وحده |
      | 9 | مدير | امسح واكتب `Nisco` ثمّ ⇓ (سهم لأسفل) مرّة ⇒ الصفّ الأوّل يُبرَز و`aria-activedescendant` يشير إليه؛ Enter ⇒ الحقل يحمل `Nisco` والقائمة تُغلق (`aria-expanded="false"`)؛ في الشبكة `/api/v1/quotations?…filter[customer_id]=<معرّف>`، والصفوف إمّا `QT-2026-0012` وحده أو **لا صفوف** (نسخة لا تملك عرضًا — المقدّمة) مع حالة «لا نتائج» لا خطأ | 1.2 · لوحة المفاتيح · `filter[customer_id]` |
      | 10 | مدير | يظهر زرّ «**مسح**» بجانب الحقل ⇒ اضغطه ⇒ الحقل يفرغ وتعود ١١ صفًّا | 1.2 · زرّ المسح |
      | 11 | مدير | انقر الحقل واختر «كل العملاء» بالفأرة ⇒ ١١ صفًّا؛ ثمّ اكتب `Sad` واضغط Escape ⇒ القائمة تُغلق **دون اختيار** والمرشّح كما كان | 1.2 · «كل العملاء» · Escape |
      | 12 | مدير | بدّل «طريقة العرض» إلى «حسب العميل» ثمّ اختر `Plan international` في المرشّح ⇒ إمّا مجموعة واحدة `Plan international` (`QT-2026-0011`) أو لا مجموعات (نسخة أخرى) — المرشّح والتجميع يعملان معًا بلا خطأ | 1.2 · المرشّح مع التجميع |

      **ب) نموذج الصفقة — `/deals`** *(D-84 · 1.3 · «اختر العميل» بلا مسح · عميل بعد المئة)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 13 | مدير | افتح «الطلبات / الصفقات» وانظر الشبكة ⇒ **لا طلب** إلى `/api/v1/customers`؛ ١٠ صفوف | 1.3 · لا قراءة عند التحميل |
      | 14 | مدير | اضغط «صفقة جديدة» ⇒ حقل «العميل (مطلوب)» **حقل كتابة** بعنصر نائب «**اختر العميل**»، **لا** زرّ «مسح» ولا خيار «كل العملاء»؛ اضغط على كلمة «العميل» في التسمية ⇒ المؤشّر ينتقل إلى الحقل | 1.3 · العنصر النائب · لا مسح · `label for` |
      | 15 | مدير | انقر الحقل ⇒ طلب `per_page=20` والقائمة نفسها (أ-2)؛ اكتب `مرأة` واختر `المركز القومي للمرأة` بالفأرة ⇒ الحقل يحمل الاسم والقائمة تُغلق — عميل في الموضع **١٨٣** لم يكن ليظهر في `<select>` المئة | 1.3 · عميل بعد المئة يُختار |
      | 16 | مدير | اكتب في «الطلب» `F-08 يدوي` واضغط «حفظ» ⇒ في الشبكة `POST /api/v1/deals` وفي جسمه `customer_id` هو معرّف العميل المختار؛ النافذة تُغلق ويظهر صفّ جديد (الرمز التالي، `DL-2026-0011` اليوم) باسم `المركز القومي للمرأة` | 1.3 · `customer_id` يُرسل |
      | 17 | مدير | «صفقة جديدة» من جديد، اترك العميل فارغًا، اكتب عنوانًا واضغط «حفظ» ⇒ سطر «**العميل مطلوب.**» تحت الحقل، الحقل يحمل `aria-invalid="true"`، و**لا** طلب `POST` | 1.3 · المطلوب · `aria-invalid` |
      | 18 | مدير | في النموذج نفسه انقر الحقل واختر عميلًا ثمّ امسح النصّ بلوحة المفاتيح (Backspace حتّى يفرغ) ⇒ القائمة تُفتح للبحث من جديد؛ Escape ثمّ «إلغاء» | 1.3 · الكتابة بعد الاختيار |
      | 19 | مدير | أثناء الحفظ (بطّئ الشبكة إلى «Slow 3G» واضغط «حفظ» بعميل وعنوان) ⇒ حقل العميل **معطَّل** مع باقي الحقول حتّى يعود الردّ | 1.3 · `disabled` يصل للحقل |
      | 35 | مدير | في «صفقة جديدة» وفي مرشّح «عروض الأسعار» ⇒ حقل العميل له **إطار** وخلفية كحقل «الطلب» وكقائمة «النوع» بجانبه تمامًا (لا حقل بلا صندوق) — بالعربية والإنجليزية، وفي الوضع الداكن | 1.5 · #170 · نظام التصميم §6.3 |

      **ج) الرفض بالصلاحية — الرئيس التنفيذي** *(SEC-07 · §3.4)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 20 | الرئيس التنفيذي | افتح «الطلبات / الصفقات» ⇒ **لا زرّ «صفقة جديدة»** (لا `deal.create`)؛ افتح «عروض الأسعار» وانقر مرشّح «العميل» ⇒ القائمة تعمل (`customer.view.all`) | SEC-07 · رفض بلا خطأ |

      **د) النطاق الفارغ — المبيعات الداخلية** *(D-84 · «لا يوجد عملاء ضمن نطاقك» · §3.3)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 21 | مبيعات داخلية | افتح «عروض الأسعار» (٩ صفوف مسمّاة كما في F-07) وانقر مرشّح «العميل» ⇒ `/api/v1/customers` يردّ **200 بلا صفوف**؛ القائمة فيها «كل العملاء» وسطر «**لا يوجد عملاء ضمن نطاقك**» — نطاق `own` وليس عيبًا | D-84 · فارغ بالنطاق (يحلّ محلّ F-07/18) |
      | 22 | مبيعات داخلية | اكتب `Nisco` ⇒ «**لا نتائج لـ «Nisco»**» (بحث فارغ لا نطاق فارغ — سطر مختلف) | D-84 · فرق السطرين |
      | 23 | مبيعات داخلية | «الطلبات / الصفقات» ⇒ «صفقة جديدة» ⇒ انقر حقل العميل ⇒ «لا يوجد عملاء ضمن نطاقك»؛ «إلغاء» | 1.3 · النطاق في النموذج |

      **هـ) فشل الطلب وإعادة المحاولة** *(D-84 · «تعذّر تحميل العملاء» · باقي الشاشة تعمل)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 24 | مدير | في «عروض الأسعار» احظر `/api/v1/customers` (المقدّمة) ثمّ انقر المرشّح ⇒ سطر «**تعذّر تحميل العملاء**» وزرّ «**إعادة المحاولة**»؛ صفوف العروض **باقية** والصفحة بلا رسالة خطأ | D-84 · الفشل لا يُسقط الشاشة |
      | 25 | مدير | ارفع الحظر واضغط «إعادة المحاولة» ⇒ طلب جديد و٢٠ صفًّا | D-84 · إعادة المحاولة تسأل من جديد |

      **و) مسار الـ403 — المستخدم يرى العروض ولا يرى العملاء** *(D-84 · «لا تملك صلاحية عرض العملاء» · SEC-07)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 26 | المشرف الأعلى | «الأدوار والصلاحيات» ⇒ Indoor Sales ⇒ صفّ `customer.view` أزِل العلامة من «الخاصة به» ⇒ «حفظ التغييرات» ⇒ «تم الحفظ. مُنحت 0 وسُحبت 1.» | تحضير · SEC-07 |
      | 27 | المشرف الأعلى | **بعد الفحصين 28 و29 — أعِد الصلاحية:** الصفّ نفسه، أعِد العلامة ⇒ «مُنحت 1 وسُحبت 0» | إعادة البيانات |
      | 28 | مبيعات داخلية | سجّل الدخول من جديد، «عروض الأسعار» ⇒ ٩ صفوف مسمّاة (D-83)؛ انقر المرشّح ⇒ `/api/v1/customers` **403** وسطر «**لا تملك صلاحية عرض العملاء**»، لا صفحة خطأ ولا `/403` | D-84 · 403 داخل المنتقي (يحلّ محلّ F-07/23) |
      | 29 | مبيعات داخلية | «الطلبات / الصفقات» ⇒ «صفقة جديدة» ⇒ انقر حقل العميل ⇒ السطر نفسه؛ باقي النموذج يعمل؛ «إلغاء» | 1.3 · 403 في النموذج |

      **ز) عميل مؤرشف لا يُعرض** *(D-84 «غير مغطّى» · DB-01)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 30 | مدير | في «العملاء» أرشف **أيّ** نسخة من `Plan international` ⇒ في «عروض الأسعار» اكتب `Plan` في المرشّح ⇒ **١٢** صفًّا لا ١٣ (المؤرشف لا يُعرض)؛ صفّ `QT-2026-0011` ما زال **مسمّى** أيًّا كانت النسخة المؤرشفة (D-83) — **أعِد** العميل من «المؤرشفة» بعدها | D-84 · المؤرشف لا يُعرض · D-83 الاسم باقٍ |

      **ح) اللغتان والعرضان** *(«نظام التصميم» RTL/LTR · 375 بكسل)*

      | # | الدور | الخطوة ⇒ المتوقَّع | المعيار |
      |---|---|---|---|
      | 31 | مدير | بدّل إلى EN وأعِد 2 و3 و6 و14 ⇒ «Any customer» أوّلًا، «More customers match — type more letters»، «No results for “المرأة”»، العنصر النائب «Choose the customer»، زرّ «Clear»؛ الاتّجاه LTR والسطر الباهت بعد الاسم | EN · LTR |
      | 32 | مدير | 375 بكسل بالعربية: «عروض الأسعار» ⇒ انقر المرشّح واكتب `مرأة` واختر ⇒ القائمة تنسدل داخل الشاشة، النصّ محاذٍ لليمين، الاختيار يعمل؛ «الطلبات / الصفقات» ⇒ «صفقة جديدة» ⇒ الحقل والقائمة داخل النافذة | 375 · RTL |
      | 33 | مدير | 375 بكسل بالإنجليزية: الشاشتان نفسهما ⇒ القائمة تنسدل وتُختار، النصّ محاذٍ لليسار | 375 · LTR |
      | 34 | مبيعات داخلية | EN و375 على «عروض الأسعار» ⇒ انقر المرشّح ⇒ «No customers in your scope» | دور ثانٍ · EN · 375 |

      **ما لا يمكن اختباره اليوم — ولماذا**
      - **معرّف يُعرض بدل الاسم** (D-84: «قيمة تُضبط من الخارج بلا صفّ مختار تُعرض كمعرّف»): لا شاشة تفعل
        هذا اليوم. يغطّيه اختبار المكوّن وحده.
      - **همزة منفردة (ء) لا تُطوى** (D-84 «غير مغطّى»): لا اسم في البيانات يحملها.
      - **عميل بعد المئة في المرشّح بالفتح دون كتابة:** أوّل ٢٠ بالاسم لاتينيّة كلّها؛ العربي لا يظهر إلّا
        بالكتابة (الفحص 4) — سلوك D-84 لا عيب، ومكتوب في قراره.
      - **منتقي المورّد** في شاشة عروض المورّدين ما زال `<select>` مسقوفًا بـ100 — دَين مسجَّل (#167)،
        خارج F-08.
      - **تفاصيل الصفقة `/deals/:id`** تسمّي العميل عبر قراءة مقيّدة بالنطاق — دَين (#165)، خارج F-08.

- [x] **F-09** Suppliers cannot be imported, and nothing can mark a supplier incomplete (Module 4 ←
      Module 3). Owner's request, agreed in conversation before F-08 and numbered 2026-09-21 (F-08 =
      the customer dropdown, so this item moved from F-08 to F-09 and its decision from D-84 to
      `D-85`; the catalog import and the product↔supplier link are **F-10 / `D-86`**, and the
      Arabic-Indic dates are an **F-11** candidate the owner has not ordered). This line is the first
      written record of that numbering. Recorded as `D-85` (proposed): suppliers get the CSV import
      customers already have (§3.3, `D-31`, the owner's 2026-08-29 CSV ruling), and a row with missing
      fields saves flagged **`is_incomplete`**. **`is_active` stays as it is** (owner's ruling): no
      `is_archived` column for suppliers.

      **Measured 2026-09-21 (database + code, not memory):** `suppliers` has `name` (NOT NULL) · `type`
      · `color_rating` (NOT NULL, default `white`) · `phone` · `contact_person` · `has_open_account`
      (NOT NULL, default false) · `is_active` (NOT NULL, default true), and **no `is_incomplete`**. Dev
      data holds **2 suppliers, both active**, and 7 catalog items. Suppliers have **no permission
      resource of their own**: `/suppliers` is guarded by `catalog.view` / `catalog.manage` because
      §3.7 is one table for the catalog and its suppliers (`routes/api.php:476-523`), and **no `import`
      action exists** under `catalog`. The customer import is `POST /customers/import` →
      `ImportCustomers` (one transaction, an audit row per customer, one batch row) → `CustomerCsv`
      (`fgetcsv`, no library; BOM, `;` sniffing, CRLF) → `import_batches`, a table the **Customers
      module owns** with no column saying what was imported. `customer.import` is seeded to the
      **Manager alone** (`PermissionMatrix.php:159`, §3.3); the dev database also grants it to the
      **Team Leader** — a grant made at runtime through the Roles screen, not by the seeder.
      Nothing clears a customer's `is_incomplete` once set: `CustomerDraft` lets only the importer
      write it, and no edit recomputes it.

      **Gaps the agent filled — each is the owner's to correct before merging this list:**
      1. **Permission:** a new **`catalog.import`** (§3.7's resource, so F-10's catalog import uses the
         same row) granted to the **Manager alone**, as §3.3's customer import is — not every
         operational role, even though `D-45` opens single edits to all of them. Alternatives:
         `supplier.import`, or a wider grant.
      2. **Columns the file may carry:** `name` · `type` · `phone` · `contact_person` ·
         `has_open_account`. **`color_rating` is not imported**: `D-19` makes it a manual rating and
         §7.1's ⚪ White means "new / not yet rated", which every imported supplier is.
      3. **What "missing fields" means for a supplier:** an empty `type`, `phone` or `contact_person`
         flags the row. An empty `has_open_account` is read as *no*, not as missing. An empty `name`
         fails the row, as for customers. **An unknown `type` fails the row too** (owner's ruling (a),
         2026-09-21): the type is checked at the boundary (`SaveSupplierRequest`, `Rule::in(SupplierDraft::TYPES)`)
         and by a database CHECK. *Corrected 2026-09-21 in F-09 · 1.2:* this line first said nothing
         checked the type, which was wrong.
      4. **Batch table:** a new **`supplier_import_batches`** owned by the Suppliers module, the same
         columns as `import_batches` — not a shared table with a "kind" column, because modules do not
         read or write each other's tables.
      5. **One CSV reader, not two:** the format rules in `CustomerCsv` (BOM, separator, CRLF, cell
         trimming) move to **`App\Support\Csv`**, with a narrow deptrac entry like
         `App\Support\Search`'s. Customers keep their headers and aliases; suppliers declare their
         own. Copying `CustomerCsv` into Suppliers would create duplicate logic.
      6. **The flag is never cleared, as for customers:** an import sets it and no edit clears it.
         §11 says "until completed", so the gap is real for both; it is registered as **one** debt row
         covering customers and suppliers, not fixed for suppliers alone.
      7. **The flag is visible:** in the payload, as `filter[is_incomplete]` on `GET /suppliers`, and
         as a chip plus a filter on the Suppliers screen. `SaveSupplierRequest` **prohibits** the field
         (a 422), as `SaveCustomerRequest` does.

      **Not covered by F-09:** duplicate detection (importing the same file twice makes two copies of
      every supplier — the reason dev data holds 13 copies of each customer); `.xlsx` (CSV only, per the
      2026-08-29 ruling); the supplier-quotations screen's capped `listSuppliers({ perPage: 100 })`
      (debt row above) — an import makes more than 100 suppliers realistic, so that debt can now be
      reached, but it stays the owner's to order; catalog items and the product↔supplier link (F-10).

      Each point is its own branch, one per turn, seven-part report, owner's merge.

      ### F-09 point list — published 2026-09-21, approved by merging #172

      - [x] **1.1** `D-85` in §2 (proposed) + this list. Docs only — the decision row is pasted by the
            owner, as `D-82`, `D-83` and `D-84` were, because the guard hook refuses an agent write to
            `CRM_Documentation_EN.md`.
            *(2026-09-21, #172 — the D-85 text in #172 wrongly says the type is unchecked; the corrected
            text is in 1.2's PR)*
      - [x] **1.2** Schema and read side: a migration adding `suppliers.is_incomplete` (boolean, NOT
            NULL, default false) and creating `supplier_import_batches` (the `import_batches` columns and
            CHECKs), with `down()` tested by `migrate:rollback`; `is_incomplete` in `SupplierPayload`;
            `filter[is_incomplete]` in `SupplierListCriteria`; `SaveSupplierRequest` prohibits it. RED
            first: the payload key, the filter both ways, and a 422 on a write that sends it.
            *(2026-09-21, #173 — both mutants failed their tests; nothing writes the flag or the batch
            table until 1.4)*
      - [x] **1.3** `App\Support\Csv`: the format rules move out of `CustomerCsv` unchanged, plus a
            deptrac entry for the one namespace. No behaviour change — the proof is that every existing
            customer-import test passes untouched, and the moved code keeps its own tests (BOM, `;`,
            CRLF, header with no usable column).
            *(2026-09-21, #174 — `CustomerImportEndpointTest` untouched and green; `name` stays built in, by
            the owner's choice)*
      - [x] **1.4** `POST /suppliers/import` under `catalog.import` (seeded to the Manager in
            `PermissionMatrix`): `SupplierCsv` (its columns and headers) + `ImportSuppliers` (one
            transaction, an audit row per supplier, one `supplier_import_batches` row, `D-31`'s flag,
            the file limit `D-71` gives `ImportCustomersRequest`). RED first: 403 for a role without
            the permission, a row missing `type`/`phone`/`contact_person` saves flagged, a row missing
            `name` is counted and not saved, `color_rating` arrives `white`. `permission-matrix-auditor`.
            **After merging, run once:** `php artisan db:seed --class=RolePermissionSeeder` (as F-01).
            *(2026-09-21, #175 — Suppliers gains `StorageContract` on Customers' terms; the directory is
            registered with the AUD-01 writer guard; 145 permissions, 218 grants)*
      - [x] **1.5** Suppliers screen: an import button drawn only by `catalog.import`, the import dialog
            (the four counts and a link to the incomplete filter, as customers have — reusing
            `CustomerImportModal`'s parts if they are shareable, searched before writing), the
            `is_incomplete` chip and filter, ar/en lang keys. `NoHardCodedTextTest`,
            `rtl-ui-verifier` (`/suppliers`, AR/EN × desktop/375 px, computed border on every new
            control), `waste-auditor`.
            *(2026-09-21, #177 — the customers' dialog is shared as `components/imports/ImportModal.vue`, owner's choice (a))*
      - [x] **1.6** Manual test list for F-09 in Arabic — roles named (the Manager imports; a role
            without `catalog.import` sees no button and gets a 403), a sample `.csv` with complete,
            incomplete and nameless rows, AR/EN × desktop/375 px.
            *(2026-09-21, #178 — 25 checks; sample file (a) proved on the test database: 6 / 3 / 1 / 3)*

      #### قائمة الاختبار اليدوي — F-09 *(النقطة 1.6، 2026-09-21)*

      > **F-09 إصلاح لا وحدة**، فالقائمة تغطّي `D-85` وحده: المورّدون يُستوردون من ملف CSV كما يُستورد
      > العملاء، والمورّد الذي ينقصه `type` أو `phone` أو `contact_person` يُحفظ **ويُعلَّم ناقصًا**
      > (`D-31`). نُفِّذ في #173 (العمود والجدول) و#174 (قارئ CSV المشترك) و#175 (`POST /suppliers/import`
      > تحت `catalog.import`) و#177 (الشاشة: الزرّ والنافذة المشتركة والمرشّح والشارة، ثمّ موضع الزرّ من
      > لقطة المالك).
      >
      > ⚠️ **بيانات التطوير كما قرأتها قاعدة البيانات في 2026-09-21 — صحّحها منها لا من الذاكرة:**
      > **٤ مورّدين** كلّهم مفعَّلون: `Nile Pumps Co` (ممتاز، بلا نوع ولا هاتف — **غير** معلَّم، لأنّه
      > أُدخل باليد والعلَم يضعه المستورِد وحده)، `new` (متوسط)، و`F09 verify complete` و`F09 verify
      > incomplete` من فحص #177 المتصفّحي. **الأخير عدّله أحدٌ بعد الاستيراد** (التقييم أحمر، هاتف ومسؤول
      > تواصل) **وما زال معلَّمًا ناقصًا** — هذا هو الدَّين «العلَم لا يُزال» مرئيًّا، لا عيبًا جديدًا (الفحص 16).
      > دفعة استيراد واحدة في `supplier_import_batches` (`verify.csv`، ٣ / ٢ / ١).
      >
      > ⚠️ **الأدوار** (`<role>@example.test`، كلمة المرور `Passw0rd123`). **`catalog.import.all` للمدير
      > وحده** في قاعدة البيانات (لا منحة وقت تشغيل له عند غيره). `catalog.manage.all` لستّة أدوار:
      > المدير، المشتريات، المبيعات الداخلية والخارجية، مشرف الخارجي، قائد الفريق (`D-45`) — **فالمشتريات
      > هو الدور السلبيّ الأوضح: يكتب المورّد باليد ولا يستورد.** الرئيس التنفيذي `catalog.view.all` وحده
      > (لا «مورّد جديد» ولا استيراد). **المشرف الأعلى** نفاذه غير مشروط (§3.1) فيرى الزرّ. **لا دور يُرفض من
      > `/suppliers` نفسها** — كلّ دور يملك `catalog.view` — فلا فحص لصفحة `/403` هنا.
      >
      > ⚠️ **الملفّات.** أنشئها بمحرّر نصّ عادي (TextEdit ⇒ Format ⇒ Make Plain Text، واحفظ بترميز
      > UTF-8 وامتداد `.csv`). **استورد الملف (أ) مرّة واحدة فقط**: لا كشف تكرار، فاستيراده مرّتين يصنع
      > نسختين من كلّ مورّد (مُثبت على قاعدة الاختبار: ٣ ⇒ ٦).
      >
      > **(أ) `f09-sample.csv`** — ستّة صفوف، والنتيجة المُثبتة: **المقروءة ٦ · المستورَدة ٣ · الناقصة ١ ·
      > غير المستورَدة ٣**:
      > ```
      > name,type,phone,contact_person,has_open_account
      > F09 test complete,supplier,0100,Sara,yes
      > F09 test incomplete,,,,
      > ,supplier,0100,Sara,no
      > F09 test wrong type,wholesaler,0100,Sara,yes
      > F09 test wrong account,supplier,0100,Sara,maybe
      > F09 test distributor,Distributor,0122,Omar,
      > ```
      > كامل ⇒ يُحفظ · ناقص ⇒ يُحفظ ويُعلَّم · بلا اسم ⇒ لا يُحفظ · نوع خارج §7.1 ⇒ لا يُحفظ (حكم المالك أ) ·
      > كلمة حساب مفتوح خارج `1,0,true,false,yes,no` ⇒ لا يُحفظ · `Distributor` بحرف كبير ⇒ يُحفظ `distributor`
      > وخانة الحساب الفارغة «لا» **بلا** علَم.
      >
      > **(ب) `f09-colour.csv`:** `name,color_rating` ثمّ `F09 colour,green` — مرفوض كلّه (`D-19`).
      > **(ج) `f09-noname.csv`:** `phone` ثمّ `0100` — مرفوض كلّه.
      > **(د) `f09-empty.csv`:** ملف فارغ تمامًا — مرفوض كلّه.
      >
      > ⚠️ **الشبكة.** افتح أدوات المطوّر ⇒ تبويب الشبكة ورشّح على `suppliers` قبل كلّ مجموعة.

      **أ) الزرّ والأدوار — `/suppliers`** *(D-85 · §3.7 · SEC-07)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 1 | مدير | افتح «الموردون» ⇒ «استيراد الموردين» (زرّ ثانوي بإطار) **بجانب** «مورّد جديد» (أساسي ملوَّن)، لا في منتصف الصفّ | D-85 · §6.2 · #177 |
      | 2 | مشتريات | افتح «الموردون» ⇒ «مورّد جديد» ظاهر و**لا** «استيراد الموردين» | D-85 · SEC-07 |
      | 3 | مشتريات | في الصفحة نفسها افتح Console والصق السطر أدناه ⇒ `403` و`permission_denied` ورسالة `catalog.import` — الخادم يرفض لا الزرّ وحده | D-85 · SEC-09 |
      | 4 | الرئيس التنفيذي | افتح «الموردون» ⇒ القائمة تظهر **بلا** «مورّد جديد» و**بلا** «استيراد الموردين» | §3.7 |
      | 5 | المشرف الأعلى | افتح «الموردون» ⇒ «استيراد الموردين» ظاهر (نفاذ غير مشروط) | §3.1 |

      سطر الفحص 3 (Console، وأنت مسجَّل بدور المشتريات):
      ```js
      const f = new FormData(); f.append('file', new File(['name\nX'], 'x.csv', { type: 'text/csv' }));
      fetch('/api/v1/suppliers/import', { method: 'POST', headers: { Accept: 'application/json', Authorization: 'Bearer ' + localStorage.getItem('crm.auth.token.v1') }, body: f }).then(async r => console.log(r.status, await r.json()));
      ```

      **ب) نافذة الاستيراد — الملف (أ)** *(D-85 · D-31 · §6.1 · §6.3)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 6 | مدير | اضغط «استيراد الموردين» ⇒ نافذة عنوانها «استيراد الموردين» (لا «استيراد العملاء»)، ونصّ «ملفات CSV فقط، حتى 30 ميغابايت.»، و«لم يُختر ملف»، وزرّ «استيراد» **معطَّل** | §6.3 · #177 |
      | 7 | مدير | اضغط «إلغاء» ⇒ تُغلق؛ افتحها ثانية واضغط Esc ⇒ تُغلق | §6.1 |
      | 8 | مدير | افتحها، اختر `f09-sample.csv` ⇒ اسم الملف يظهر، «استيراد» يُفعَّل؛ اضغطه ⇒ «جارٍ الاستيراد…» ثمّ «انتهى الاستيراد»: **الصفوف المقروءة: 6 · المستورَدة: 3 · المُعلَّمة ناقصة: 1 · غير المستورَدة: 3** (أرقام غربية، `D-70`) | D-85 · D-31 |
      | 9 | مدير | في الشبكة ⇒ `POST /api/v1/suppliers/import` ⇒ `201`، ثمّ `GET /api/v1/suppliers` (القائمة تُطلب من جديد) | §5.2 |
      | 10 | مدير | خلف النافذة ⇒ العدد «7 موردًا» (4 + 3)؛ لا صفّ باسم `F09 test wrong type` ولا `F09 test wrong account` ولا صفّ بلا اسم | D-85 حكم (أ) |
      | 11 | مدير | اضغط «اعرض السجلّات الناقصة» ⇒ النافذة تُغلق، «السجلات الناقصة فقط» مؤشَّرة، والطلب يحمل `filter[is_incomplete]=true` ⇒ صفّان فقط: `F09 test incomplete` و`F09 verify incomplete`، وبجانب كلّ اسم شارة «سجل ناقص» | §10 · D-85 |

      **ج) الرفض بكلمات الخادم — الملفّات (ب)(ج)(د)** *(§6.1 · D-19)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 12 | مدير | استورد `f09-colour.csv` ⇒ تحت الحقل بالأحمر: «يحتوي هذا الملف على أعمدة لا يقبلها الاستيراد: color_rating.»، اسم الملف **باقٍ**، «استيراد» ما زال مفعَّلًا؛ أغلق ⇒ العدد لم يتغيّر | D-19 · §6.1 |
      | 13 | مدير | استورد `f09-noname.csv` ⇒ «لا يحتوي هذا الملف على عمود «name»، ولا يمكن استيراد **مورّد** بدونه.» — كلمة «مورّد» لا «عميل» | D-85 |
      | 14 | مدير | استورد `f09-empty.csv` ⇒ «هذا الملف فارغ. يجب أن يحمل السطر الأول أسماء الأعمدة.» | D-85 |

      **د) المرشّح والشارة والتقييم** *(D-85 · D-31 · D-19 · §6.4)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 15 | مدير | ألغِ «السجلات الناقصة فقط» ⇒ الطلب **بلا** `filter[is_incomplete]` أصلًا (لا `=false`) ⇒ السبعة كلّهم، والشارة على الناقصَين وحدهما؛ `Nile Pumps Co` بلا شارة رغم نقصه (أُدخل باليد) | D-85 · ثلاثيّ الحالة |
      | 16 | مدير | التقييم: `F09 test complete` و`F09 test incomplete` و`F09 test distributor` كلّها «غير مُقيَّم»؛ `F09 test distributor` نوعه «موزّع» وحسابه المفتوح «لا» | D-19 · §7.1 |
      | 17 | مدير | اضغط «تعديل» على `F09 test incomplete` ⇒ النموذج **لا** يحمل حقلًا للنقص؛ أضِف النوع والهاتف ومسؤول التواصل واحفظ ⇒ الشارة **باقية** — دَين مسجَّل «العلَم لا يُزال»، لا عيب | D-85 «غير مغطّى» |
      | 18 | مدير | فعّل المرشّح وابحث عن `zzz` ⇒ «لا مورد مطابق» (حالة الفراغ المرشَّح، لا «لا شيء هنا بعد») | فارغ |
      | 19 | مدير | الشبكة ⇒ زرّ أيمن على طلب `/api/v1/suppliers` ⇒ «Block request URL»، ثمّ غيّر المرشّح ⇒ حالة الخطأ «حدث خطأ ما.» وزرّ «إعادة المحاولة»؛ ألغِ الحجب واضغطها ⇒ القائمة تعود | خطأ |

      **هـ) العملاء — النافذة نفسها صارت مشتركة** *(#177 · Module 3 Point 4.6)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 20 | مدير | «العملاء» ⇒ «استيراد العملاء» ⇒ العنوان «استيراد العملاء» والنصوص نفسها (CSV، 30 ميغابايت، «استيراد»)؛ «إلغاء» **دون** استيراد | انحدار #177 |

      **و) اللغتان والعرض** *(RTL/LTR · 375 بكسل)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 21 | مدير | بدّل إلى EN ⇒ «Import suppliers» بجانب «New supplier»، مرشّح «Incomplete records only»، شارة «Incomplete record» بعد الاسم يسارًا؛ النافذة: «CSV files only, up to 30 MB.»، «Rows read»، «Imported»، «Flagged incomplete»، «Not imported»، «Show the incomplete records»؛ الاتّجاه LTR | EN · LTR |
      | 22 | مدير | بالإنجليزية أعِد الفحص 12 بالملف (ب) ⇒ «This file has columns the importer does not accept: color_rating.» | EN · §6.1 |
      | 23 | مدير | 375 بكسل بالعربية ⇒ الزرّان معًا في سطر تحت العنوان، النافذة داخل الشاشة، الشارة داخل خانة الاسم | 375 · RTL |
      | 24 | مدير | 375 بكسل بالإنجليزية ⇒ الشيء نفسه محاذًى لليسار | 375 · LTR |
      | 25 | مشتريات | 375 بكسل بالإنجليزية ⇒ «New supplier» وحده، بلا «Import suppliers» | دور ثانٍ · EN · 375 |

      **ما لا يمكن اختباره اليوم — ولماذا**
      - **العلَم لا يُزال أبدًا** (الفحص 17): §11 يقول «حتى يُستكمل»، والدَّين مسجَّل لعملاء ومورّدين معًا.
      - **لا كشف تكرار ولا `.xlsx`** (`D-85` «غير مغطّى»): استيراد الملف مرّتين يضاعف المورّدين.
      - **منتقي المورّد في «عروض المورّدين»** ما زال `<select>` مسقوفًا بـ100 — دَين #167؛ صار بلوغه ممكنًا
        بعد استيراد يتجاوز المئة، وأمره للمالك.
      - **حالة التحميل داخل النافذة** سريعة جدًّا محلّيًّا لتُرى بالعين؛ يغطّيها اختبار المكوّن.
      - **ملف فوق 30 ميغابايت** (`D-71`): يغطّيه `SupplierImportEndpointTest`، ولا داعي لصنع ملف بهذا الحجم.
      - **صفحة `/403` للموردين:** كلّ دور يملك `catalog.view`، فلا دور يصلها.
      - **`D-85` ما زال «مقترحًا»** في §2 (سطر 156) حتّى يقلبه المالك؛ القائمة تختبر ما بُني لا ما اعتُمد.
      - **أثر الفحص:** بعد القائمة تبقى في قاعدة التطوير ثلاثة مورّدين `F09 test …` ودفعة ثانية — يُعطَّلون ولا يُحذفون (DB-01).


- [x] **F-10** Catalog items cannot be imported, and nothing records which supplier carries an item
      (Module 4). Owner's request, agreed in conversation before F-08 and numbered on 2026-09-21 (first
      written in the F-09 item above). The decision is **`D-86`** (proposed). It takes up the debt row "The two imports carry
      three identical shapes…" as its first code point, because a catalog import would be the third copy.

      **The owner's rulings (2026-09-21, in conversation: Q0–Q9, then four follow-ups A1–A4):**
      1. **A new link between a catalog item and a supplier, with no price** (Q1). §7.3 keeps the catalog
         "descriptive data only — no prices"; prices stay on supplier quotations. **Many suppliers per
         item, and editable by hand on the item's form**, every change audited (A3). An import row adds
         zero or one link.
      2. **One CSV with a `kind` column** (Q2). Columns: `kind`, `product_code`, `name`, `category`,
         `unit`, `service_type`, `description`, `company`, `notes`, `is_active`, `supplier` (Q3). An empty
         `is_active` means active.
      3. **Rejected and counted** (Q4): an unknown or missing `kind`; a product with no `name`; a `unit`,
         `service_type` or `company` that is not in its managed list (A1: the import **never creates** a
         list value, although the form adds an unknown company, so an unknown company is rejected); a
         value longer than its column; a `supplier` that matches no supplier or more than one (Q7).
      4. **Saved and flagged incomplete** (Q5, A1, `D-31`): a product with no `unit`, a service with no
         `service_type`, any row with no `company` — what the form requires. The «سجل ناقص» chip and the
         incomplete filter come with it, and **an edit that completes the item clears the flag from the
         start** (`D-87`'s clear-only shape, not a later fix).
      5. **Matching** (A2): a list value matches its code or either label, and a supplier matches by
         name, **after trimming and ignoring case**; active and deactivated suppliers both count. A blank
         `supplier` saves the item with no link (Q7).
      6. **Every row creates a new item** — no update, no skip (Q6).
      7. **`catalog.import` stays**, and which roles hold it is the administrator's call, the Manager being
         only the default (Q8).
      8. **Out of F-10, registered:** duplicate detection on import; a per-row list of rejected rows; the
         form accepting off-list units and service types (A4). Three debt rows, 2026-09-21.

      **Gaps found while drafting (measured 2026-09-21, not recalled):**
      1. **Q8 already works; only the document lags.** `catalog.import` is a matrix row seeded to the
         Manager (`PermissionMatrix.php:374`); in dev the Manager alone holds it. `PATCH
         /roles/{role}/permissions` (`admin.manage_roles`) grants or revokes it, the matrix screen lists it
         under «الكتالوج والموردون», every role but the Super Admin is editable (`RolePayload.php:55`), and
         the only ungrantable rows are `customer.delete` and `catalog.delete` (`ListPermissions.php:26`).
         **No code point is needed.** But §3.7's table (doc line 279) prints no import row, although `D-85`
         says the row is "in §3.7" — the owner pastes it with `D-86`.
      2. **`company` fills its list from use on the form** (`SaveCatalogItem::withListedCompany`, owner's
         ruling 2026-08-31); the import does not (ruling 3). The two paths differ on purpose.
      3. **The form does not check `unit` or `service_type` against their lists**
         (`SaveCatalogItemRequest.php:104,107`); the import will. Registered as debt (ruling 8).
      4. **No module publishes a supplier lookup.** `deptrac.modules.yaml` has no `SuppliersContract` layer;
         Catalog cannot read a supplier by name today without reaching into another module. Point 1.4.
      5. **`product_code` has no unique index**, so ruling 6 needs no schema change to hold.
      6. **The existing item↔supplier relation is the priced one:** `supplier_quotation_items.catalog_item_id`.
         It stays as it is; the new link is separate and carries no price or quantity.
      7. **`catalog_items` has no `is_incomplete`.** A new column defaults to false, so no existing row is
         flagged and F-10 needs **no correction command** (unlike F-11 · 1.4).
      8. **The two imports' shared reader already exists** (`App\Support\Csv\CsvReader`, F-09 · 1.3); what is
         still copied is the summary, its payload and the upload request (the debt row above).

      **Not covered by F-10:** duplicate detection; per-row rejection reasons; `.xlsx`; importing supplier
      prices or quantities (they stay on supplier quotations); re-flagging (`D-87` ruling 2); the form's
      off-list values; the Arabic-Indic dates (F-12, not ordered).

      Each point is its own branch, one per turn, seven-part report, owner's merge.

      ### F-10 point list — published 2026-09-21, approved by merging #184

      - [x] **1.1** `D-86` in §2 (proposed) + this block + the three debt rows. Docs only — the `D-86` row
            and §3.7's `import` row are pasted by the owner, as `D-85` and `D-87` were.
            *(2026-09-21, #184 — both rows' text is in #184's description)*
      - [x] **1.2** The shared import shapes move to `App\Support\Csv`: the summary, its payload and the
            upload request, used by Customers and Suppliers. No behaviour change: every existing import
            test passes unchanged, deptrac 0 violations both configs. Closes the debt row.
            `waste-auditor` (the old classes are deleted, not left beside the new).
            *(2026-09-22, #185 — **amended by the owner before coding:** the summary stays per module (deptrac:
            `Domain` ↛ `SharedContracts`); `ImportFileRequest` + `ImportBatchPayload::of()` shared, the field
            named by `validation.attributes.file`; suite 2981/18640)*
      - [x] **1.3** Schema, reversible: `catalog_items.is_incomplete` (default false);
            `catalog_item_suppliers` (item, supplier, standard columns, soft delete, FKs, one live row per
            pair); `catalog_import_batches` owned by Catalog. RED first: `migrate:rollback` round trip;
            the pair constraint refuses a second live link.
            *(2026-09-22, #186 — `2026_09_22_000000_add_catalog_import_columns_and_links`; 17 tests, 2 mutants caught;
            FKs no-action, no `supplier_id`-only index (`ponytail:`); dev: 0 of 7 items flagged)*
      - [x] **1.4** A supplier lookup Suppliers publishes (`Domain/Contracts`, its own deptrac layer):
            the ids matching a name (trimmed, any case, active or not), and names for ids. RED first: 0, 1
            and 2 matches; spaces and case; a deactivated supplier found.
            *(2026-09-22, #187 — `SupplierLookupInterface` in layer `SuppliersContract`, granted to Suppliers and
            Catalog; 8 tests, 5 mutants + 1 deptrac mutant caught; soft-deleted suppliers not found)*
      - [x] **1.5** `POST /catalog-items/import` under `catalog.import`: rulings 2–6 in one transaction, an audit
            row per item and per link, one batch row. RED first, one test per rejection and per flag
            reason, a linked and an unlinked row, `is_active` empty = active, a list value by code and by
            label, and no managed-list row added. `permission-matrix-auditor`.
            *(2026-09-22, #188 — path under `/catalog-items` and `CATALOG_ITEM_SUPPLIER_LINKED` on the link row, owner's
            answers of 2026-09-22 (the `D-86` path is corrected by the owner); unknown `is_active` word rejected; a list
            value stored as its code, the code winning over a label; 45 tests, 11 mutants + 1 deptrac mutant caught)*
      - [x] **1.6** An edit that completes a flagged item clears the flag (`D-87`'s shape): clear-only, in
            the same `CATALOG_ITEM_UPDATED` audit row; `is_incomplete` in the payload and
            `filter[is_incomplete]`, prohibited in a write. RED first: the three clear-only cases.
            *(2026-09-22, #189 — complete = a product's `unit` / a service's `service_type` + `company`, judged by the
            `kind` after the edit (owner, 2026-09-22); a bad filter value is `OpenAPI §6.1`'s 400; 8 tests, 7 mutants caught)*
      - [x] **1.7** Links by hand: the item payload lists its suppliers; the save request takes the full
            set of supplier ids and replaces it; the change is audited with old and new. Under
            `catalog.manage`. RED first: add, remove, unchanged set writes no link audit, an unknown id is
            a 422, a role without `catalog.manage` is refused. `permission-matrix-auditor`.
            *(2026-09-22, #190 — `supplier_ids` is the full set (absent = untouched, `[]` = unlink all); one audit row per
            changed link, `CATALOG_ITEM_SUPPLIER_UNLINKED` soft-deletes it; a deactivated supplier may be linked, unknown or
            soft-deleted is a 422 (owner, 2026-09-22); `suppliers` on the single-item payload only; 15 tests, 10 mutants caught)*
      - [x] **1.8** The catalog screen: the import button drawn by `catalog.import` (the shared
            `ImportModal`), the incomplete filter and chip, the item's suppliers, and a supplier picker in
            the form. Lang keys AR/EN, `NoHardCodedTextTest`, `rtl-ui-verifier` (AR/EN × desktop/375 px),
            `waste-auditor`.
            *(2026-09-22, #191 — import button by `catalog.import` on the shared `ImportModal`; incomplete filter + chip; suppliers
            picked with a server-searched `SupplierPicker` (chips, contact · phone · inactive), after the owner rejected one checkbox per
            supplier; `supplier_ids` sent only when the set changed, never when the item's read failed; chip not seen on screen (no
            flagged row in dev data — 1.9's `.csv` makes one); 18 mutants caught)*
      - [x] **1.9** Manual test list for F-10 in Arabic — roles named (who imports, who edits, who is
            refused), a sample `.csv` covering every ruling, AR/EN × desktop/375 px. Closes F-10.
            *(2026-09-22, #192 — 44 checks; sample file (a) proved on the test database: 18 / 8 / 3, 4 links; files (b)–(e) refused)*

      #### قائمة الاختبار اليدوي — F-10 *(النقطة 1.9، 2026-09-22)*

      > **F-10 إصلاح لا وحدة**، فالقائمة تغطّي `D-86` وأحكامك (Q0–Q9، A1–A4، وأحكام 1.5–1.7): عناصر الكتالوج
      > تُستورد من ملف CSV واحد، والعنصر الذي ينقصه ما يطلبه النموذج يُحفظ **ويُعلَّم ناقصًا** (`D-31`) ويزول
      > العلَم حين يُستكمل (`D-87`)، ولكل عنصر موردون يُربطون بالاستيراد أو باليد. نُفِّذ في #184 (القائمة
      > والقرار) و#185 (القارئ المشترك) و#186 (جدول الرابط والعمود) و#187 (بحث المورد عبر عقد) و#188
      > (`POST /catalog-items/import`) و#189 (إزالة العلَم) و#190 (الرابط باليد) و#191 (الشاشة ومنتقي الموردين بالبحث).
      >
      > ⚠️ **بيانات التطوير كما قرأتها قاعدة البيانات في 2026-09-22 — صحّحها منها لا من الذاكرة:**
      > **7 منتجات و0 خدمات**، لا عنصر ناقص ولا معطَّل، **6 روابط** (3 على `Submersible pump 5HP`: «Alex Pipes
      > Trading» و«Cairo Valves Co» مرّتين؛ 3 على `opmflksl`)، ولا دفعة استيراد للكتالوج. القوائم: الوحدات
      > `piece` «قطعة» · `metre` «متر» · `kilo` «كيلو»؛ أنواع الخدمة `installation` «تركيب» · `repair` «إصلاح» ·
      > `maintenance` «صيانة» · `setup` «تجهيز»؛ الشركات `adidas` «اديداس» · `pawlar`. **17 موردًا مفعَّلًا
      > كلّهم، بينهم أسماء مكرّرة**: «Cairo Valves Co» و«Delta Electric Supplies» و«الشرق للتوزيع» و«المتحدة
      > للتوريدات» و«شركة النيل للمضخات» كلٌّ مرّتين. **تركتَ التكرار عمدًا (2026-09-22)**، ويُختبر في الفحص 17.
      >
      > ⚠️ **الأدوار** (`<role>@example.test`، كلمة المرور `Passw0rd123`). **`catalog.import.all` للمدير وحده.**
      > `catalog.manage.all` لستّة أدوار: المدير، المشتريات، المبيعات الداخلية والخارجية، مشرف الخارجي، قائد
      > الفريق — **فالمشتريات هو من يعدّل ولا يستورد**. الرئيس التنفيذي `catalog.view.all` وحده. المشرف الأعلى
      > نفاذه غير مشروط (§3.1).
      >
      > ⚠️ **التحضير قبل الملف (أ):** لا مورد معطَّل في بيانات التطوير، وحكم A2 يحتاج واحدًا — الفحص 0 يعطّل
      > «Giza Tools Store». **استورد الملف (أ) مرّة واحدة فقط**: لا كشف تكرار، فاستيراده مرّتين يضاعف العناصر.
      >
      > ⚠️ **الملفّات.** أنشئها بمحرّر نصّ عادي (TextEdit ⇒ Format ⇒ Make Plain Text، واحفظ بترميز UTF-8 وامتداد
      > `.csv`). الفاصلة المنقوطة `;` تعمل أيضًا، وكذلك ملف يبدأ بـ BOM كالذي يحفظه Excel (مُثبت).
      >
      > **(أ) `f10-sample.csv`** — 18 صفًّا، والنتيجة المُثبتة على قاعدة الاختبار: **المقروءة 18 · المستورَدة 8 ·
      > الناقصة 3 · غير المستورَدة 10**، و**4 روابط** لكلٍّ منها سطر تدقيق:
      > ```
      > kind,product_code,name,category,unit,service_type,description,company,notes,is_active,supplier
      > product,F10-001,Copper cable 2mm,Cables,metre,,Copper wire,adidas,,,Alex Pipes Trading
      > product,F10-002,Steel pipe 1in,Pipes,Metre,,,Adidas,,YES,  alex pipes trading
      > product,F10-003,Water pump 1HP,Pumps,قطعة,,,اديداس,,no,Nile Pumps Co
      > product,F10-004,Valve 2in,Valves,,,,pawlar,,,
      > service,F10-005,,,,installation,Site installation,pawlar,,,
      > service,F10-006,Pump repair,,,,,,,,Giza Tools Store
      > product,F10-007,Drill bits set,Tools,piece,,,,,true,
      > SERVICE,F10-008,Annual maintenance,,,Maintenance,,adidas,,0,
      > tool,F10-009,Hammer,,piece,,,adidas,,,
      > ,F10-010,No kind,,piece,,,adidas,,,
      > product,F10-011,,Pipes,piece,,,adidas,,,
      > product,F10-012,Box,,box,,,adidas,,,
      > service,F10-013,Cleaning,,,cleaning,,adidas,,,
      > product,F10-014,Gloves,,piece,,,Acme,,,
      > product,F10-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX,Long code item,,piece,,,adidas,,,
      > product,F10-016,Sensor,,piece,,,adidas,,maybe,
      > product,F10-017,Relay,,piece,,,adidas,,,Unknown Supplier Ltd
      > product,F10-018,Gate valve,,piece,,,adidas,,,Cairo Valves Co
      > ```
      > **المحفوظة (8):** 001 كامل بالكود ومربوط · 002 الوحدة والشركة بعنوانهما الإنجليزي و`YES` واسم المورد بحروف
      > صغيرة ومسافات ⇒ `metre` و`adidas` ومفعَّل ومربوط · 003 الوحدة والشركة بعنوانهما العربي و`no` ⇒ `piece`
      > و`adidas` و**غير مفعَّل** · 004 منتج بلا وحدة ⇒ **ناقص** · 005 خدمة بلا اسم ⇒ تُحفظ كاملة · 006 خدمة بلا نوع
      > ولا شركة ⇒ **ناقصة** ومربوطة بمورد **معطَّل** (A2) · 007 بلا شركة ⇒ **ناقص** · 008 `SERVICE` بحروف كبيرة
      > و`Maintenance` و`0` ⇒ خدمة `maintenance` غير مفعَّلة.
      > **المرفوضة (10):** 009 `kind` مجهول · 010 `kind` فارغ · 011 منتج بلا اسم · 012 وحدة ليست في القائمة ·
      > 013 نوع خدمة ليس في القائمة · 014 شركة ليست في القائمة (الاستيراد **لا يضيف** قيمة، A1) · الكود ذو 65 حرفًا
      > (الحدّ 64) · 016 كلمة `maybe` في `is_active` · 017 مورد لا وجود له · **018 مورد يطابقه سجلّان** (Q7).
      >
      > **(ب) `f10-price.csv`:** `kind,name,price` ثمّ `product,Cable,10` — مرفوض كلّه.
      > **(ج) `f10-noname.csv`:** `kind,unit,company` ثمّ `product,piece,adidas` — مرفوض كلّه.
      > **(د) `f10-empty.csv`:** ملف فارغ تمامًا — مرفوض كلّه.
      > **(هـ) `f10-twice.csv`:** `kind,name,Name` ثمّ `product,Cable,Cable` — مرفوض كلّه.
      >
      > ⚠️ **الشبكة.** افتح أدوات المطوّر ⇒ تبويب الشبكة ورشّح على `api/v1` قبل كلّ مجموعة.

      **أ) التحضير والزرّ والأدوار — `/suppliers` ثمّ `/catalog`** *(D-86 · §3.7 · SEC-07)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 0 | مدير | «الموردون» ⇒ «تعديل» على «Giza Tools Store» ⇒ ألغِ «مفعَّل» واحفظ ⇒ حالته «غير مفعَّل» (تحضير لحكم A2) | تحضير |
      | 1 | مدير | افتح «الكتالوج» ⇒ «استيراد من CSV» (زرّ ثانوي) **بجانب** «منتج جديد» (أساسي ملوَّن)؛ العدد «7 منتجًا» | D-86 · §6.2 |
      | 2 | مشتريات | افتح «الكتالوج» ⇒ «منتج جديد» ظاهر و**لا** «استيراد من CSV» | D-86 · SEC-07 |
      | 3 | مشتريات | في الصفحة نفسها افتح Console والصق السطر أدناه ⇒ `403` — الخادم يرفض لا الزرّ وحده | D-86 · SEC-09 |
      | 4 | الرئيس التنفيذي | افتح «الكتالوج» ⇒ القائمة تظهر **بلا** «منتج جديد» و**بلا** «استيراد من CSV» و**بلا** «تعديل» | §3.7 |
      | 5 | المشرف الأعلى | افتح «الكتالوج» ⇒ «استيراد من CSV» ظاهر (نفاذ غير مشروط) | §3.1 |

      سطر الفحص 3 (Console، وأنت مسجَّل بدور المشتريات):
      ```js
      const f = new FormData(); f.append('file', new File(['kind,name\nproduct,X'], 'x.csv', { type: 'text/csv' }));
      fetch('/api/v1/catalog-items/import', { method: 'POST', headers: { Accept: 'application/json', Authorization: 'Bearer ' + localStorage.getItem('crm.auth.token.v1') }, body: f }).then(async r => console.log(r.status, await r.json()));
      ```

      **ب) نافذة الاستيراد — الملف (أ)** *(D-86 · D-31 · §6.1 · §6.3)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 6 | مدير | اضغط «استيراد من CSV» ⇒ نافذة عنوانها «استيراد من CSV»، ونصّ «ملفات CSV فقط، حتى 30 ميغابايت.»، و«لم يُختر ملف»، وزرّ «استيراد» **معطَّل**؛ «إلغاء» يغلقها | §6.3 · §6.1 |
      | 7 | مدير | افتحها، اختر `f10-sample.csv`، اضغط «استيراد» ⇒ «جارٍ الاستيراد…» ثمّ «انتهى الاستيراد»: **الصفوف المقروءة: 18 · المستورَدة: 8 · المُعلَّمة ناقصة: 3 · غير المستورَدة: 10** | D-86 · D-31 |
      | 8 | مدير | في الشبكة ⇒ `POST /api/v1/catalog-items/import` ⇒ `201`، ثمّ `GET /api/v1/catalog-items…` (القائمة تُطلب من جديد) | §5.2 |
      | 9 | مدير | اضغط «اعرض السجلّات الناقصة» ⇒ النافذة تُغلق، «السجلات الناقصة فقط» مؤشَّرة، والطلب يحمل `filter[is_incomplete]=true` ⇒ في «المنتجات» صفّان: «Valve 2in» و«Drill bits set»، وبجانب كلٍّ شارة «سجل ناقص» | D-31 · D-86 |
      | 10 | مدير | والمرشّح مؤشَّر، انتقل إلى «الخدمات» ⇒ صفّ واحد «Pump repair» بشارة «سجل ناقص» | D-31 · A1 |
      | 11 | مدير | ألغِ المرشّح ⇒ الطلب **بلا** `filter[is_incomplete]` أصلًا ⇒ «المنتجات»: «12 منتجًا» (7 + 5)، «الخدمات»: «3 خدمة»؛ لا عنصر بكود F10-009 إلى F10-018 ولا بالكود الطويل | D-86 · Q4 · Q6 |

      **ج) كيف قرأ المستورِد القيم — افتح «تعديل» على كلّ عنصر ثمّ «إلغاء»** *(أحكام A2 و1.5)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 12 | مدير | «Copper cable 2mm» (F10-001) ⇒ الوحدة «متر»، الشركة «adidas»، مفعَّل، وتحت «الموردون الذين يوفّرون هذا العنصر» شريحة «Alex Pipes Trading» | D-86 · مطابقة بالكود |
      | 13 | مدير | «Steel pipe 1in» (F10-002) ⇒ الوحدة «متر» (كُتبت `Metre`)، الشركة «adidas» (كُتبت `Adidas`)، مفعَّل (`YES`)، شريحة «Alex Pipes Trading» (كُتب بحروف صغيرة ومسافات) | A2 · 1.5 |
      | 14 | مدير | «Water pump 1HP» (F10-003) ⇒ الوحدة «قطعة» والشركة «adidas» (كُتبتا بالعربية)، **غير مفعَّل** (`no`)، شريحة «Nile Pumps Co» | A2 · 1.5 |
      | 15 | مدير | «الخدمات» ⇒ صفّ بلا اسم («—») كوده F10-005 نوعه «تركيب»، **بلا** شارة؛ و«Annual maintenance» (F10-008، كُتب `SERVICE`) نوعه «صيانة» وحالته «غير مفعَّل» | D-86 · 1.5 |
      | 16 | مدير | «Pump repair» (F10-006) ⇒ شريحة «Giza Tools Store» — مورد **معطَّل** رُبط (الفحص 0) | A2 |
      | 17 | مدير | لا عنصر «Gate valve»: السطر 018 سمّى «Cairo Valves Co» ويطابقه **سجلّان** في بيانات التطوير، فرُفض السطر كلّه | Q7 · قرارك 2026-09-22 |

      **د) الرفض بكلمات الخادم — الملفّات (ب)(ج)(د)(هـ)** *(§6.1)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 18 | مدير | استورد `f10-price.csv` ⇒ تحت الحقل بالأحمر: «يحتوي هذا الملف على أعمدة لا يقبلها الاستيراد: price.»، اسم الملف **باقٍ**؛ أغلق ⇒ العدد لم يتغيّر | D-86 · §6.1 |
      | 19 | مدير | استورد `f10-noname.csv` ⇒ «لا يحتوي هذا الملف على عمود «name». كل ملف للكتالوج يحتاجه، ولو تركته خدمةٌ فارغًا.» | D-86 |
      | 20 | مدير | استورد `f10-empty.csv` ⇒ «هذا الملف فارغ. يجب أن يحمل السطر الأول أسماء الأعمدة.» | D-86 |
      | 21 | مدير | استورد `f10-twice.csv` ⇒ «يسمّي هذا الملف الحقل نفسه في أكثر من عمود: name.» | D-86 |

      **هـ) التعديل: زوال العلَم والموردون باليد** *(D-87 · D-86 · A3 · §5.2)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 22 | مدير | «تعديل» على «Valve 2in» ⇒ اختر الوحدة «قطعة» واحفظ ⇒ **تزول** شارة «سجل ناقص» | D-87 · 1.6 |
      | 23 | مدير | «تعديل» على «Drill bits set» ⇒ الشركة «pawlar» واحفظ ⇒ تزول الشارة | D-87 · A1 |
      | 24 | مدير | «الخدمات» ⇒ «تعديل» على «Pump repair» ⇒ نوع الخدمة «إصلاح» والشركة «adidas» واحفظ ⇒ تزول الشارة؛ الشريحة «Giza Tools Store» **باقية** | D-87 · 1.6 |
      | 25 | مدير | «تعديل» على «Copper cable 2mm» ⇒ اضغط حقل «ابحث عن مورد بالاسم…» ⇒ طلب `GET /api/v1/suppliers?per_page=20` وقائمة تحت كلّ اسم فيها سطر «المسؤول · الهاتف» | D-86 · §6.3 |
      | 26 | مدير | اكتب `nile` ⇒ بعد توقّف قصير طلب يحمل `q=nile`؛ اضغط «Nile Pumps Co» ⇒ تظهر شريحة وعلامة ✓، والقائمة **تبقى مفتوحة** | OpenAPI §6.2 |
      | 27 | مدير | اضغط × على شريحة «Alex Pipes Trading» ثمّ «حفظ» ⇒ في الشبكة `PATCH` جسمه `supplier_ids` فيه معرّف واحد؛ افتح «تعديل» ثانية ⇒ شريحة «Nile Pumps Co» وحدها | 1.7 · A3 |
      | 28 | مدير | اكتب `cai` ⇒ «Cairo Valves Co» **مرّتين بالسطر نفسه** «Karim Nabil · 01534567890» — سجلّان متطابقان تركتهما عمدًا، ولا شاشة تفرّق بينهما | قرارك 2026-09-22 |
      | 29 | مدير | اكتب `giza` ⇒ «Giza Tools Store» وسطرها ينتهي بـ «غير مفعَّل»؛ اكتب `zzz` ⇒ «لا نتائج لـ «zzz»» | A2 · فارغ |
      | 30 | مدير | اكتب اسمًا عربيًّا مثل `النيل` ⇒ تظهر «شركة النيل للمضخات» | SearchService |
      | 31 | مدير | أضِف أيّ مورد ثمّ «إلغاء» ⇒ «هذا النموذج يحتوي على تغييرات لم تُحفظ.»؛ «تجاهل التغييرات» ⇒ يُغلق **بلا** `PATCH` | §5.2 |
      | 32 | مدير | افتح قائمة البحث واضغط Esc ⇒ تُغلق **القائمة وحدها** والنموذج باقٍ؛ Esc ثانية ⇒ يُغلق النموذج (لا تغيير) | WAI-ARIA · §6.1 |
      | 33 | مشتريات | «تعديل» على «Valve 2in» ⇒ أضِف «Alex Pipes Trading» واحفظ ⇒ يُحفظ — المشتريات يعدّل الموردين ولا يستورد | A3 · SEC-07 |

      **و) الحالات: الفراغ والخطأ** *(Design §6)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 34 | مدير | أشِّر «السجلات الناقصة فقط» وابحث عن `zzz` ⇒ «لا نتيجة مطابقة» (حالة الفراغ المرشَّح) | فارغ |
      | 35 | مدير | الشبكة ⇒ زرّ أيمن على طلب `/api/v1/suppliers` ⇒ «Block request URL»، ثمّ في نموذج عنصر اضغط حقل البحث ⇒ «تعذّر تحميل الموردين» وزرّ «إعادة المحاولة»؛ ألغِ الحجب واضغطه ⇒ القائمة تعود | خطأ |
      | 36 | مدير | احجب طلب `GET /api/v1/catalog-items/<id>` لعنصر واحد ثمّ افتح «تعديل» عليه ⇒ «تعذّر تحميل موردي العنصر؛ يبقون كما هم.» و**لا** حقل بحث؛ غيّر الفئة واحفظ ⇒ `PATCH` **بلا** `supplier_ids`؛ ألغِ الحجب وافتحه ⇒ شرائحه كما كانت | 1.8 · لا فكّ بالخطأ |

      **ز) اللغتان والعرض** *(RTL/LTR · 375 بكسل)*

      | # | الدور | الفعل ⇒ النتيجة | المعيار |
      |---|---|---|---|
      | 37 | مدير | بدّل إلى EN ⇒ «Import from CSV» بجانب «New product»، مرشّح «Incomplete records only»، شارة «Incomplete record» بعد الاسم؛ النافذة: «CSV files only, up to 30 MB.»، «Rows read»، «Imported»، «Flagged incomplete»، «Not imported»، «Show the incomplete records»؛ الاتّجاه LTR | EN · LTR |
      | 38 | مدير | بالإنجليزية في النموذج ⇒ «Suppliers carrying this item» و«Search suppliers by name…»، وسطر «Giza Tools Store» ينتهي بـ «Inactive» | EN |
      | 39 | مدير | بالإنجليزية أعِد الفحص 18 بالملف (ب) ⇒ الرسالة بالإنجليزية وتنتهي بـ «price.» | EN · §6.1 |
      | 40 | مدير | 375 بكسل بالعربية ⇒ الزرّان في سطر تحت العنوان، النافذة داخل الشاشة، الشارة داخل خانة الاسم؛ في النموذج الشرائح تلتفّ أسطرًا، والقائمة تنفتح **تحت** الحقل داخل النافذة غير مقصوصة | 375 · RTL |
      | 41 | مدير | 375 بكسل بالإنجليزية ⇒ الشيء نفسه محاذًى لليسار | 375 · LTR |
      | 42 | مشتريات | 375 بكسل بالإنجليزية ⇒ «New product» وحده، بلا «Import from CSV» | دور ثانٍ · EN · 375 |
      | 43 | مدير | 375 بكسل ⇒ زرّ × في الشريحة وكلّ خيار في القائمة يسهل لمسه بالإصبع (44 بكسل على الأقل) | §6.1 · لمس |

      **ما لا يمكن اختباره اليوم — ولماذا**
      - **سجلّات التدقيق** (رابط، فكّ رابط، إزالة العلَم): لا شاشة للتدقيق في الواجهة بعد. مُثبت على قاعدة الاختبار
        (4 أسطر `CATALOG_ITEM_SUPPLIER_LINKED` للملف أ) وفي اختبارات 1.5–1.7؛ ويُقرأ من قاعدة التطوير عند الطلب.
      - **«الكود يسبق عنوان مدخل آخر»** و**«عنوان يحمله مدخلان»**: قوائم التطوير بلا تصادم كهذا؛ يغطّيهما
        `CatalogImportEndpointTest`.
      - **الاكتمال بحسب النوع بعد التعديل** (1.6): النموذج لا يغيّر نوع عنصر موجود؛ يغطّيه اختبار الخادم.
      - **لا كشف تكرار ولا `.xlsx` ولا أسباب لكلّ صفّ مرفوض** (`D-86` «غير مغطّى»، دَينان مسجَّلان): الملخّص يعدّ
        المرفوض ولا يسمّيه.
      - **رسالة «ليست لديك صلاحية عرض الموردين»**: `GET /suppliers` يحرسه `catalog.view`، وكلّ من يعدّل يملكه.
      - **ملف فوق 30 ميغابايت** (`D-71`): يغطّيه `CatalogImportEndpointTest`.
      - **375 بكسل بالعربية:** النافذة كلّها مزاحة نحو 30 بكسل عن الحافة اليسرى — دَين الشريط الجانبي المسجَّل، لا عيب جديد.
      - **`D-86` ما زال «مقترحًا»** في §2 (سطر 158) حتّى يقلبه المالك؛ القائمة تختبر ما بُني.
      - **أثر الفحص:** تبقى في قاعدة التطوير 8 عناصر `F10-…` ودفعة استيراد، و«Giza Tools Store» معطَّلًا — تُعطَّل
        ولا تُحذف (DB-01)، وأعِد تفعيل المورد إن شئت.

- [x] **F-11** The «سجل ناقص» / «Incomplete record» flag never clears once the record is completed
      (Modules 3 and 4). Owner's request, 2026-09-21, after running F-09: `Alex Pipes Trading` was
      imported without a phone, edited to add one at 17:11, and still carries the flag. **Numbering:**
      the owner gave F-11 to this fix; the Arabic-Indic dates candidate that the F-09 heading called
      "F-11" (never ordered) moves to **F-12**. `D-86` stays reserved for F-10, so the decision is
      **`D-87`** (proposed). This item takes up the debt row "Nothing clears `is_incomplete` once an
      import sets it — customers and suppliers alike", which closes when F-11 closes.

      **The owner's three rulings (2026-09-21, in conversation):**
      1. **A customer is complete when `name`, `sector`, `region`, `contact_person` and `phone` are
         filled** — core fields, not all ten. `ImportCustomers` today flags a row when *any* of
         `CustomerDraft::WRITABLE`'s ten fields is empty (its own docblock calls that reading
         undocumented and awaiting a `D-xx`); the importer narrows to the same five, so import and
         edit agree. A supplier is complete when `type`, `phone` and `contact_person` are filled —
         `D-85`'s rule, unchanged.
      2. **Clear only, never set.** An edit that leaves every core field filled clears the flag; an
         edit that empties one does not set it. `D-31` makes the flag the importer's, and a record
         typed by hand is never incomplete.
      3. **A one-off correction** clears the flag on existing records that are already complete, with
         an audit entry per cleared row.

      **Gaps found while drafting (measured 2026-09-21, not recalled):**
      1. **Only the importer writes the flag:** `SupplierDraft::forImport` (`SupplierDraft.php:82`)
         and `CustomerDraft::forImport` (`CustomerDraft.php:89`). `SaveSupplierRequest.php:70` and
         `SaveCustomerRequest.php:77` prohibit the field, and neither `SaveSupplier::update` nor
         `SaveCustomer::update` recomputes it.
      2. **The customers' completeness rule is the ten-field reading**
         (`ImportCustomers.php:30`, `$flagged = count($attributes) < count(CustomerCsv::COLUMNS)`).
         Ruling 1 narrows it; a file that fills the five core fields and leaves `email` empty stops
         being flagged. That is a behaviour change to the customers' import, stated rather than hidden.
      3. **The clear must be audited:** `AUD-02` wants old and new values. The flag change goes into
         the same `SUPPLIER_UPDATED` / `CUSTOMER_UPDATED` row as the edit that caused it
         (`is_incomplete: true → false`), not a second event.
      4. **Dev data, from `crm-postgres`:** 6 suppliers flagged, **2 already complete** (`Alex Pipes
         Trading`, `F09 verify incomplete`) — the correction clears those two. 234 customers flagged,
         **0 complete under the core rule** (233 have no `sector`, 234 no `region`) — the correction
         changes no customer today, and that is the correct result, not a failed run.
      5. **The screens need no new control:** the list and the detail page re-read the record after a
         save (`SuppliersView.vue` `onSaved` → `load()`; `CustomersView.vue:315` and
         `CustomerDetailView.vue:142` → `load()`), so the chip disappears once the server clears the
         flag. To be seen in the browser at 1.2 and 1.3, not assumed from this reading.
      6. **Module 13** excludes flagged records from financial reports (§11, doc line 838); this makes
         that exclusion end when the record is completed, as §11 says. Nothing in Module 13 exists yet.

      **Not covered by F-11:** re-flagging (ruling 2); what "complete" means for a record that was
      never imported (it is never flagged, so the question does not arise); duplicate detection;
      the Arabic-Indic dates (F-12, not ordered).

      Each point is its own branch, one per turn, seven-part report, owner's merge.

      ### F-11 point list — published 2026-09-21, approved by merging #179

      - [x] **1.1** `D-87` in §2 (proposed) + this list. Docs only — the decision row is pasted by the
            owner, as `D-82` … `D-85` were, because the guard hook refuses an agent write to
            `CRM_Documentation_EN.md`. *(2026-09-21, #179 — the `D-87` row itself was still not in the
            master doc when 1.2 started; 1.2 cites it as proposed.)*
      - [x] **1.2** Suppliers: `SaveSupplier::update` clears `is_incomplete` when the saved row has
            `type`, `phone` and `contact_person` filled; clear-only; the change is in the
            `SUPPLIER_UPDATED` audit row's old/new. RED first: completing a flagged supplier clears it;
            a partial edit keeps it; emptying a field on a complete supplier does not set it; the audit
            row carries `is_incomplete`. `rtl-ui-verifier` (the chip goes after a save), `waste-auditor`.
            *(2026-09-21, #180 — `SupplierDraft::EXPECTED` is the one list, read by the importer and the
            edit; the browser check cleared 4 dev suppliers through the real API, so 1.4's dev expectation
            is now 0 suppliers, not 2.)*
      - [x] **1.3** Customers: one core-field list (`name`, `sector`, `region`, `contact_person`,
            `phone`) used by `ImportCustomers` **and** `SaveCustomer::update`; clear-only; audited in
            `CUSTOMER_UPDATED`. RED first: an import row missing only `email` is not flagged; one
            missing `region` is; completing a flagged customer clears it; the three clear-only cases
            as 1.2. `CustomerImportEndpointTest` updated where ruling 1 changes its expectation, named
            case by case. `rtl-ui-verifier`, `waste-auditor`.
            *(2026-09-21, #181 — `CustomerDraft::EXPECTED`; no existing import assertion flipped, only the
            Plan-international comment; the browser check completed 4 dev customers, so 1.4's dev
            expectation stays 0 customers.)*
      - [x] **1.4** The one-off correction: an idempotent artisan command per module (Suppliers,
            Customers) that clears the flag on already-complete rows, one audit row each, a second run
            changing nothing. RED first: a complete flagged row is cleared and audited; an incomplete
            one is untouched; a second run writes no audit row. **After merging, the owner runs it
            once** (as `RolePermissionSeeder` after #175); expected in dev: 2 suppliers, 0 customers.
            *(2026-09-21, #182 — `suppliers:clear-incomplete` and `customers:clear-incomplete`; no logic of
            their own: an empty edit through `SaveSupplier`/`SaveCustomer::update` with a null (system)
            actor, the J-15 shape; `updated_by` and the audit `user_id` are null. Dev expectation is now
            **0 and 0** — 1.2/1.3's browser checks completed the rows through the real API.)*
      - [x] **1.5** Manual test list for F-11 in Arabic — complete a flagged supplier and customer and
            watch the chip go; a partial edit keeps it; the correction's run (0 and 0 in dev); AR/EN ×
            desktop/375 px. Closes F-11 and the debt row.
            *(2026-09-21, #183 — 24 checks; import sample proved in a rolled-back transaction: 2 / 2 / 1 / 0)*

      #### قائمة الاختبار اليدوي — F-11 *(النقطة 1.5، 2026-09-21)*

      > **F-11 إصلاح لا وحدة**، فالقائمة تغطّي `D-87` وحده: علَم «سجل ناقص» الذي يضعه المستورِد (`D-31`)
      > **يُزال** حين يكتمل السجلّ بالتعديل، و**لا يُوضع أبدًا** بالتعديل. المورّد مكتمل بـ`type` و`phone`
      > و`contact_person` (`D-85`)؛ العميل مكتمل بالحقول الخمسة `name` و`sector` و`region` و`contact_person`
      > و`phone` (الحكم 1)، والمستورِد يقرأ الخمسة نفسها. نُفِّذ في #180 (المورّدون) و#181 (العملاء
      > والمستورِد) و#182 (أمرا التصحيح). **لا تغيير في الشاشات**: الشارة تختفي لأنّ الشاشة تعيد القراءة بعد
      > الحفظ.
      >
      > ⚠️ **بيانات التطوير كما قرأتها قاعدة البيانات في 2026-09-21 مساءً — صحّحها منها لا من الذاكرة:**
      > **19 مورّدًا، 2 معلَّمان:** `Giza Tools Store` (بلا نوع ولا مسؤول تواصل، هاتفه `01099998888`) و`مخازن
      > الصفا` (بلا هاتف). **234 عميلًا، 231 معلَّمًا، ولا واحد منها مكتمل** — 231 بلا منطقة و229 بلا قطاع،
      > وكلّها فيها جهة الاتصال والهاتف. الثلاثة غير المعلَّمين هم نُسخ `ASPPC` الثلاث (مكتملة).
      > في سجلّ التدقيق 4 صفوف `SUPPLIER_UPDATED` و3 `CUSTOMER_UPDATED` تحمل `is_incomplete` من فحوص
      > المتصفّح في #180/#181، **وليس فيها صفّ بلا مستخدم** — أي أنّ تشغيل أمري التصحيح لم يجد ما يصحّحه.
      >
      > ⚠️ **الأدوار** (`<role>@example.test`، كلمة المرور `Passw0rd123`). المورّد: **`catalog.manage.all`**
      > لستّة أدوار (المدير، المشتريات، المبيعات الداخلية والخارجية، مشرف الخارجي، قائد الفريق). العميل:
      > **`customer.edit`** للمدير وقائد الفريق (`all`)، ومشرف الخارجي (`out`)، والمبيعات الداخلية والخارجية
      > (`own`). **الرئيس التنفيذي يرى الشاشتين ولا يعدّل في أيّ منهما** (`catalog.view.all` و`customer.view.all`
      > وحدهما)، فهو الدور المرفوض في هذه القائمة. استيراد العملاء `customer.import.all`: المدير وقائد الفريق.
      >
      > ⚠️ **الترتيب مهمّ:** الفحوص تغيّر بيانات التطوير (تُكمل مورّدَين وعميلًا وتستورد عميلَين)، فالأعداد
      > المتوقَّعة مكتوبة لهذا الترتيب. **استورد الملف مرّة واحدة**: لا كشف تكرار.
      >
      > ⚠️ **أين يُرى التدقيق:** لا شاشة لسجلّ التدقيق بعد. الفحصان 10 و19 يُقرآن من قاعدة البيانات
      > (`crm-postgres` أو `psql`)، وهذا مذكور فيهما.

      **أ. أمر التصحيح — قبل أيّ تعديل** *(من الطرفية، في `crm/`)*

      1. شغّل `docker compose exec -T php php artisan suppliers:clear-incomplete` ⇒ يجب أن ترى
         `suppliers:clear-incomplete — 0 of 2 flagged suppliers cleared.` — **0 هي النتيجة الصحيحة**: المورّدان
         المعلَّمان ناقصان فعلًا، والأمر لا يُزيل إلّا علَم سجلّ مكتمل (الحكم 3).
      2. شغّل `docker compose exec -T php php artisan customers:clear-incomplete` ⇒
         `customers:clear-incomplete — 0 of 231 flagged customers cleared.` — لا عميل معلَّم مكتمل اليوم.
      3. أعد تشغيل الأمرين ⇒ السطران نفساهما حرفيًّا، ولا صفّ تدقيق جديد (الأمر متساوي الأثر).

      **ب. المورّدون `/suppliers`** *(المدير — `manager@example.test`، بالعربية، سطح المكتب)*

      4. افتح `/suppliers` ثمّ فعّل «السجلات الناقصة فقط» ⇒ يجب أن ترى صفَّين فقط، `Giza Tools Store` و`مخازن
         الصفا`، وعلى كلّ منهما شارة «سجل ناقص».
      5. اضغط «تعديل» على `Giza Tools Store` واختر النوع فقط (مورّد) ثمّ «حفظ» ⇒ **الشارة باقية** والصفّ باقٍ
         تحت المرشّح: ما زال بلا مسؤول تواصل (الحكم 2 — تعديل جزئيّ لا يُزيلها).
      6. عدّله مرّة ثانية واكتب مسؤول تواصل (أيّ اسم) ثمّ «حفظ» ⇒ **الشارة تختفي** والصفّ يخرج من المرشّح؛
         أطفئ المرشّح ⇒ الصفّ موجود بلا شارة.
      7. عدّل `مخازن الصفا` واكتب هاتفًا ثمّ «حفظ» ⇒ الشارة تختفي، والمرشّح يعرض الحالة الفارغة «لا مورد مطابق» —
         لا مورّد معلَّم.
      8. عدّل المورّد `new` (مكتمل، غير معلَّم) وامسح هاتفه ثمّ «حفظ» ⇒ **لا شارة تظهر** — التعديل لا يضع
         العلَم أبدًا (الحكم 2، `D-31`). أعد الهاتف `01068161659` بعدها.
      9. أعد تحميل الصفحة (F5) بعد 6 و7 و8 ⇒ النتائج نفسها: الشارة من الخادم لا من الشاشة.
      10. *(قاعدة البيانات)* `select event, old_values, new_values from audit_log where entity_id = (select id from
          suppliers where name = 'Giza Tools Store') order by created_at desc limit 2` ⇒ الصفّ الأحدث
          `SUPPLIER_UPDATED` فيه `is_incomplete` من `true` إلى `false` مع مسؤول التواصل، والذي قبله (الفحص 5)
          **ليس فيه** `is_incomplete` (`AUD-02`: الحقول التي تغيّرت وحدها).

      **ج. العملاء `/customers` و`/customers/{id}`** *(المدير، بالعربية، سطح المكتب)*

      11. افتح `/customers` وفعّل «السجلات الناقصة فقط» ⇒ القائمة تعرض المعلَّمين (231). **لا شارة في جدول
          العملاء** — المرشّح وحده يدلّ هنا، والشارة في صفحة العميل.
      12. افتح `Al Yousr Hospital` ⇒ في رأس الصفحة شارة «سجل ناقص».
      13. «تعديل» واكتب المنطقة فقط (مثلًا «القاهرة») ثمّ «حفظ» ⇒ **الشارة باقية**: ما زال بلا قطاع.
      14. «تعديل» واختر قطاعًا ثمّ «حفظ» ⇒ **الشارة تختفي**؛ عُد إلى `/customers` بالمرشّح ⇒ `Al Yousr
          Hospital` لم يعد فيها (230).
      15. افتح إحدى نسخ `ASPPC` (مكتملة) وامسح منطقتها ثمّ «حفظ» ⇒ **لا شارة** (الحكم 2). أعد المنطقة كما
          كانت (`الإسكندرية` أو `القاهرة` أو `Alexandria`).
      16. من جدول `/customers` اضغط «تعديل» على عميل معلَّم آخر وأكمل القطاع والمنطقة ثمّ «حفظ» ⇒ يخرج من
          المرشّح (229) — المسار نفسه من القائمة لا من الصفحة وحدها.

      **د. استيراد العملاء** *(المدير — «استيراد العملاء» على `/customers`)*

      > **`f11-sample.csv`** — أنشئه بمحرّر نصّ عادي (TextEdit ⇒ Format ⇒ Make Plain Text، UTF-8):
      > ```
      > name,sector,region,contact_person,phone,email
      > F11 test complete,medical,Cairo,Sara,0100,
      > F11 test no region,medical,,Sara,0100,
      > ```

      17. استورد `f11-sample.csv` ⇒ «انتهى الاستيراد»: **الصفوف المقروءة 2 · المستورَدة 2 · المُعلَّمة ناقصة 1 ·
          غير المستورَدة 0**.
      18. فعّل «السجلات الناقصة فقط» ⇒ `F11 test no region` فيها و**`F11 test complete` ليس فيها** — ينقصه
          البريد وحده، والبريد ليس حقلًا أساسيًّا. **هذا تغيير سلوك مقصود** (الحكم 1): قبل #181 كان يُعلَّم.
      19. *(قاعدة البيانات)* `select name, is_incomplete from customers where name like 'F11 test%'` ⇒ `complete`
          = `false`، `no region` = `true`. ثمّ افتح `F11 test no region` واكتب منطقة ⇒ الشارة تختفي.

      **هـ. الرفض بالصلاحية والخطأ**

      20. ادخل **الرئيس التنفيذي** (`ceo@example.test`) وافتح `/suppliers` ثمّ `/customers/{id}` لعميل
          معلَّم ⇒ الشارة ظاهرة، و**لا زرّ «تعديل»** في أيّ منهما — لا طريق لإزالة العلَم لمن لا يعدّل
          (`SEC-07`).
      21. *(المدير)* افتح عميلًا معلَّمًا و«تعديل» واملأ القطاع والمنطقة، ثمّ أدوات المطوّر ⇒ Network ⇒
          **Offline** واضغط «حفظ» ⇒ «تعذّر الوصول إلى الخادم. لم يتم حفظ العميل.»؛ أعد Network إلى
          No throttling وأعد التحميل ⇒ الشارة **باقية** — لا نجاح وهميّ من الشاشة. *(لا تُوقف `php`: 502
          من nginx رسالة أخرى.)*

      **و. اللغتان والعرضان**

      22. بدّل إلى الإنجليزية ⇒ على `/suppliers` المرشّح «Incomplete records only» والحالة الفارغة بالإنجليزية
          (لا مورّد معلَّم بعد 7)؛ على `/customers` افتح عميلًا معلَّمًا ⇒ الشارة «Incomplete record»؛ أكمل
          قطاعه ومنطقته ⇒ تختفي. الاتجاه من اليسار إلى اليمين.
      23. عرض 375 px (أدوات المطوّر ⇒ جهاز)، بالعربية ثمّ بالإنجليزية، على `/suppliers` وصفحة عميل ⇒ الشارة
          مقروءة لا مقطوعة، والنافذة تُحفظ منها. *(قطع 375 px في رأس الواجهة دين معروف ولا يخصّ F-11.)*
      24. **أخيرًا** أعد تشغيل أمري التصحيح ⇒ `0 of 0 flagged suppliers cleared.` و`0 of 228 flagged customers
          cleared.` إن مشيت القائمة كما هي (231، ناقص 3 أُكملت في 14 و16 و22، زائد 1 من الاستيراد، ناقص 1 أُكمل في 19 = 228)؛ وإلّا فـN هو عدد المرشّح على
          `/customers`، ويبقى الرقم الأوّل 0.

      **ما لا تغطّيه القائمة، صراحةً:** إعادة وضع العلَم (الحكم 2 — خارج F-11)؛ ما يعنيه «مكتمل» لسجلّ أُدخل
      باليد (لا يُعلَّم أصلًا)؛ كشف التكرار في الاستيراد؛ **عمود القطاع في `/customers` يطبع الرمز
      (`medical`) لا اسمه** — دَين مسجَّل ويبقى مفتوحًا؛ استبعاد المعلَّمين من التقارير المالية (§11) — الوحدة 13
      لم تُبنَ بعد؛ والتواريخ بالأرقام العربية الهندية (F-12، غير مطلوبة).

- [x] **F-13** «عروض الموردين» searches offers by the deal's internal UUID, which nobody knows, and shows
      that UUID in its deal column (Module 6). Owner's request, 2026-09-22, "last change before module 10":
      search by the deal's code instead. The owner gave it **F-13** (F-12 stays the unordered dates
      candidate). The decision is **`D-88`** (proposed).

      **The owner's rulings (2026-09-22, in conversation):**
      1. **The search box and the deal column change; the offer form does not.** The form's deal field
         keeps taking a UUID — a debt row, not part of F-13.
      2. **A partial code matches**: `0003` or `2026-00` finds every offer whose deal's code contains it,
         any case, surrounding spaces ignored.
      3. **An offer with no deal is excluded** from a code search (it has no code to match); the column
         keeps saying «بلا صفقة» for it.
      4. **The code joins the deals search index**, so the search goes through `SearchService` like every
         other search — and the **deals screen's own search box then finds a deal by its code too**
         (accepted as a side effect).

      **Measured 2026-09-22, not recalled:**
      1. `SupplierQuotationListCriteria` (`:185-186`) filters on `supplier_id` and `deal_id` only, both UUIDs;
         the screen's box (`SupplierQuotationsView.vue:267`) is a raw text input whose own comment calls it a
         ceiling "Module 5 replaces with a picker" — Module 5 closed without replacing it.
      2. The payload carries `deal_id` alone (`SupplierQuotationPayload.php:40`); the column prints it raw.
      3. `SearchIndex::Deals` indexes `title` only (`SearchIndex.php:77`), so no search finds a deal by code today.
      4. Deals already publishes `DealFactsInterface` in the `DealsContract` deptrac layer, granted to
         Quotations; **SupplierQuotations is not granted it**, and may not read `deals` directly.
      5. Dev data: 12 deals (`DL-2026-0001`…), 5 offers, 2 of them on a deal.

      **Not covered by F-13:** the offer form's deal field (debt row); a deal picker; searching offers by
      anything else of the deal (title, customer).

      ### F-13 point list — published 2026-09-22, approved in conversation; recorded by merging this point

      - [x] **1.1** `D-88` in §2 (proposed) + this block + the form's debt row. Docs only — the `D-88` row is
            pasted by the owner, as `D-86` was.
            *(2026-09-22, #193 — the `D-88` row's text and its paste script are in the PR description)*
      - [x] **1.2** Deals: `code` joins `SearchIndex::Deals`; `DealFactsInterface` gains a code fragment ⇒ deal
            ids (through `SearchService`) and deal ids ⇒ codes (one page, no query for `[]`); `DealsContract`
            granted to SupplierQuotations. RED first; mutants, and a deptrac mutant.
            *(2026-09-22, #194 — a blank fragment throws, as `SearchService` does; 1.3 validates the empty filter)*
      - [x] **1.3** SupplierQuotations API: `filter[deal_code]` (partial, trimmed, case-insensitive; with the
            supplier filter and the row scope; an offer with no deal never matches) and `deal_code` on the list
            and the single offer. RED first. `permission-matrix-auditor`.
            *(2026-09-22, #196 — one `codesOf` per page; create/update responses carry no `deal_code`)*
      - [x] **1.4** The screen: the box becomes «رمز الصفقة» (placeholder `DL-2026-0003`), the column shows the
            code, lang keys AR/EN. `rtl-ui-verifier` (AR/EN × desktop/375 px), `waste-auditor`.
            *(2026-09-22, #197 — the filter has its own placeholder key; the form modal keeps `filter.dealPlaceholder`)*
      - [x] **1.5** Manual test list for F-13 in Arabic. Closes F-13.
            *(2026-09-22, #198 — 27 checks; opens with the data it needs, since the dev database was reset the same day)*

      #### قائمة الاختبار اليدوي — F-13 *(النقطة 1.5، 2026-09-22)*

      > **F-13 إصلاح لا وحدة**، فالقائمة تغطّي `D-88` وحده (مقترَح): البحث في «عروض الموردين» برمز
      > الصفقة بدل معرّفها الداخلي، وعرض الرمز في عمود الصفقة. نُفِّذ في #194 (فهرس الصفقات وعقد
      > `DealFactsInterface`)، و#196 (`filter[deal_code]` و`deal_code` في الواجهة البرمجية)، و#197 (الشاشة).
      > **الأحكام الأربعة** التي تتحقّق منها القائمة: (1) الصندوق والعمود يتغيّران، ونموذج العرض لا —
      > حقل الصفقة فيه ما زال معرّفًا داخليًّا (دَين `~1053`)؛ (2) جزء من الرمز يكفي، بأيّ حالة أحرف،
      > والمسافات حوله تُهمَل؛ (3) العرض بلا صفقة لا يظهر في بحثٍ بالرمز، وعموده يبقى «غير مرتبط»
      > (النصّ المشحون، لا «بلا صفقة» كما كُتب الحكم)؛ (4) الرمز دخل فهرس بحث الصفقات، فصندوق بحث
      > شاشة الصفقات يجد الصفقة برمزها أيضًا.
      >
      > ⚠️ **قاعدة بيانات التطوير فارغة** منذ إعادة الضبط في 2026-09-22 (`migrate:fresh --seed`):
      > لا عملاء ولا صفقات ولا مورّدين ولا عروض. المجموعة (أ) تُنشئ ما تحتاجه القائمة، **وبهذا الترتيب**
      > لأنّ الرموز تُولَّد تلقائيًّا: أوّل صفقة تأخذ `DL-2026-0001` والثانية `DL-2026-0002`، وأوّل عرض
      > `SQ-2026-0001`. إن كانت القاعدة غير فارغة عندك فاقرأ الرموز الفعليّة من الشاشة وبدّلها أدناه.
      >
      > ⚠️ **الأدوار** (`<role>@example.test`، كلمة المرور `Passw0rd123`). عرض العروض `supplier_quotation.view`
      > (§3.6): المدير وقائد الفريق والمبيعات الداخلية والخارجية والمشتريات والرئيس التنفيذي — **شاشة
      > مشتركة، لا نطاق ملكيّة**. **مشرف الخارجي (`outdoor.supervisor`) لا يرى العروض أصلًا**، فهو الدور
      > المرفوض. **الرئيس التنفيذي يرى ولا ينشئ**. الصفقات (§3.4): المبيعات الخارجية `Own` — وهذا يهمّ
      > الفحص 22 فقط.
      >
      > ⚠️ **معرّف الصفقة لنموذج العرض** (الحكم 1): افتح الصفقة من `/deals` وانسخ الجزء الأخير من عنوان
      > الصفحة `/deals/<uuid>` — هذا هو ما يُلصَق في حقل «الصفقة» في نموذج العرض. لا طريقة أخرى اليوم.

      **أ. تجهيز البيانات** *(المدير — `manager@example.test`، بالعربية، سطح المكتب)*

      1. افتح `/customers` ثمّ «عميل جديد» واحفظ عميلًا باسم `Nisco` (الاسم والقطاع والمنطقة وجهة
         الاتصال والهاتف) ⇒ يظهر في القائمة.
      2. افتح `/deals` ثمّ «صفقة جديدة»، اختر `Nisco` واكتب العنوان «Server room» واحفظ ⇒ الصفقة تحمل
         الرمز **`DL-2026-0001`** (مولَّد، لا يُكتب). كرّر بعنوان «Generators» ⇒ **`DL-2026-0002`**.
      3. افتح `/suppliers` ثمّ «مورّد جديد» واحفظ `Nile Pumps Co` (النوع مورّد، مسؤول تواصل، هاتف) ⇒ يظهر.
      4. افتح `/supplier-quotations` ثمّ «عرض جديد»: المورّد `Nile Pumps Co`، تاريخ العرض اليوم، الإجمالي
         `4500`، العملة `EGP`، وفي حقل «الصفقة» الصق معرّف `DL-2026-0001` (انظر التنبيه أعلاه) ثمّ «حفظ»
         ⇒ صفّ `SQ-2026-0001` وعموده «الصفقة» يعرض **`DL-2026-0001`** لا المعرّف.
      5. كرّر بعرض ثانٍ على `DL-2026-0001` (الإجمالي `3000`) ⇒ `SQ-2026-0002`، وعرض ثالث على
         `DL-2026-0002` ⇒ `SQ-2026-0003`، وعرض رابع **بلا صفقة** (اترك الحقل فارغًا) ⇒ `SQ-2026-0004`
         وعموده **«غير مرتبط»** (الحكم 3).
      6. الصق في حقل «الصفقة» نصًّا ليس معرّفًا (مثلًا `DL-2026-0001`) واحفظ ⇒ **رفض تحت الحقل**: النموذج
         يقبل المعرّف الداخلي وحده (الحكم 1 — الدَّين المعلَن، لا عيب في F-13).

      **ب. صندوق «رمز الصفقة» `/supplier-quotations`** *(المدير، بالعربية، سطح المكتب)* — *#197، `D-88`*

      7. افتح `/supplier-quotations` ⇒ فوق الجدول صندوق عنوانه **«رمز الصفقة»** وعنصره النائب
         `DL-2026-0003`، ولا صندوق «معرّف الصفقة». القائمة تعرض العروض الأربعة.
      8. اكتب `0001` ثمّ «تطبيق» ⇒ عرضان فقط (`SQ-2026-0001` و`SQ-2026-0002`)، وعمود الصفقة في كليهما
         `DL-2026-0001` (الحكم 2 — جزء من الرمز يكفي).
      9. اكتب `dl-2026-0002` بأحرف صغيرة ثمّ «تطبيق» ⇒ `SQ-2026-0003` وحده (الحكم 2 — لا فرق في الحالة).
      10. اكتب `  0002  ` بمسافات قبله وبعده ثمّ «تطبيق» ⇒ النتيجة نفسها (الحكم 2 — المسافات تُهمَل).
      11. اكتب `2026-00` ثمّ «تطبيق» ⇒ ثلاثة عروض (كلّ ما على صفقة)، **و`SQ-2026-0004` غائب** (الحكم 3 —
          بلا صفقة فلا رمز يطابق).
      12. اكتب `ZZZZ` ثمّ «تطبيق» ⇒ الحالة الفارغة **«لا توجد عروض مطابقة»** مع «غيّر المرشِّحات أو
          امسحها…»، لا رسالة خطأ.
      13. امسح الصندوق ثمّ «تطبيق» ⇒ العروض الأربعة من جديد؛ `SQ-2026-0004` بعموده «غير مرتبط».
      14. اختر المورّد `Nile Pumps Co` من مرشّح «المورّد» **مع** `0001` في الصندوق ثمّ «تطبيق» ⇒
          العرضان نفسهما (المرشّحان معًا)؛ اختر «كل المورّدين» ⇒ لا تغيير (مورّد واحد في البيانات).
      15. اضغط «تعديل» على `SQ-2026-0001` ⇒ حقل «الصفقة» في النموذج يعرض **المعرّف الداخلي** لا الرمز
          (الحكم 1 — النموذج لم يتغيّر). «إلغاء».
      16. أعد تحميل الصفحة (F5) والصندوق فيه `0001` ⇒ الصندوق يفرغ والقائمة كاملة — المرشّح لا يُحفَظ.

      **ج. الاتجاه والعرض** *(المدير)*

      17. بدّل اللغة إلى English ⇒ العنوان **"Deal code"** والعنصر النائب `DL-2026-0003`، والعمود
          **"Deal"** يعرض الرموز و**"Not linked"** للرابع؛ الفحصان 8 و12 يعطيان النتائج نفسها.
      18. بالعربية، ضيّق النافذة إلى 375 بكسل ⇒ صندوق «رمز الصفقة» وزرّ «تطبيق» ظاهران ويعملان
          (الفحص 8 يُعطي عرضين)؛ **عمود الصفقة مخفيّ** على هذا العرض (`hidden lg:table-cell` — بالتصميم).
          ⚠️ تجاوز العرض الأفقيّ للصفحة كلّها (411–421 من 375) دَين مسجَّل (`~862`) في كلّ شاشة، لا هنا.
      19. بالإنجليزية على 375 بكسل ⇒ الشيء نفسه من اليسار إلى اليمين.

      **د. أثر الفهرس على شاشة الصفقات `/deals`** *(المدير، بالعربية)* — *#194، الحكم 4*

      20. افتح `/deals` واكتب `0002` في صندوق البحث ثمّ طبّق ⇒ `DL-2026-0002` وحدها.
      21. اكتب `Server` ⇒ `DL-2026-0001` وحدها — البحث بالعنوان ما زال يعمل بجانب الرمز.

      **هـ. الأدوار** — *#196، §3.6*

      22. اخرج وادخل **المبيعات الخارجية** (`outdoor.sales@example.test`) وافتح `/supplier-quotations`
          واكتب `0001` ثمّ «تطبيق» ⇒ العرضان نفسهما بعمود `DL-2026-0001` **وإن لم تكن الصفقة له**:
          الشاشة مشتركة (§3.6) والرمز معروض على سابقة `D-83` كما كان المعرّف.
      23. ادخل **الرئيس التنفيذي** (`ceo@example.test`) ⇒ الشاشة والصندوق والعمود كما في (ب)،
          **بلا زرّ «عرض جديد» ولا «تعديل»**.
      24. ادخل **مشرف الخارجي** (`outdoor.supervisor@example.test`) ⇒ **لا عنصر «عروض الموردين» في
          القائمة الجانبية**؛ اكتب `/supplier-quotations` في شريط العنوان ⇒ شاشة الرفض (403)، لا قائمة فارغة.

      **و. الواجهة البرمجية** *(من الطرفية، برمز جلسة المدير)* — *#196*

      25. `GET /api/v1/supplier-quotations?filter[deal_code]=0001` ⇒ عنصران، كلّ منهما يحمل `deal_id`
          **و`deal_code: "DL-2026-0001"`**؛ `?filter[deal_code]=` (فارغ) ⇒ العروض الأربعة (الفراغ يُهمَل)؛
          `?filter[deal_code][]=x` ⇒ **400** برمز `not_a_string`.
      26. `GET /api/v1/supplier-quotations/<id of SQ-2026-0001>` ⇒ `deal_code` موجود؛ استجابة الإنشاء
          في الفحص 4 (أو `PATCH`) **لا تحمل `deal_code`** — مقبول من المالك في 1.3.
      27. *(قاعدة البيانات)* أرشِف `DL-2026-0002` من شاشتها إن كان الأرشيف متاحًا، أو
          `update deals set deleted_at = now() where code = 'DL-2026-0002'` ⇒ `GET …/supplier-quotations` يعطي
          `SQ-2026-0003` بـ`deal_id` **و`deal_code: null`** (صفقة محذوفة ناعمًا بلا رمز). أعد
          `deleted_at = null` بعدها.

      **لا يمكن اختباره بعد**

      - **اختيار الصفقة من قائمة في نموذج العرض** — ليس في F-13 (الحكم 1، دَين `~1053`)؛ الحقل يقبل المعرّف
        الداخلي، والفحصان 4 و6 يثبتان ذلك لا أكثر.
      - **البحث بعنوان الصفقة أو عميلها من شاشة العروض** — «غير مغطّى» في `D-88`.
      - **التدقيق** — F-13 لا يضيف حدث تدقيق (بحث وعرض فقط)، فلا فحص تدقيق هنا.

- [x] **F-14** Quotations publishes a read-only contract for Pdf. Requested by the second developer
      before Module 9 · 1.2: Pdf could be granted only the whole Quotations layer, Eloquent models
      included. The owner filed it as **F-14** and paused F-13 at 1.3 for it (2026-09-22). No `D-xx`:
      it narrows an existing seam (modules communicate through interfaces, `CLAUDE.md`) and changes no
      behaviour.

      **The owner's rulings (2026-09-22):** Pdf's grant of `QuotationsContract` arrives with Module 9 ·
      1.2, per Pdf's own "a grant arrives with the point that uses it"; 1.2's wording names the new
      interface; the list is one point.

      **Not covered by F-14:** the teammate's item 2 (supplier-quotation item ⇒ the catalog item's
      customer-facing description, before Module 9 Step 3). That belongs to Catalog/SupplierQuotations,
      not Quotations, and will be its own contract; `QuotationLine::supplierQuotationItemId` stays as
      its key. `find()` is unscoped, so Pdf applies §3.5's generate scope itself (`DealsContract`).

      ### F-14 point list — published and approved 2026-09-22 in conversation

      - [x] **1.1** `QuotationReaderInterface::find()`; `QuotationDirectoryInterface` extends it; a
            `QuotationsContract` deptrac layer (the reader + `QuotationDetail`, `QuotationLine`,
            `QuotationAdditionalLine`, `QuotationNotFound`), collected out of `Quotations` and granted
            to it. RED first; deptrac mutants. Closes F-14; no screen, so no manual test list.
            *(2026-09-22, #195 — Pdf's grant is Module 9 · 1.2's; a Pdf probe reaches the reader, not the directory)*

- [x] **F-15** *(number proposed; the owner assigns it)* A supplier offer could be saved with priced
      lines and **no currency** (Module 6), and every customer quotation built on it then hit §5.6's
      `supplier_price_missing` — the builder showed «سعر المورّد 900.000» and refused to save. Owner's
      screenshot, 2026-09-22. `D-80` had named this "a correct block on a dead end" and removed only
      the inability to *pick* a currency; nothing *required* one. Root cause: `SaveSupplierQuotationRequest`
      ties `currency_id` to `total_price` alone, never to `items`. The fix is one guard,
      `PricedLinesNeedCurrency`, asked by both write use cases on the **resulting** offer (a `PATCH` of
      `items` alone passes while the stored currency stands; blanking the pair under lines is refused):
      §5.6 "Every amount stores: amount · currency". Because the DB CHECK pairs the columns, the total
      becomes mandatory alongside — **flagged for the owner as the consequence of that reading**. No
      `D-xx` yet.

      **Not covered:** the builder's line meta still prints the supplier price without its currency
      code; offers already saved without a currency (`SQ-2026-0001` on the dev database) are not
      corrected — each needs its currency and total set by hand in «عروض الموردين».

      - [x] **1.1** RED on POST and PATCH (four cases), guard in Create/Update, `ar`/`en` message.
            Closes F-15; no screen change, so no manual test list beyond the two clicks named in the PR.

- [x] **F-16** A customer-quotation line names its product. The owner's report (2026-09-22): the
      detail's lines table and the edit form list lines by number alone. The open debt row "A quotation
      line is unnamed on the wire" (Module 7 · 6.7). Ruling: the server sends the name. No `D-xx`: it
      closes a recorded gap and changes no rule.

      ### F-16 point list — approved 2026-09-22 in conversation

      - [x] **1.1** `items[].product_name` on every quotation-detail response (§7.3 label: `name`, or a
            service's `service_type`), outside §3.5's cost grant. A narrow Catalog contract
            `CatalogItemLabelsInterface` (Quotations → `CatalogContract`); `SupplierItemPrice` carries its
            `catalog_item_id`. Detail table gets a product column; the edit form names each existing line.
            *(2026-09-22, #200 — `product_name` rides `quotation.view`, not the cost grant; null when unnamed)*

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

- [ ] **2.1** `J-01 expire_quotations`: a use case and a job on `maintenance`, daily; `sent` and
      `valid_until` before today in `locale.timezone` ⇒ `expired`, `QUOTATION_EXPIRED` with a system actor,
      idempotent. A `scheduler` service in `docker-compose.yml` runs `J-01` once on start (the `D-55`
      catch-up) and then `schedule:work` — closing the "nothing runs the scheduler" debt row, and from
      then on `J-02` and `J-15` fire in the stack too. No deal move (Q2), no customer status (debt row).
- [ ] **2.2** `GET /purchase-orders` (paginated, scoped through the quotation's deal) with `q` over
      `po_number` **and** `customer_po_reference` through a new `SearchIndex::PurchaseOrders`;
      `GET /purchase-orders/{id}`; the quotation detail names its PO.
- [ ] **2.3** The PO's attachment: `POST /purchase-orders/{id}/documents` under
      `quotation.record_customer_response`, the list of its files, and the download mapping for
      `AttachmentParent::PurchaseOrder` (an unmapped parent is refused today, `ParentAwareAttachmentPermission.php:33-35`);
      `AttachDealDocument`'s shape (validate, store, scan after commit).

#### Step 3 — the screens

- [ ] **3.1** The quotation detail: a *Send* button (`approved`, `quotation.send_to_customer`) and a
      *Record the customer's response* dialog — four outcomes, the reason field for Counter and Rejected,
      the PO reference and date for Accepted; Partial and Counter open the new draft; an `expired`
      quotation offers *Reject* with its reason; `409` shows the refresh message (§10.5).
- [ ] **3.2** "Previous Quotations" in the deal detail (§6.3; the sixth criterion): the deal's quotations
      by version chain, through the existing `GET /quotations?filter[deal_id]`.
- [ ] **3.3** Purchase orders: a list searchable by both numbers, the PO on its quotation, and the upload
      of its attachment.

#### Step 4 — close the module

- [ ] **4.1** Arabic manual test list, freeze to `checklist/module-10.md`, stub here, ownership row.

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
