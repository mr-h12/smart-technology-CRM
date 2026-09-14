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
| **7 — Customer Quotations** | Yousef | in progress — Steps 1–3 closed (3.7 merged 2026-09-12, #102); Step 4 approved 2026-09-12 (#103); 4.1–4.4 (#104–#107) merged, 4.5 on #108 — Step 4 closes with it. *Row added 2026-09-12; the module had been built since 2026-09-07 without one.* |
| **8 — Approvals** | Yousef | **not started — reassigned to Yousef 2026-09-13 by owner direction.** Claimed by the second developer 2026-09-10 and never started; a point list was drafted (PR #94) and is left for the new owner to accept or discard, not merged. *Row written on the owner's instruction, not by the module's owner — the one exception to this table's own claiming rule, recorded as such.* |
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

- [ ] **An offer's currency is unreachable from the SPA, and that now costs a *field* rather than a
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
      accept a currency code. Both are cross-module and neither belongs inside a Module 6 point

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

- [ ] **Six list screens carry the same table boilerplate** — *revealed by Module 7 Point 6.3,
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

- [ ] **The quotation-create screen's "add a new supplier item" button is ruled, not built** — *owner's
      ruling 2026-09-11, recorded by Module 7 Point 3.3.* A quotation line always references a
      `supplier_quotation_item_id`; the screen carries a button atop the supplier-item list that jumps
      to adding a new supplier item and returns. Step 3 is the API, so this belongs to the
      quotation-create **screen** (a later Module 7 frontend point) and is parked here so the ruling
      is not lost between the point that heard it and the point that draws it.

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


---

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

> As a sales employee, I want to build a price quotation for my customer using supplier prices and a
> profit margin, so that I can send it after my Team Leader's approval.

**Tables** `quotations` · `quotation_items` · `quotation_additional_items` · `user_term_suggestions`

**Endpoints**
- [ ] `POST /api/v1/quotations` · `GET /:id`
- [ ] `PATCH /:id/submit-for-approval`
- [ ] `POST /:id/new-version`
- [ ] `GET /api/v1/quotations?group_by=employee|customer`

**Frontend** quotation builder — dynamic suppliers via (+) up to 10 · products per supplier · live
calculation · confirmation preview before saving · SmartTermInput

**Acceptance criteria — the most important in the project**
- [ ] Cost 1000, margin 20% → selling price **1200** automatically
- [ ] Quotation margin 20%, line margin 30% → line uses **30%**
- [ ] Suppliers in different currencies → converted at the FX rate captured at creation, one
      quotation currency
- [ ] Rounding on: total 1234.67 EGP → final total **1235**, `rounding_diff` **0.33**
- [ ] Rounding on: total 1234.678 USD → final total **1234.68** (rounding unit 0.01)
- [ ] Rounding off for the currency → final total keeps full precision, `rounding_diff` **0** (`D-65`)
- [ ] Items 10,000 + delivery 1,000, discount 1%, tax 14% → tax base **9,900**, tax **1,386**
      (discount first per `D-64`; delivery outside the base per `D-62`)
- [ ] Customer flagged tax-exempt, or `tax_percent` null → **no tax line at all**, not a zero line (`D-63`)
- [ ] Quantity above the supplier's recorded amount → **inline red warning**, not a block
- [ ] Product with no recorded price → **save is blocked**
- [ ] Supplier price changed after the quotation was built (Draft) → warning + "refresh prices"
- [ ] Employee and Team Leader edit simultaneously → **409 Conflict**

**Money rules — no exceptions**
- [ ] `Decimal` everywhere; no float touches a price
- [ ] All calculations in the backend
- [ ] No intermediate rounding — final total only, and only when rounding is enabled (`D-65`)
- [ ] Discount subtracted **before** tax, reducing the tax base (`D-64`)
- [ ] Editing an FX rate never alters an existing quotation
- [ ] Unit tests for every formula, rounding boundary, conversion, discount, tax, additional item

#### Step 1 — schema and domain *(shipped 2026-09-07, PRs #83–#89; boxes added 2026-09-12)*

The seven points below shipped one PR each on 2026-09-07 and left only debt-register entries here
— no point list and no boxes — so Steps 2 and 3 cite "Point 1.1 … 1.7" by numbers this file never
carried. Recorded retroactively in the one-line form; whether the step's point list was approved
before the first PR is not recorded anywhere and is not claimed here.

- [x] **1.1** `quotations` table — `D-63`'s nullable `tax_percent`, `D-65`'s rounding snapshot, `version`/`version_token` *(2026-09-07, #83)*
- [x] **1.2** §5.2's money identities as six CHECKs on `quotations` (tax base, net, total-before-round, final total, rounding-off diff, tax null-pairing) *(2026-09-07, #84)*
- [x] **1.3** `quotation_items` — `moneyWithContext('unit_cost')`, `line_no` *(2026-09-07, #85)*
- [x] **1.4** `quotation_additional_items` — never taxed, `D-62` *(2026-09-07, #86)*
- [x] **1.5** `customers.is_tax_exempt` for `D-63` *(2026-09-07, #87)*
- [x] **1.6** `App\Support\Database\DocumentNumberAllocator` extracted from the Deals and Supplier Quotations directories for the `QT-` code *(2026-09-07, #88)*
- [x] **1.7** Quotations domain layer and its Eloquent directory — `QuotationDraft`, `QuotationDirectoryInterface`, `QuotationSummary`, `EloquentQuotationDirectory` *(2026-09-07, #89)*

#### Step 2 — pricing engine *(point list approved 2026-09-07)*

Pure `Quotations/Domain/Pricing/` classes: no framework, no database, and **no import outside
their own namespace**, which `deptrac.layers.yaml`'s empty `Domain` ruleset requires. §5.2's last
two lines are deliberately absent — `Admin\Domain\Money\RoundingRule::apply()` already *is* them
("§5.2's last two lines, and nothing else"), and the rounding acceptance rows already pass against
it in `tests/Feature/Seed/CurrencyMatrixDataTest.php`. Step 3's Application layer composes the
two, which is the crossing `Catalog/Application` already makes; the engine therefore stops at
`total_before_round` and **Step 2 changes neither deptrac configuration**. Every output is brought
to `D-68`'s money scale by truncation — `bcadd($v, '0', 6)`, the idiom `RoundingRule`'s disabled
path already uses — because BCMath truncates where PostgreSQL would round, and the four additive
CHECKs of Point 1.2 must hold exactly at scale 6.

- [x] **2.1** `PricedLine` — §5.1's four formulas: `unit_cost_base = unit_cost × fx_rate_at_time`
      (`D-09`), the margin inheritance (`D-03`), `unit_price = unit_cost_base × (1 + margin / 100)`
      (`D-04`), `line_total`, `line_cost`. **A `null` line margin inherits the quotation's; `'0'`
      does not** — zero is a real margin, the numeric form of the `array_key_exists` distinction
      the drafts already make. A negative margin stays legal, as Point 1.3's schema allows.
      *Verified by* acceptance rows 1 (`1000` at `20%` → `1200.000000`), 2 (a line's `30%` beats
      the quotation's `20%`) and 3 (conversion at the captured rate), plus a truncation row and
      `SCALE` asserted equal to `Precision::MONEY_SCALE` — restated, not imported, exactly as
      `RoundedTotal::SCALE` is and for the same reason.

- [x] **2.2** `QuotationTotals` through the tax base — `subtotal = Σ line_total`,
      `additional_total = Σ amount`, `discount_amount` (`D-07`), and
      `tax_base = subtotal − discount_amount` (`D-64`). **Additional items are summed and then
      kept out of the tax base** (`D-62`, `OD-01`); that exclusion is the one Point 1.2's CHECK
      cannot catch, because the identity it constrains has no `additional_total` term.
      *Verified by* acceptance row 7's first half (items `10,000` + delivery `1,000`, discount
      `1%` → tax base **`9,900`**), a test that fails if delivery enters the base, and the empty
      quotation returning `0.000000` rather than an error.

- [x] **2.3** Tax and the net chain — `tax_amount = tax_base × tax_percent / 100`,
      `net_amount = subtotal + additional_total − discount_amount`,
      `total_before_round = net_amount + tax_amount`. **A null `tax_percent` yields a null
      `tax_amount`, never `'0'`** (`D-63`): `quotations_tax_amount_matches_tax_percent` refuses the
      mixed pair and `quotations_tax_percent_not_zero` refuses a zero percent, so an exempt
      quotation has no tax line at all rather than a zero one. Also extends `DB-07`'s float-token
      scanner over `Domain/Pricing` by giving it a directory list instead of one path.
      *Verified by* acceptance rows 7 (tax **`1,386`**) and 8 (exempt), the two remaining additive
      identities, and §5.2's worked example end to end — `7,368.42` → `total_before_round`
      **`8,315.998812`** → `final_total` **`8,316.000000`**, composed with `RoundingRule` in the
      test only. The document prints `8,315.9988`; the exact scale-6 value carries two more digits
      and the final total is unchanged.

#### Step 3 — the write path *(point list approved 2026-09-07)*

The write half of §6: row scope, the directory's two child tables, the use case that composes
Step 2's engine, and the four `OpenAPI §7.1` routes. The engine stops at `total_before_round`
(Step 2), so **Point 3.3's Application layer** is where `Admin\Domain\Money\RoundingRule::apply()`
finishes §5.2 and where `AdminContract` is first added to `Quotations` in `deptrac.modules.yaml`
— the crossing `Catalog/Application` already makes; the Domain and Infrastructure points below add
no dependency. Three owner decisions are still open and each blocks a later point, not an earlier
one: what "own" means for a quotation (`created_by` vs the deal's owner) and the `view cost & margin`
permission slug both block **3.5**; where the `Idempotency-Key` store lives — `AuditEnforcementTest`
forbids `app/Http`, `app/Support` and `routes` from writing to the database — blocks **3.7**.
*Ruled 2026-09-11, in Point 3.4 (#97):* a quotation's **"own" is its deal's `owner_id`** (so a scoped
`create` constrains which deal may be quoted, `team` fails closed until a team entity exists);
**`customer_id` must be the deal's customer** (`422` on `customer_id`); and **`fx_rate_missing`** is a
distinct `422 business_rule_blocked` detail code beside `supplier_price_missing`. 3.5 inherits the
first and still waits on the slug; 3.7 still waits on the store.
*Ruled 2026-09-12, in Point 3.7 (#102):* the store is a **new module, `app/Modules/Idempotency`** (not
Audit's, whose rows never expire, and not Quotations-local, since §9.1 names five resources); and Point
3.6's assumption stands — **a Draft is re-priced at the FX rate effective at the edit**, `D-09`'s "at
creation" governing the first pricing only.

- [x] **3.1** `QuotationRowScope` — §3.5's `own | team | asgn | all` resolved to owner-id lists,
      the third transcription of the shape `CustomerRowScope` and `DealRowScope` share (verified
      byte-identical once comments are stripped). `asgn` has no backing mechanism yet, so
      Procurement sees no quotation — fail-closed and a real functional gap, asserted by name in
      `QuotationRowScopeTest`. *Shipped in PR #91.*

- [x] **3.2** `EloquentQuotationDirectory::create()` writes Points 1.3/1.4's `quotation_items` and
      `quotation_additional_items` — one generic `writeChildren()`, batched with no Eloquent model
      exactly as Module 6's `writeLines()`, one `now()`, UUID ids, `DB-02`'s actor on every line.
      `line_no` is **positional (1-based)**, the one divergence from Module 6 whose item table has
      no such column; a user-orderable list stays on the debt register. The child rows arrive
      through the draft's new `withLines()` — priced by Step 2, never a caller's, because §5 puts
      all pricing in the backend. **No transaction here**; Point 3.3 owns it (`DB-11`).
      *Verified by* three tests in `EloquentQuotationDirectoryTest`: the priced lines reach
      `quotation_items` with their FK, actor and `line_no` 1/2; the additional items reach their
      table; a childless quotation writes no child rows. Each broken on purpose first — dropping
      `line_no` trips the NOT NULL, a constant `line_no` fails the order assertion, skipping one
      write empties one table alone.

- [x] **3.3** `CreateQuotation` — one transaction (`DB-11`): composes `PricedLine` + `QuotationTotals` + `RoundingRule`, captures the FX rate per line and the currency's rounding at creation, derives `tax_percent` from `customers.is_tax_exempt` (`D-63`), blocks on a missing price or FX rate (§5.6, `D-09`), warns on over-quantity, records `QUOTATION_CREATED`. Crossed four modules through named interfaces (`Admin`, `Audit`, `Customers`, a new `SupplierQuotationsContract`), not the one the note predicted. *(2026-09-11, #96 — built as one point by the owner's decision, not the 3.3b/3.3c split)*

- [x] **3.4** `POST /api/v1/quotations` — Form Request mirroring the tables' CHECKs and `DB-07`'s decimal-string triple, `permission:quotation.create`, `201` `{id, code}`, `422 business_rule_blocked` with `supplier_price_missing` / `fx_rate_missing` and `field = lines.N…`, `quantity_exceeds_recorded` in `meta.warnings`. The scoped create is applied to the **deal** through a new `DealFactsInterface` (`DealsContract`); `Quotations` also gained `IdentityContract` here, not in 3.5. *(2026-09-12, #97 — three owner rulings, see the Step 3 note above)*

- [x] **3.5** `GET /api/v1/quotations/{id}` — `find()` unscoped in the directory, `QuotationRowScope` applied in `ShowQuotation` to the deal's `owner_id` through Point 3.4's `DealFactsInterface` (no subquery on `deals`); `404 resource_not_found` for absent-or-invisible (§5.1), `team`/`asgn` fail closed; cost fields **absent** without `quotation.view_cost_and_margin` (slug confirmed by the owner 2026-09-12); `etag: quotation:<id>:<version_token>` (§9.2). *(2026-09-12, #100 — own = deal owner via the seam, not a join)*

- [x] **3.6** `PATCH /api/v1/quotations/{id}` — full editable body re-priced by §5 through `PriceQuotation` (lifted out of `CreateQuotation`, one implementation); `If-Match` missing/malformed → `400 invalid_request`, stale → `409 concurrency_conflict` with `current_etag` (`API-12`, never 412), the `UPDATE … WHERE version_token = ?` bumps the token; non-Draft → `422 business_rule_blocked` `quotation_not_draft`; `edit_margin`/`edit_tax` asked only when the body moves them; `QUOTATION_UPDATED` with old/new. *(2026-09-12, #101 — a Draft is re-priced at the FX rate effective at the edit, stated as an assumption)*

- [x] **3.7** `Idempotency-Key` on the POST (`OpenAPI §9.1`) — the owner ruled 2026-09-12 that the store is its own module, `app/Modules/Idempotency`: one table `idempotency_keys` UNIQUE `(user_id, route, key)`, claimed by `INSERT … ON CONFLICT DO NOTHING` before the use case runs and completed with the final status and body after; the `idempotency` route middleware runs **after** `permission:` so a replay re-checks the grant (§9.1); missing header → `400 invalid_request`, changed payload or key still in flight → `409 idempotency_conflict`; a 5xx releases the key. *(2026-09-12, #102 — quotations only; the retention period §9.1 calls "defined" is undefined, see the debt register)*

#### Step 4 — actions on one quotation *(point list approved 2026-09-12 with defaults Q1–Q6, #103)*

What §6 asks of a single quotation between the builder (Step 3) and the list (Step 5), and what
Modules 8, 9 and 10 will call rather than rebuild: the status graph, the two `OpenAPI §7.2`
actions the build plan puts under Module 7, `D-46`'s delete, and `D-36`'s price-drift warning.
Approve, return, send and the customer's response are **not** here — the Documentation Map files
them under Modules 8 and 10, and the delivery order holds. Nothing in this step touches
`resources/js`; `user_term_suggestions` (SmartTermInput) is the builder screen's table and waits
for the frontend step.

**Owner decisions this list needs — each names its default, and the default is what ships if the
owner says only "approved":**

- **Q1 · the returned quotation.** §6.4 draws `return with note ──► Draft (v2)`. Read as the same
  row going back to `draft` (the graph edge `pending → draft`, Point 4.1) with the copy being
  Module 8's call — *or* as a new version through Point 4.3, the source leaving `pending` by some
  edge the graph does not draw. **Default: the edge `pending → draft` exists; what Module 8 does
  with it is Module 8's list.**
- **Q2 · `submitted_at`.** `D-11`'s "days waiting" needs the moment a quotation entered `pending`.
  §6.2's Tracking group does not list it; the audit row carries it, but a list screen cannot read
  a partitioned audit table per row. **Default: add `quotations.submitted_at` in Point 4.2, set on
  submit, cleared on the way back to `draft`.**
- **Q3 · a new version's number.** `quotations.code` is UNIQUE (Point 1.1) and §4.7 numbers
  documents, so a copy cannot carry its parent's `QT-` code as the schema stands. **Default: a
  new version takes the next `QT-` number; the link is `parent_id` + `version`, as §6.3 says.**
- **Q4 · which statuses may open a new version.** §6.3 names Partial, Counter and Returned.
  **Default: `partial`, `counter`, `expired`** — the three where the document is finished with the
  customer and the deal continues (`J-01` produces `expired`); `rejected` archives and the deal is
  Lost (Module 10), `draft`/`pending` are still live, `sent`/`accepted`/`approved` are the
  customer's to answer.
- **Q5 · the delete route.** `D-46` and §3.5 grant "delete (Draft only)", and `OpenAPI §7.1`
  lists no `DELETE` for any resource — customers archive through `PATCH /archive`. **Default:
  `DELETE /api/v1/quotations/{id}` answering `204`**, recorded as a contract addition for
  `OpenAPI §7.1` rather than an `archive` action, because the document says delete and Module 10
  already owns "archive" for a rejected quotation.
- **Q6 · `Idempotency-Key` on submit.** §9.1 requires it for "actions that change
  irreversible-equivalent business state"; a submit is undone by a return, and `If-Match` (§9.2)
  already makes a repeated submit a `409`. **Default: `If-Match` only on submit and delete;
  `Idempotency-Key` on `new-version`, which §9.1 names ("versions").**

- [x] **4.1** `QuotationStatusTransition` — §6.1's nine statuses and §6.4's arrows as one edge
      table in `Domain/Status/`, on `DealStatusTransition`'s exact shape (`isAllowed`,
      `allowedFrom`): `draft → pending` · `pending → approved | draft` · `approved → sent` ·
      `sent → accepted | partial | counter | rejected | expired`; `accepted`, `partial`, `counter`,
      `rejected`, `expired` terminal — Partial and Counter continue through a **copy** (§6.3), not
      an edge. `QuotationWriteRefused` gains `invalidTransition(from, to)` → `409
      state_transition_invalid` (`OpenAPI §5.1`), the row `dealStatusTransitionRefused` already
      renders — one exception class per module's write refusals, no new renderer. Domain only:
      no route, no database. *Verified by* a unit test transcribing every row of the table, one
      asserting each terminal status has no edge, and one that `sent → draft` is refused.
      *(2026-09-12, #104 — edge table + 409 factory; no route until 4.2)*

- [x] **4.2** `PATCH /api/v1/quotations/{id}/submit-for-approval` — `permission:quotation.submit_for_approval`
      (§3.5: All / Team / Own / Own) with `QuotationRowScope` applied to the deal's owner as 3.4
      and 3.5 do; `If-Match` on 3.6's terms (`400` missing, `409 concurrency_conflict` stale);
      `draft` only through 4.1, anything else `409 state_transition_invalid`; the
      `UPDATE … WHERE version_token = ?` moves `status`, bumps the token and (Q2) sets
      `submitted_at`; audit `QUOTATION_SUBMITTED` with old/new status (`AUD-01`); `200` with
      3.5's `detail()` body and the new etag. No `Idempotency-Key` (Q6). *Verified by* the
      role matrix row by row including Team Leader fail-closed and Procurement/CEO `403`; a second
      submit with the old etag → `409 concurrency_conflict`; a submit of a `pending` quotation
      with a fresh etag → `409 state_transition_invalid`; the audit row; and the verifier broken
      by removing the 4.1 check.
      *(2026-09-12, #105 — `submitted_at` added; `QuotationEtag` + `QuotationWriteAccess` extracted from 3.6)*

- [x] **4.3** `POST /api/v1/quotations/{id}/new-version` — §6.3 / `D-08`'s "full copy": one
      transaction (`DB-11`) inserting a new `quotations` row with `parent_id = {id}`,
      `version = parent.version + 1`, `status = draft`, its own `QT-` code (Q3), every header
      field, every `quotation_items` and `quotation_additional_items` row **verbatim** — captured
      `unit_cost`, FX rate and rounding included, because the copy is the document the customer
      answered; the first `PATCH` on the copy re-prices at the edit (3.6), which is §10.3's
      "refresh". Accepted from Q4's statuses only, else `409 state_transition_invalid`; the source
      row is not touched. `permission:quotation.edit` with the row scope (whoever may edit the
      next draft); `Idempotency-Key` required (§9.1 "versions"), through 3.7's alias; audit
      `QUOTATION_VERSION_CREATED` carrying the parent id; `201 {id, code, version}`. The UNIQUE
      `(parent_id, version)` (Point 1.1) refuses a second copy of the same parent at the
      database. *Verified by* a copy whose `detail()` equals the parent's except id, code,
      version, status, etag and timestamps; a second `new-version` on the same parent →
      `409`; a `draft` parent → `409 state_transition_invalid`; the replayed `Idempotency-Key` →
      one copy.
      *(2026-09-12, #106 — `replicate()` minus the answer's marks; `23505` → `409 version_exists`; `store()` keeps `{id, code}`)*

- [x] **4.4** `DELETE /api/v1/quotations/{id}` (Q5) — `D-46`: `permission:quotation.delete`
      with the row scope, `If-Match` required, `draft` only else `422 business_rule_blocked`
      `quotation_not_draft` (3.6's reason, reused — a delete outside Draft is the same rule 3.6
      enforces, not a transition); soft-deletes the row and both child tables in one transaction
      (`DB-01`, no `forceDelete`); audit `QUOTATION_DELETED`; `204`. A deleted quotation answers
      `404 resource_not_found` on 3.5's read afterwards. *Verified by* the role matrix, a
      `pending` quotation refused with the row intact, the three tables' `deleted_at` set, the
      audit row, and the read returning `404`.
      *(2026-09-12, #107 — `204`; children soft-deleted in the same guarded transaction; `lockedRow()` now the one `version_token` guard)*

- [x] **4.5** `D-36` / §10.3's price-drift warning on the read — for a `draft` or `pending`
      quotation, `ShowQuotation` compares each line's captured `unit_cost` and currency with the
      supplier line's current price through the existing `SupplierItemPricingInterface` (3.3's
      seam, no new crossing) and lists each difference in `meta.warnings` as
      `{field: "lines.N", code: "supplier_price_changed", message}` — the same shape 3.4's
      `quantity_exceeds_recorded` uses; `sent` and beyond compare nothing (§10.3 "completely
      unaffected — fixed snapshot"). No "refresh prices" route: the button calls 3.6's `PATCH`,
      which re-prices at the edit and is Draft-only as §10.3 requires. *Verified by* a line whose
      supplier price moved after creation warning on `draft` and `pending`, the same line silent
      on `sent`, an unmoved line silent, and the key absent when nothing moved.
      *(2026-09-12, #108 — `lines.N.unit_cost`; gone line or unrecorded currency counts as moved; FX rate never compared, `D-09`)*

**What Step 4 leaves for its neighbours, named so nobody assumes it is here:** approve / edit &
approve / return with note and `D-50`'s `SELF_APPROVAL` (Module 8); send and the PDF (Module 9);
accepted / partial / counter / rejected and `J-01`'s expiry (Module 10); the list, its
`group_by` and §6.6's views (Step 5, which still needs the set-based owner seam
`ShowQuotation`'s `ponytail:` note records); the builder screen and `user_term_suggestions`
(the frontend step).

#### Step 5 — the list *(point list published 2026-09-12 on #110; approved by the owner the same day with defaults Q1–Q7)*

`GET /api/v1/quotations` — the endpoint the stub above names, §6.6's views for the Team Leader
and Manager, and the same list for a sales employee inside §3.5's `view` scope. `OpenAPI §6`'s
query contract in Domain first (Module 6 Step 4's shape: criteria → directory → use case →
route), then §6.6's two groupings. Nothing here touches `resources/js`: the toggle, the split
and "the system remembers the user's last choice" are the frontend step's, and the list answers
whatever that screen asks with `filter[]`/`group_by`.

**Owner decisions this list needs — each names its default, and the default is what ships if the
owner says only "approved":**

- **Q1 · "active quotations · history".** §6.6 splits every view in two and defines neither
  word. **Default: `active` = `draft | pending | approved | sent`, `history` = `accepted |
  partial | counter | rejected | expired`** — 4.1's terminal statuses are the history — served as
  `filter[bucket]=active|history` so the screen makes two requests and the server owns the
  definition. A quotation is in exactly one bucket.
- **Q2 · "employee".** §6.6 filters and groups by employee; the owner ruled on 2026-09-11 that a
  quotation's owner is its **deal's** `owner_id`, which is Deals' column. **Default: `employee` is
  the deal's owner, read through a set-based method on `DealFactsInterface`** (Point 5.1) — not
  `created_by`, and not a new column on `quotations`.
- **Q3 · "amount range".** A quotation carries one currency and no base-currency total, so a
  range over `final_total` across currencies ranks EGP against USD (Module 6 refused to sort
  `total_price` for the same reason). **Default: `filter[amount_min]` / `filter[amount_max]`
  apply to `final_total` and are accepted only together with `filter[currency]`; without it,
  `400 invalid_request`.**
- **Q4 · "period".** Nothing says which date. **Default: `filter[from]` / `filter[to]` on
  `quotation_date`** (§6.2's Core group), inclusive, ISO dates; `created_at` is a tracking field.
- **Q5 · `q`.** §6.6 lists no search box. **Default: no `q` on this list** — `OpenAPI §6.2` makes
  the allowlist the point; Module 15's Meilisearch step adds it if a screen asks.
- **Q6 · the row.** **Default: `QuotationSummary` as 3.4 answers it plus what §6.6's columns
  need** — `status`, `customer_id`, `deal_id`, `currency_id`, `final_total`, `quotation_date`,
  `valid_until`, `submitted_at`, `version`, `parent_id`, `created_at`, `updated_at` — and **no
  cost, margin or supplier field** (§3.5's `view cost & margin` is the detail's business, and a
  list that leaks it to a role without the grant is `SEC-07` broken at scale).
- **Q7 · the customer group's label.** Customers exposes `CustomerTaxStatusInterface` to
  this module and nothing that answers a name (checked 2026-09-12). **Default: the group's
  `label` is the `customer_id` and the name is the frontend step's lookup through
  `GET /api/v1/customers`** — the alternative, a `namesOf(list<string>)` on Customers' contract,
  is one more crossing for a label, and the screen already lists customers.

- [x] **5.1** The set-based owner seam — `DealFactsInterface::dealIdsOwnedBy(string $ownerId):
      list<string>` and `ownersOf(list<string> $dealIds): array<string, ?string>`, with the
      Eloquent implementation in Deals. The first answers "own" for the list (`WHERE deal_id IN`)
      and `filter[employee]`; the second answers `group_by=employee` for one page. Both read
      `deals` through the module's own model, `DB-01` soft-deleted deals excluded, on
      `factsOf()`'s terms. `ShowQuotation::one()` and `QuotationWriteAccess` keep `factsOf()` — a
      single-row read has no set to ask for; the `ponytail:` note on `ShowQuotation` is retired
      and the third scope-check copy the debt register names is **not** touched here (it is its
      own row). *Verified by* a feature test on the Eloquent adapter: owned ids only, a soft-deleted
      deal absent from both answers, an unknown id mapping to `null` in `ownersOf()`, and the
      empty list answering `[]` without a query. **Ceiling, stated:** `dealIdsOwnedBy()` returns
      an unbounded set — fine for one employee's deals, and the point to denormalise
      `owner_id` onto `quotations` is when a Manager's `filter[employee]` on a ten-thousand-deal
      owner is measured slow, not before. *(2026-09-13, #113 — unknown or soft-deleted id is absent from `ownersOf()`, not `null`; `null` is an unowned deal)*

- [x] **5.2** `QuotationListCriteria` · `InvalidQuotationListQuery` · `QuotationPage` in
      `Domain/Listing/`, on `DealListCriteria`'s exact shape (`fromQuery()`, `offset()`,
      `DEFAULT_PER_PAGE = 25`, `MAX_PER_PAGE = 100`). **Filters:** `status` (the nine of §6.1,
      repeatable), `bucket` (Q1), `employee` (Q2, a user id), `customer_id`, `deal_id`,
      `currency` (a code, as 3.4's request names it), `amount_min` / `amount_max` (Q3),
      `from` / `to` (Q4). **Sorts:** `quotation_date`, `created_at`, `updated_at`, `code`,
      `final_total` — the last accepted **only with `filter[currency]`**, Q3's reason. Default
      `-updated_at`. **`group_by`:** `employee | customer` (the stub's own line), nothing else.
      No `q` (Q5), no `include`. Everything outside these lists is
      `InvalidQuotationListQuery` → `400 invalid_request` (`OpenAPI §6.1`, §6.2 "never ignore
      them silently"), rendered by the row `ApiExceptionRenderer` already has for
      `InvalidDealListQuery`. *Verified by* a unit test transcribing every allowlist, one 400 per
      rejected shape (unknown filter, unknown sort, unknown group, `per_page=101`, `page=0`,
      `amount_min` without `currency`, `sort=final_total` without `currency`, `from` after `to`),
      and the default sort. *(2026-09-13, #114 — `q`/`include` answered `unknown_parameter`; `amount_min > amount_max` not refused, not in the list)*

- [x] **5.3** `QuotationDirectoryInterface::list(QuotationListCriteria, QuotationRowScope):
      QuotationPage` and its Eloquent implementation. The scope is applied **in the query**:
      `unrestricted` adds nothing; an `ownerIds` scope becomes `WHERE deal_id IN (…)` from 5.1's
      `dealIdsOwnedBy()` for each owner; `permitsNothing()` answers an empty page without a
      query (the read's rule, `OpenAPI §5.1`). `filter[employee]` intersects the same way. Rows
      are `QuotationSummary` (Q6) — `QuotationSummary` grows the fields Q6 names, `store()`'s
      `{id, code}` answer unchanged (4.3's lesson). `total` counted after scoping, before
      serialisation (`OpenAPI §6.1`). *Verified by* the feature test on the adapter: each filter
      alone, two together, the bucket split (a quotation is in exactly one), the `IN` scope
      (own sees own deals' quotations only; another owner's absent; a soft-deleted quotation
      absent), pagination arithmetic (`total`, `total_pages`, last page), and `-updated_at` by
      default. *(2026-09-13, #115 — `own` = two ANDed `IN`s on `deal_id`, `filter[employee]` can only narrow; unknown currency code = empty page; `create()` now `refresh()`es)*

- [x] **5.4** `ListQuotations::handle()` · `GET /api/v1/quotations` →
      `permission:quotation.view` with the row scope resolved from the held scopes, as
      `ListDeals::handle()` does. `OpenAPI §4.2`'s collection envelope with `meta.pagination`;
      `QuotationPayload::summary()` serialises Q6's row and **nothing from
      `QuotationLine::COST_FIELDS`** — the list never asks `revealsCosts()`, because it carries
      nothing that needs it. *Verified by* the endpoint test on `QuotationReadEndpointTest`'s
      fixtures (no new fixture copy — the debt row counts): 401; Manager sees every quotation;
      Own-scoped roles see their own deals' only; Team Leader an empty page (fail-closed);
      Procurement/CEO — §3.5's `view` cell — per the matrix; a withdrawn grant 403; every 400 of
      5.2 reaching the wire as `invalid_request` with the offending parameter in
      `error.details[0].field`; `per_page` default 25 and cap 100; `meta.request_id` present. *(2026-09-13, #119 — 5.2's 25 refusals reused via `DataProviderExternal`; `Payload::pagination()` now the 10th copy, the 2026-09-12 row's count of 8 is stale)*

- [x] **5.5** `group_by=employee|customer` — the same page, grouped server-side (`OpenAPI §6.2`
      "server-side grouping only"): `data` becomes `[{key, label, count, items: [...]}]` in the
      page's sort order within each group, groups ordered by `label`; pagination still counts
      quotations, not groups, so a page may open or close a group mid-way — **stated, not
      hidden**: §6.6's screen groups what it shows, and a group that spans pages is the price
      of `OpenAPI §6.1`'s bound on every list. `employee` groups by 5.1's `ownersOf()` (a deal
      with no owner groups under `null` / "Unassigned", the label from the lang file); `customer`
      groups by `customer_id` (Q7). *Verified by*
      the endpoint test: two employees' quotations land in two groups with the right counts; a
      customer group; an unassigned deal's quotation under the `null` key; `group_by=deal` →
      400; the ungrouped shape untouched when `group_by` is absent. *(2026-09-13, #120 — label = key, `null` → `quotations.groups.unassigned`; first production caller of `ownersOf()`; Catalog's flat `group_by` shape differs — debt row)*

**What Step 5 leaves for its neighbours:** the toggle, the two-panel split and the remembered
choice (the frontend step — `localStorage` per §6.6's "remembers", or a user setting if the owner
wants it to follow the user across devices: **a question for that step, not this one**); `D-11`'s
red badge and "days waiting" (Module 8's approvals screen, §6.4); `q` (Module 15); export.

#### Step 6 — the screens *(point list approved 2026-09-13 on #121 with defaults Q1–Q7)*

Module 7's frontend: the list §6.6 describes, the quotation itself, and the builder the build plan
names (`MVP §Module 7` "Frontend"). Everything Steps 1–5 shipped is consumed, nothing is
recomputed — the SPA "displays backend results and may preview; it never owns a calculation, a
permission decision, or a state transition" (`D-67`, `Coding Standards §11`). Every point that
draws a screen is verified in Claude Browser at desktop and mobile widths, Arabic (RTL) and English,
and the report lists the steps taken (`CLAUDE.md` "UI Verification"). House pattern is the Deals
screens: `services/<feature>.ts` + `pages/<feature>/`, `components/states/*` for the four states,
`useAuth().hasPermission()` for hiding what the API will refuse anyway (§3.12 "hiding a button is
not the same as blocking an action").

**What the read of the code found, so the list is honest about backend work it needs:**
- `api.ts`'s `request()` takes no headers and returns none; the SPA has never sent `If-Match` or
  `Idempotency-Key`, and never read `meta.warnings`. The builder needs all three (Points 3.6, 3.7,
  3.4, 4.5).
- `GET /supplier-quotations/{id}` returns lines **without `id`** — Module 6 Point 2.3 left it out
  because "nothing addresses a single line yet". A quotation line is `supplier_quotation_item_id`
  (Point 3.3), so the builder cannot pick a line the backend can price. One backend field.
- `user_term_suggestions` has no migration, no endpoint, no OpenAPI row — only its name in the
  build plan's table list and "SmartTermInput" in its frontend line.
- No endpoint answers "employee name for id" to a Team Leader: `GET /api/v1/users` is behind
  `admin.create_user`. Step 5's Q7 put the customer label on the frontend's `GET /customers`
  lookup; the employee label has nowhere to look.
- No preview endpoint exists. "Live calculation · confirmation preview before saving" (build plan)
  meets `D-67`'s "never owns a calculation".

**Owner decisions this list needs — each names its default, and the default is what ships if the
owner says only "approved":**

- **Q1 · "the system remembers the user's last choice" (§6.6).** **Default: `localStorage`, key
  `crm.quotations.view`, the `theme.ts` shape (try/catch, silent when storage is unavailable),
  remembering the toggle (`employee | customer | flat`) and nothing else.** Per-browser, not
  per-account: no settings endpoint exists, and `Design System §3.1`'s only stated per-account
  preference is the theme. A user setting is one endpoint plus one column if the owner wants the
  choice to follow the user across devices — say so and Q1 becomes a backend point.
- **Q2 · the employee group's label.** **Default: the server fills `label` for `group_by=employee`
  with the owner's display name through a `namesOf(list<string>): array<string, string>` on the
  Identity facts contract the Quotations module already depends on; `null` stays
  `quotations.groups.unassigned`.** This re-rules half of Step 5's Q7 on new evidence — the
  customer half stands (the screen lists customers anyway); the employee half cannot, because the
  only users endpoint is an admin's. The alternative, a read-only `GET /api/v1/users` for anyone
  with `quotation.view`, is a new route and a new `SEC-07` surface for a label.
- **Q3 · "confirmation preview before saving".** **Default: no preview endpoint. The builder saves
  the Draft, and the detail screen (Point 6.5) is the confirmation — the server's totals, the
  server's warnings, edit or delete one click away.** A Draft is free: `quotation.edit` and
  `quotation.delete (Draft only)` are the employee's own (§3.5), and `D-67` forbids the SPA the
  calculation a client-side preview would need. A `POST /quotations/preview` that prices without
  saving is not in `OpenAPI §7` — if the owner wants it, it is a new requirement (`CLAUDE.md`
  "Requirements Traceability") and one backend point.
- **Q4 · the supplier line's `id` on the wire.** **Default: expose it** — `SupplierQuotationLine`
  gains `id`, `SupplierQuotationPayload::detail()` writes it, `services/supplier-quotations.ts`
  reads it. Module 6's frozen `checklist/module-06.md` is not edited; Point 6.2 below records the
  change. The alternative — a Quotations-side endpoint listing priceable lines for a deal — is a
  second read of the same rows.
- **Q5 · `user_term_suggestions` and SmartTermInput.** **Default: the table is filled by the
  server, not the user — saving a quotation upserts `(user_id, field, term)` for `payment_terms`,
  `warranty`, `delivery_terms`; one read route `GET /api/v1/user-term-suggestions?field=`
  returns the caller's own terms, most recent first, capped at 20; the input is a native
  `<datalist>` (the house pattern in Catalog and Settings).** `Design System §6.3` says
  "reusable suggestions; suggestions never force a structured payment schedule" — a datalist
  cannot force anything. The route is not in `OpenAPI §7`: a new requirement, flagged here.
  Alternative: defer the whole thing to a debt row and ship plain textareas.
- **Q6 · builder as a page, not a modal.** Module 6's form is a modal; the quotation builder has
  header + up to 10 suppliers × lines + additional items + totals + terms. **Default: routes —
  `/quotations` (list), `/quotations/new`, `/quotations/:id`, `/quotations/:id/edit`** — the
  `DealDetailView` precedent, `Design System §4.3` "multi-column forms" at ≥ 1024px, cards under
  640px.
- **Q7 · cost and margin on screen.** §3.5's `view cost & margin` is granted to every role that
  can view a quotation. **Default: the SPA shows the cost fields when the detail carries them and
  nothing when it does not** — Point 3.4's `withCosts` already strips `QuotationLine::COST_FIELDS`
  for a caller without the grant; the SPA makes no permission decision of its own (`D-67`).

- [x] **6.1** The client — `services/quotations.ts`: `listQuotations(query)` building
      `page/per_page/filter[*]/sort/group_by` from Step 5's allowlist (empty filters omitted, the
      Deals rule), `readQuotation`, `createQuotation`, `updateQuotation`, `submitQuotation`,
      `createQuotationVersion`, `deleteQuotation`; TypeScript types for the 14-key summary, the
      grouped `{key, label, count, items}` shape, the detail with optional cost fields, and
      `meta.warnings` `{field, code, message}`. `api.ts`'s `request()` gains one optional
      `headers` argument so `If-Match` (`API-12`) and `Idempotency-Key` (`OpenAPI §9.1`) can be
      sent; the detail's `etag` is read from the body, where Point 3.6 put it. *Verified by* vitest
      on the query string per filter and on the two headers reaching `fetch`. No screen. *(2026-09-13, #122 — first `If-Match`/`Idempotency-Key`/204 in the SPA; `Page<T>` now exported from `api.ts`, six older copies are a debt row)*
- [x] **6.2** The supplier line's `id` (Q4) — backend: `SupplierQuotationLine::$id`,
      `SupplierQuotationPayload::detail()` writes `items[].id`; frontend:
      `SupplierQuotationLine.id` in `services/supplier-quotations.ts`, its doc comment corrected.
      *Verified by* Module 6's `GET /{id}` feature test asserting the id, and the existing SQ view
      spec still green. Recorded here because `checklist/module-06.md` is frozen. *(2026-09-13, #123 — `items[].id` first key; Module 6's `PATCH` still replaces the set)*
- [x] **6.3** The list — route `/quotations` behind `quotation.view`, nav item on the reserved
      `my-quotations` slot (`navigation.ts:24`, badge count is Module 8's), table on the summary's
      columns (`code`, `version`, `status`, customer, `final_total` with its currency code,
      `quotation_date`, `valid_until`, `updated_at`), §6.6's six filters (`status`, period =
      `from`/`to`, `employee`, `customer_id`, `currency`, `amount_min`/`amount_max` — the amount
      pair disabled until a currency is chosen, Step 5 Q3), sort on the five allowed fields,
      server pagination, the four states plus `PermissionDeniedState` on 403. Customer names via
      `listCustomers({perPage: 100})` (Deals' ceiling, id as fallback); status as text with the
      `Design System §6.4` badge colour, never colour alone. Flat list only — the toggle is 6.4.
      *Verified by* vitest on the query string per control and on each state; Claude Browser at
      desktop + mobile, ar + en. *(2026-09-14, #124 — owner's ruling A: rows and the detail carry `currency` (ISO code) beside `currency_id`, resolved server-side; no employee filter — no name lookup exists for a non-admin, 6.4's employee groups carry the server's label instead; `badge` left unset until Module 8 counts)*
- [x] **6.4** §6.6's views — the toggle **by employee · by customer · flat** at the top
      (`group_by`), the fixed **active · history** split in every mode (two requests,
      `filter[bucket]`, Step 5 Q1), grouped rows rendered from `{key, label, count, items}` with a
      group heading row (Catalog's `<th scope="colgroup">` precedent), and the remembered choice
      (Q1). Pagination counts quotations, so a group may continue on the next page — the heading
      says so. *Verified by* vitest: the stored choice restores the toggle, an unavailable
      `localStorage` falls back to flat; Claude Browser as above. *(2026-09-14, #125 — Q2's employee label is the server's: `UserFactsInterface::namesOf()` under `Identity/Domain/Contracts`, `EloquentUserFacts` withholds the hidden Super Admin so its group shows the key; the customer group is named on the screen from the same `listCustomers` lookup; one refusal for both buckets, one 403 being one permission)*
- [x] **6.5** The quotation — route `/quotations/:id` on `readQuotation`: header (customer, deal,
      status, version, dates, currency), lines with the cost columns present only when the body
      carries them (Q7), additional items, the totals block in `Design System §7.2`'s groups
      (subtotal · additional · discount · tax base · tax · rounding · final) with **no tax row
      when `tax_amount` is null** (`D-63`) and a rounding row only when `rounding_enabled`
      (`D-65`); `meta.warnings` `supplier_price_changed` as the red line warning `Design System §7.2` names
      (`D-36`); the version chain — `parent_id` link and "new version" (`POST /new-version`,
      `Idempotency-Key`, Draft opens in the builder); actions by status and grant: **Edit**
      (Draft, `quotation.edit`), **Submit for approval** (Draft, `quotation.submit_for_approval`,
      `If-Match`), **Delete** (Draft, `quotation.delete`, confirm dialog `Design System §6.6`);
      `409` → a "changed by someone else — reload" banner, never a silent retry (`API-12`). Approve,
      return, send, PDF are Modules 8–10 and are **not** drawn. *Verified by* vitest per action and
      per state; Claude Browser as above. *(2026-09-14, #126 — **merged on local gates by the owner's word: GitHub Actions refused to start (billing), so no CI conclusion exists for `9650bf8`** — `QuotationDetailView.vue`; the list's code is now the link in; a 409 `concurrency_conflict` is the reload banner, any other refusal the server's `message`; new version opens the copy's page until 6.7 registers the builder; Edit links `/quotations/:id/edit` for 6.7; the supplier behind a line is not named)*
- [x] **6.6** The builder, create — route `/quotations/new?deal=` (from the deal, `Design System
      §2.1` "empty state with the permitted next action" on the deal's page is where the link
      lives), header fields (`currency`, `default_margin`, `discount_percent`, `tax_percent`
      nullable = exempt, dates, terms as plain textareas until 6.8), suppliers via (+) up to 10
      (§6.1): each picks a supplier quotation (`listSupplierQuotations({dealId})`, then any) and
      lists its lines from `readSupplierQuotation` (6.2's ids), `quantity` and an optional line
      `margin_percent` per line; additional items (`description`, `amount`); the button atop the
      supplier-item list that jumps to Module 6's form and returns (owner's ruling 2026-09-11,
      debt-register row); `POST /quotations` with `Idempotency-Key = crypto.randomUUID()` minted
      once per form open (a retry replays, a new form mints anew); `422 supplier_price_missing` at
      the line it names (save blocked, `§5.6`), `meta.warnings` `quantity_exceeds_recorded` inline
      red on the line without blocking (`§5.6`), `fx_rate_missing` at the form; on 201 → 6.5 as the
      confirmation (Q3). Money stays strings, `inputmode="decimal"` (`DB-07` on the client side:
      no `Number`). *Verified by* vitest on the payload shape, both warning paths, the blocked save,
      the idempotency header; Claude Browser as above, including the 10-supplier cap. *(2026-09-14, #127 — `QuotationBuilderView.vue`; `customer_id` comes from `readDeal`; both `QuotationNotPriceable` codes arrive on `lines.N.supplier_quotation_item_id` — `supplier_price_missing` shown at that line, `fx_rate_missing` at the form; a 201 that carries `quantity_exceeds_recorded` keeps the form read-only with the red line and a link to the draft, because a `GET` never repeats that warning; a clean 201 goes to 6.5; Module 6's modal is mounted for "new supplier quotation" and its `saved` offer becomes the next block — its `deal_id` is not pre-filled (Module 6's prop surface, not this point's); `NoHardCodedTextTest` list; deal page link behind `quotation.create`)*
- [ ] **6.7** The builder, edit — `/quotations/:id/edit` for a Draft the caller may edit: 6.6's
      form loaded from the detail, `PATCH` with `If-Match: <etag>` and `lines`/`additional_items`
      always present (Point 3.6's "an edit replaces every editable field"), `409 stale_version` →
      reload banner with the newer version's totals, `quotation_not_draft` → back to 6.5; unsaved-
      change warning (`Design System §5.2` form/builder). *Verified by* vitest on the header, the
      409 path and the dirty check; Claude Browser as above.
- [ ] **6.8** SmartTermInput (Q5) — backend: migration `user_term_suggestions`
      (`user_id`, `field`, `term`, `last_used_at`, UNIQUE on the three, `DB-01` columns,
      `down()`), upsert inside the quotation save transaction for the three term fields,
      `GET /api/v1/user-term-suggestions?field=payment_terms|warranty|delivery_terms` behind
      `quotation.create`, own rows only; frontend: `<datalist>` on the three textareas of 6.6/6.7
      fed by that route. *Verified by* a feature test (own terms only, unknown `field` → 400,
      upsert on repeat), vitest on the datalist; Claude Browser as above.

**What Step 6 leaves for its neighbours:** the approvals queue, `D-11`'s red badge and "days
waiting", the yellow "Self-approved" badge and the `my-quotations` badge count (Module 8); the
PDF button and preview (Module 9); send, the customer's response, Partial/Counter/Returned copies
(Module 10); `q` (Module 15); the Arabic manual test list that closes the module (after 6.8).

---

## Module 8 — Approvals

> As a Team Leader, I want to review quotations and adjust tax and margin before approving, so that I
> protect the company's margin.

**Endpoints** `PATCH /:id/approve` · `/return` · `/edit-and-approve`

**Acceptance criteria**
- [ ] Tax or margin edit → **mandatory audit entry** with old and new values
- [ ] Returned quotation → mandatory note + returns to Draft, appears under "Incomplete"
- [ ] Team Leader approving own quotation → `is_self_approved = true` + **yellow badge** +
      `SELF_APPROVAL` audit entry
- [ ] Quotation waiting beyond SLA → red badge + "days waiting" column
- [ ] Team Leader and Manager → **same screen, same authority**
- [ ] No automatic escalation

---

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
