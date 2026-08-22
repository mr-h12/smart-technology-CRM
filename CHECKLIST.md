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

- [ ] **OD-02** — PDF template *(before Module 9 — effectively answered by P-01, needs confirmation)*
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

## Module 0 — Foundation

*Infrastructure — no user story.*

The build plan names seven items. `D-66` adds an eighth that comes first: a
production-matched local environment, because every later item has to be built
somewhere. Steps below; each step's points are approved before it starts.

### Step 0 — production-matched Docker environment (`D-66`)

- [x] **0.1** PostgreSQL + Redis, healthchecks, `ST-03` wait-for-healthy proven
- [x] **0.2** Meilisearch at `ST-02` position 3 (behind a profile since — ~95 MB idle,
      unused before Module 15)
- [x] **0.3** PHP 8.4 image with the extensions this system needs, `bcmath` included (`DB-07`)
- [x] **0.4** Arabic fonts (`§4.1`) + the UTF-8 locale the base image lacked
- [x] **0.5** Headless Chromium — Arabic PDF rendered and inspected inside the container,
      which is what `P-01` proved on macOS and `D-66` required on Linux
- [x] **0.6** PHP-FPM + Nginx + self-signed TLS (`SEC-14`), plus the `USER` directive and
      `storage/` ownership deferred from 0.3
- [x] **0.7** The four queue workers by priority (`§15.1`) — **and the image split**:
      `PRF-04` puts PDF generation on the `pdf` queue, so only that worker needs Chromium.
      Two build targets, `app` (~810 MB) for web, API and three workers, and `pdf` (~2.08 GB)
      for one. Architecture, not a size trick
- [x] **0.8** Storage volume outside the web root (`§17`) + complete `.env.example`
- [x] **0.9** Boot verification as a script that records its result (`ST-02`, `ST-03`, `ST-04`,
      `ST-06`, `ST-09`, `D-54`) + the startup runbook (`DEV-11`). `ST-01`, `ST-05`, `ST-07`
      and `ST-08` are named in both and stay on the debt register
- [x] **0.10** Build the amd64 image on amd64 hardware in CI, verify it, publish to GHCR.
      Done out of order so 0.4 and 0.5 — the most architecture-sensitive points — were
      verified on amd64 as they landed

### Steps 1–7 — the build plan's own items

Step 1 is decomposed. Steps 2–7 are provisional and are confirmed before each
one starts, because a decomposition written too early is a guess about work the
step itself will clarify.

Two points carry disproportionate risk and both get an enforcing check rather
than an agreement: **1.2** (module boundaries) and **6.2** (automatic audit).
Left as conventions people are expected to honour, both break quietly and
surface months later.

#### Step 1 — project structure (backend + frontend)

- [x] **1.1** Laravel running in the container behind nginx, replacing the placeholder
      document root (`D-57`). No starter kit — Breeze and Jetstream install Inertia or
      Livewire, which `D-67` forbids
- [x] **1.2** Four layers (`AP-03`) and the twelve module directories `AP-02` names, with the
      boundaries **enforced by a failing check**, not documented and hoped for (`ERP-01`).
      deptrac, two rule sets, both proven to fail on a planted violation
- [x] **1.3** Vue 3 + TypeScript SPA on Vite consuming `/api/v1` (`D-67`, `AP-07`). Rendered in
      a real browser fetching a real endpoint; `/api/v1` prefix set at registration (`API-02`),
      responses carry the `OpenAPI §4.1` envelope and an `X-Request-Id` (`§3.3`)
- [x] **1.4** Strict typing, lint and static analysis wired into CI (Coding Standards §5, `DEV-10`).
      Pint with `declare_strict_types` and strict comparison, PHPStan at **level 10**, both proven
      to fail on planted code. Runbook now covers backend and frontend first-run setup
- [x] **1.5** Ownership of `storage/` and `bootstrap/cache` — deferred from 0.6 because the
      directories did not exist yet. Made container-owned volumes after proving the bind-mount
      version works on macOS only and is refused on Linux

#### Step 2 — database connection and migration tooling *(provisional)*

- [x] **2.1** Connection and configuration, application timezone UTC (`DB-08`), asserted by a
      guard test. Also moved the suite off sqlite — it cannot hold `D-68` precision
- [x] **2.2** The standard column block as a shared base: UUID key (`D-61`), `created_by`,
      `created_at`, `updated_by`, `updated_at`, `deleted_at` (`DB-01`, `DB-02`).
      Brings in `RefreshDatabase` — the first tests to write rows need it, and it is the
      mechanism that made the `crm_test` guard in 2.1 worth having
- [x] **2.3** First migration with a `down` path that is actually run, not merely written (`DEV-03`).
      Landed with 2.2 — `document_sequences` was simply the first table to need a migration, and CI
      now runs migrate, reset and migrate again. It carries **none** of 2.2's column block: it holds
      `prefix`, `year` and `last_value` under a composite key, with no actor to record and nothing
      to soft-delete. The block is proven by `StandardColumnsTest` against a purpose-built probe
      table — this line previously named `document_sequences` as that proof, which it never was
- [x] **2.4** Money column precision applied once `Q-8` is answered — closed by `D-68`. Named
      macros (`money`, `fxRate`, `percentage`, `quantity`) because Laravel's `decimal()` defaults
      to `(8,2)`, and one `Precision` class so the column scale and cast scale cannot drift

#### Step 3 — i18n *(provisional)*

- [x] **3.1** Backend lang files (`ar` + `en`, complete), locale resolved from `Accept-Language`
      per OpenAPI §2, machine codes left untranslated. The no-string-literals check is 3.3
- [x] **3.2** Frontend i18n and direction switching — `vue-i18n`, `ar`/`en` dictionaries, and a
      language switch that flips `dir` and `lang` without a reload. Verified in a real browser
- [x] **3.3** A check that **fails** on a hard-coded user-facing string — without it the rule
      is forgotten by the third screen. `NoHardCodedTextTest` scans Vue templates, Blade views
      and the narrow PHP sinks that put a literal in front of a user (`abort`, `abort_if`,
      `abort_unless`, `'message' => …`). Every pattern carries `/u`: without it PCRE reads the
      trailing `0x85` of م (U+0645) as a line break, so the guard saw English and was blind to
      Arabic — proven, then made permanent by Arabic cases that fail if the modifier is removed

#### Step 4 — design system and RTL/LTR *(provisional)*

- [x] **4.1** Semantic tokens, the three themes (Design System §3) and the typography of §4.1.
      Delivered in two halves. **4.1a** — §3.2 obliges every component to consume 21 semantic
      tokens and §8 forbids bypassing them, but §3.3 valued only 16; `D-70` derives the five that
      were mandatory and undefined (`primary-active`, `text-inverse`, `status-neutral`,
      `shadow-1`, `shadow-2`) from numbers §3.3 already publishes. `ThemeTokenTest` computes WCAG
      2.1 contrast from `tokens.css` itself rather than from the documented table, so a wrong hex
      in the shipped stylesheet fails instead of passing. **4.1b** — the three §4.1 families are
      self-hosted as ten `woff2` faces (`§1` puts this system on premises; a CDN typeface is a
      dependency it may not have), with `TypographyTest` asserting the family × weight matrix,
      `font-display: swap`, that every `src` resolves to a real `wOF2`, and the checksums of the
      exact bytes whose glyph coverage was measured. **Two findings worth carrying forward.**
      Odoo's documented `success` `#15803D` on its documented `surface-muted` `#F1EEF0` measures
      **4.35:1**, under §8's 4.5 — both values predate `D-70`, neither was changed, and the pair
      is pinned at its measured ratio so it cannot widen while it waits on an owner decision.
      And Tailwind v4 tree-shakes theme variables nothing references: four of the five §4.1 sizes
      compiled away entirely until `@theme static` was used — found by reading the build output,
      not the docs, and now held by a test. **Not covered:** nothing enforces `ar-u-nu-latn` at
      the formatting layer yet, so `Intl.NumberFormat('ar')` can still emit `٠١٢٣` that Noto Sans
      Arabic will happily draw; and the container's OS fonts carry Arabic in Regular and Bold
      only, so a 500 or 600 Arabic weight in a **PDF** is synthesised — a Module 9 concern
- [x] **4.2** Application shell using logical start/end properties, not left/right.
      `App.vue` hosts the §5.1 shell — sidebar, context bar, page content — with `AppSidebar`
      at 256px/72px and a drawer below 1024px (§4.3), and `AppContextBar` carrying the page
      title, theme, language and user context. One DOM order, one stylesheet: the RTL mirror
      is `dir` following the locale, never a duplicated screen (Coding Standards §11).
      `LogicalPropertiesTest` scans both surfaces a physical side can arrive on — CSS
      declarations in `<style>` blocks and Tailwind utility classes in markup — because in a
      Tailwind codebase `ml-4` is likelier than `margin-left`. **The drawer slides on
      `inset-inline-start`, not a transform:** `translateX` is physical by definition, so an
      RTL drawer built on `-translate-x-full` enters from the wrong edge while every class
      still reads as correct. **What the tests could not see:** all twenty passed while the
      rail measured 237px in English and 300px in Arabic — a scoped media query set
      `inline-size: auto` and outranked the utility class, so it sized to its own text. Only
      headless-Chromium box measurement caught it; the widths are now declared in CSS and
      pinned by a test. Measured after the fix, in both locales: 256/72 expanded/collapsed,
      drawer off-canvas and back on Escape, exact mirror at 1280 and 375.
      **Not covered:** the drawer has no focus trap (§8 exempts dialogs from the no-trap rule,
      and a drawer is one); Laravel's stock `tailwind.blade.php` paginator uses `ml-auto`,
      `-ml-px`, `mr-6`, `pl-4`, `pr-2.5` and `text-right` and is already a Tailwind source, so
      the first §5.2 table inherits a paginator that does not mirror; navigation deliberately
      lists only routes that exist, since §5.1 forbids showing what cannot be reached
- [x] **4.3** Theme persisted, applied before first paint, no flash. An inline, synchronous
      script in `welcome.blade.php` above `@vite` reads the preference and sets `data-theme`
      before anything paints; `theme.ts` writes it on switch. Only the two non-default themes
      set an attribute — §3.1 makes Odoo-inspired the default and `tokens.css` puts it on
      `:root`, so "no attribute" is already right. The stored value is checked against a fixed
      list rather than trusted: `localStorage` is writable by anything on this origin and this
      script's whole job is to move a value from there into the document.
      **The flash did not reproduce on a developer machine.** On a warm LAN the naive version —
      theme applied from the Vue bundle — painted correctly too, because 202 kB of JavaScript
      arrived before the browser painted at all. Throttled to 400 kbps with the cache emptied,
      the same page painted at 2552ms with no `data-theme` and a canvas of `rgb(248, 248, 249)`,
      turning dark only at 5748ms: **3.2 seconds of the wrong theme**. With the inline script it
      painted dark at 2564ms. **Two assumptions the measurement killed:** `defer` and
      `type="module"` were expected to reintroduce the flash and neither did — they fetch
      nothing, and `defer`/`async` are ignored outright on a classic inline script. The property
      that matters is the absence of a network fetch, and the test says so.
      **Not covered:** `§9.1` requires the preference to persist *for the account* into the next
      session, and there is no account until Module 1 — this stores per browser, so the flash
      requirement is met and the account requirement is a debt. Nothing in CI re-measures the
      flash: a regression that keeps the ordering but moves the work elsewhere would pass
- [x] **4.4** Loading, empty, error and permission-denied states as base components.
      Four components under `resources/js/components/states/`, each carrying the state in an
      icon **and** text — §9.5: "visible without relying on color alone", which rules out a red
      box for an error and a bare spinner for loading. `LoadingState` keeps its width and
      announces politely (§6.1); `ErrorState` interrupts with `role="alert"` and offers a 44px
      retry (§6.4, §8); `EmptyState` takes the next action as a **slot**, because what a user
      may do next is a permission decision and SEC-09 keeps those on the server — an empty list
      with a disabled Create button tells the user about a capability they do not have.
      `PermissionDeniedState` **takes no props at all**: a denial that names the resource or the
      permission turns an access-control boundary into an enumeration oracle, so there is
      nothing a caller can pass in and leak, and no retry, since a 403 does not become a 200 by
      asking again. Wired into `Ping.vue` so that four components nothing imports are not four
      components nothing type-checks.
      **A defect that had already shipped, found by measuring a browser.** `text-[var(--text-card-title)]`
      reads as a font size and is not one: Tailwind cannot tell a size from a colour inside an
      arbitrary value, guesses colour, and emits `color: 16px` — invalid, dropped in silence. So
      no heading in the application had its §4.1 size, and where a colour utility sat beside it
      the colour was lost too: the §6.4 danger heading measured `rgb(46, 44, 45)` instead of red.
      Eight elements across the shell and the state components, through the CI-green commit for
      4.2 and 207 passing tests. Fixed to the named utilities and pinned by a test.
      Measured after the fix, both locales, mobile viewport: heading `rgb(180, 35, 24)` at 16px
      weight 600, retry 95×44 and 109×44, `role="alert"`, icon `aria-hidden`.
      **Not covered:** nothing mounts these in a test, so the announcements, the contrast in all
      three themes and the reachability of a 44px target are asserted through their markup, not
      observed; and a caller passing a literal to a `titleKey` prop would bypass the i18n gate,
      which only scans template text

#### Step 5 — storage abstraction and `files` *(`Q-2` closed by `D-71`)*

- [x] **5.1** `Q-2` answered, then the `files` migration. `D-71` records the answer: a central
      `files` table plus one pivot per parent, with real foreign keys — **not** a polymorphic
      `entity_type`/`entity_id` pair, because PostgreSQL cannot constrain one column against five
      tables, so every attachment row would be free to name a deal that never existed. The
      strongest evidence that the polymorphic shape was already known to leak is in the spec
      itself: `J-11` is a weekly job whose entire purpose is deleting files attached to nothing.
      A cleanup job for orphans is an admission, written in advance, that orphans will occur.
      With the pivots the database refuses them and `J-11` becomes a safety net rather than the
      mechanism. Four pivots now (`deal_files`, `supplier_quotation_files`,
      `purchase_order_files`, `report_files`), composite primary key, `file_id` indexed on its
      own, `ON DELETE CASCADE` on both sides. Upload ceiling raised to **30 MB** (`D-71`,
      superseding the 10 MB in `D-39`) and carried through all three ceilings that can cut
      first, since only the lowest one is real.
      **Three false greens, each found by breaking the thing on purpose.** A bare
      `expectException(QueryException::class)` is satisfied by "relation does not exist", so
      three constraint tests passed before the migration existed — every negative assertion now
      names its SQLSTATE (`23503`, `23505`, `23514`). Worse, the `file_id` index test asked
      whether *any* index mentioned the column, and the composite key `(deal_id, file_id)`
      mentions it: deleting the real index left all 28 tests green. A B-tree is only searchable
      from its leading column, and the check now says so. **And nginx was the actual ceiling:**
      `client_max_body_size` was never set in `default.conf`, so the live limit was nginx's 1 MB
      default — a 2 MB upload would have failed today, long before anyone reached 30. Proven by
      real request, not by reading config: 30 MB → 405 (Laravel answering), 60 MB → 413.
      `DEV-03` run, not assumed: `migrate` → `migrate:reset` → `migrate`, and `down()` drops the
      pivots before `files` or PostgreSQL raises `2BP01`.
      **Not covered:** the four parent-side foreign keys are **owed, not written** — `deals`,
      `supplier_quotations`, `purchase_orders` and `reports` are Modules 5, 6, 10 and 13 and do
      not exist, so today nothing stops a `deal_id` pointing at nothing; the debt is machine-
      readable in `test_the_parent_foreign_keys_that_are_still_owed_are_recorded`, which fails
      the moment a parent table appears without its key. The nginx ceiling is outside the
      `crm/` bind mount, so no test can read it and a regression there will not fail CI.
      `scan_status` is a `CHECK` constraint, not the managed enum table `DB-05` wants, pending
      `Q-6`. And nothing writes to these tables yet: no service, no model, no endpoint
- [x] **5.2** Storage behind an interface, local driver first. `StorageServiceInterface` in
      `app/Modules/Storage/Domain/Contracts/`, `LocalStorageService` in `Infrastructure/`, one
      binding in `AppServiceProvider`. The contract **names no framework type** — no uploaded
      file, no disk, no path helper — because `deptrac.layers.yaml` gives Domain an empty
      ruleset: it may depend on nothing, Illuminate included. `StoragePath` is a value object
      rather than a string built at the call site, since every segment of §17's
      `/{year}/{month}/{entity_type}/{entity_id}/{uuid}.ext` is assembled from material a user
      supplied, and `..` in any one of them walks out of the root. The original name is
      **truncated to its extension, not sanitised**: the safest handling of an untrusted string
      is to keep it out of the path entirely, which is what §17's UUID naming already says.
      **The root is `/var/crm-files`, not `storage/`.** §17 asks for a path *outside the
      application directory* — not merely outside the web root — and `storage/` sits inside the
      bind mount. The volume was already there and waiting: `docker-compose.yml` mounts
      `crm-storage` at `${STORAGE_PATH:-/var/crm-files}` for php and every worker, the Dockerfile
      creates it 0750 www-data, nginx deliberately does not mount it, and `.env.example` already
      carried the variable with §17 quoted above it. Found by opening the compose file before
      writing the config. `serve` is false and there is no `url`, so the disk publishes no route;
      `throw` is true, because a write that returns false and reports success loses a file that
      BK-01 then backs up as absent.
      **The abstraction is scanned, not trusted.** §14.2 says "behind an abstraction layer", and
      a layer callers may walk around is documentation. One test fails the build if anything in
      `app/` or `routes/` outside the driver touches the Storage facade or a raw file function;
      another fails if anything but the binding names the concrete class. **Three of the checks
      could not have caught anything when first written** — `serve` read as null on a disk that
      did not exist and null casts to false; a `glob` returning nothing passes a scanner forever;
      and one scanner searched for `LocalStarageService`, a class that will never exist. All
      three were found by breaking the code on purpose and watching nothing happen.
      **Not covered:** `app/Modules/Storage` appears in no layer of `deptrac.modules.yaml` — AP-02
      names twelve modules and this is not one of them, so a module importing the driver directly
      reports as *uncovered*, which does not fail the build; the hand-rolled scanner stands in
      until a thirteenth layer is approved, and a test records the gap. Nothing calls the service
      yet. `pathinfo` takes an extension and asks nothing about the bytes, so an executable
      renamed `.pdf` stores successfully until 5.3. `delete()` really deletes — whether a
      soft-deleted `files` row keeps its bytes is a J-11 question nobody has answered. And one
      full-suite run failed once, unreproduced across fifteen further runs and three random
      orders; its name was not captured
- [x] **5.3** Upload validation: true MIME, configured size (`D-71`), allowed types (`D-40`).
      `UploadValidatorInterface` asks three questions in order, because each only means anything
      if the one before it held: is there a file of a sane size, is it a type we accept, is it
      whole. **The interface takes a path and no filename.** A browser controls the name and the
      `Content-Type` header it sends with it; passing either in would create somewhere for them
      to be trusted, so neither is passed in, and the extension a file is stored under comes back
      out of the detected type instead.
      **Nothing here was assumed about libmagic; all of it was measured in this image (file-5.44).**
      DOCX and XLSX are zips, and libmagic separates them from `application/zip` by reading the
      entry names inside — so a renamed archive does not become a Word document, and 8 KB of head
      identifies a 26 MB DOCX correctly, which is why the whole file is never read into a string.
      A GIF is refused: `D-40` writes "images" in shorthand and §17 enumerates three, so three is
      the number. Type detection says nothing about completeness — libmagic calls a 40-byte
      fragment of a JPEG a JPEG — so each family gets a second check: `%%EOF` for a PDF,
      `getimagesize()` for an image, an openable archive for OOXML.
      **A fourth false-green verifier, and the worst of them.** `Lang::has($key, 'ar')` falls back
      to English by default — the third argument is `$fallback` and it is `true` — so deleting the
      entire Arabic message left the translation test green. It now passes `false`, and the break
      was repeated to watch it fail. **And one code comment claimed more than was proven:**
      dropping `ZipArchive::CHECKCONS` changed nothing, because a truncated archive is refused by
      a plain open too (`ER_NOZIP`). The flag stays, the comment now says its extra strictness is
      untested rather than implying otherwise.
      **Not covered:** the validator exists and **nothing calls it** — there is no upload endpoint,
      so a hostile file is refused only if it is routed through here, and nothing forces that until
      5.4. `LocalStorageService::store()` still takes its extension from the untrusted name, so
      5.4 must pass the detected type in. Corruption is detected partially, never wholly: `%%EOF`
      present does not make a PDF valid, `getimagesize` reads a header, and a consistent zip may
      hold broken XML. No zip-bomb ratio check. A DOCX from a producer that orders archive entries
      unusually reads as `application/zip` and is refused — safe, but a refusal of a good file.
      `SEC-15` still has no virus scanner. And the two lang files sit under `lang/`, which pint and
      PHPStan both exclude
- [x] **5.4** Download endpoint checking permission on the parent entity (`D-38`).
      `GET /api/v1/files/{file}/download` streams from the private volume behind three gates in
      order: the row exists and is not soft-deleted (`DB-01`), its scan came back clean
      (`SEC-15`), and **some parent grants read** — approved 2026-08-22: any one of the linked
      parents is enough. **D-38 cannot be answered yet and nothing here pretends to answer it.**
      Identity and dynamic RBAC are Module 1 and the parents are Modules 5, 6, 10 and 13, so
      Module 0 implements the *traversal* — file → its D-71 pivots → its parents — and delegates
      the decision to `AttachmentPermissionInterface`. The binding that ships is
      `DenyAllAttachmentPermission`: a default that denies is a feature that visibly does not
      work yet, a default that grants is a hole that looks finished. **Consequence, stated
      plainly: the endpoint refuses every request in production today.**
      A refusal is **404, never 403** — the OpenAPI contract says 404 covers "does not exist or
      is not visible to the caller. Do not reveal which case applies", so the use case discards
      the distinction before the controller sees it and cannot leak what it does not hold.
      Headers: `nosniff`, `Cache-Control: private, no-store`, and RFC 5987 `filename*` so an
      Arabic name survives an ASCII-only header. `store()` no longer takes a filename at all —
      the extension comes from the validated type (Point 5.3), closing the gap 5.2 left open.
      **Three routing defects that predate this point, all found by measurement.** (1) The
      authorisation decision was made **once per process**: `Route::getController()` memoises the
      controller on the Route object, so constructor-injected services outlive the request —
      measured allow → 200, deny → **200**, allow → 200. Dependencies moved to per-call
      injection; pinned by a test. (2) Every unmatched `/api/*` path returned **200 with the SPA
      shell** — the catch-all's comment claimed /api/v1 was matched first, true only of routes
      that exist. Two tests asserting 404 went green before the endpoint existed. (3) An
      unauthenticated API call returned **500**, not 401: `auth` redirects a guest to
      `route('login')`, which D-67 does not have. Measured after the fix: `/api/v1/does-not-exist`
      → 404 JSON, unauthenticated download → 401 JSON. And the `local` disk's `serve => true` had
      published `GET` and `PUT /storage/{path}` with no middleware — unreachable only because
      defect (2) shadowed it, which is an accident rather than a control. Now `serve => false`.
      **Not covered:** a download writes **no audit record** — §13 requires one and the audit log
      is Step 6, blocked by `Q-3`, so this is a gap and not merely an ordering. No upload
      endpoint yet, so the validator and the store are wired to each other but not to HTTP. No
      `Range`, `ETag` or resume: an interrupted 30 MB download restarts. Each stream holds a
      PHP-FPM worker for its duration, with no concurrency limit. Every file stays `pending`
      until 5.5, which means every file is undownloadable regardless of permission. And
      `app/Modules/Storage` is still absent from `deptrac.modules.yaml`, now by four layers
- [x] **5.5** Virus scanning on every upload (`SEC-15`). `VirusScannerInterface` takes a stream
      and answers `Clean` or `Infected` — never a third thing. **"Could not check" is an
      exception, not a status**: `ScannerUnavailable` propagates, the row stays `pending`, and
      the file stays undownloadable. An antivirus that is down and reports clean is worse than
      none, because the column then says the file was examined and every later reader believes
      it. `ScanStatus` is asserted against the `files_scan_status_check` constraint itself
      rather than against a copy of the list, because two lists in two files is how they drift.
      Two drivers. `ClamAvScanner` speaks clamd's **INSTREAM** rather than `SCAN`, so the daemon
      never needs to see the attachment volume and can live anywhere the network reaches; its
      detection is **unverified — there is no clamd in this stack** and the item is on the
      deployment-debt register. `EicarSignatureScanner` knows exactly one signature and a test
      asserts out loud that it calls everything else clean, so it cannot be mistaken for
      protection. The EICAR string is assembled at run time in both the scanner and the test:
      it is designed to be detected, and a repository carrying it whole can trip a developer's
      own antivirus or a CI cache scan, which looks like a build failure and not at all like
      its cause.
      **A verifier that could not fail, caught by breaking it.** Deleting the scanner's
      chunk-overlap changed nothing: the "buried in a larger file" test put the signature at
      100 kB, comfortably inside the second 64 KiB read. Five boundary-straddling cases were
      added, and the same break then failed all five. **And a knob that was never connected:**
      `FILES_MAX_SIZE_BYTES` went into the *root* `.env.example` in Point 5.1, but Laravel reads
      `crm/.env` and the php service injects no environment — measured, `env()` never saw it, so
      "configurable without a code change" was false and the 30 MB default was doing all the
      work. Moved to `crm/.env.example` and proven live: setting it to 12345 changed
      `config('files.max_size_bytes')` to 12345.
      **`app/Modules/Storage` is now in `deptrac.modules.yaml`** — the debt Point 5.2 recorded.
      Split in two so the boundary means something: `StorageContract` (Domain + Application) is
      what a module may one day depend on, `StorageDriver` (Infrastructure + Presentation) is
      what nothing may. Proven by planting `Deals\Application\Probe` on the driver:
      `DependsOnDisallowedLayer`, deptrac exit code **1**. Coverage went from 0 checked
      dependencies to 79.
      **Not covered:** nothing calls the scanner automatically — there is no upload endpoint and
      no queued job, so `ScanStoredFile` has to be invoked by whatever wires the upload. §17
      says "on every upload"; today it is "on every call". `ClamAvScanner` has never spoken to a
      real daemon: the suite proves only that an unreachable one throws. No retry, no backoff
      and no `J-` number for rescanning — a file whose scan failed stays `pending` until someone
      calls again. clamd's 25 MB default `StreamMaxLength` is below the 30 MB ceiling (`D-71`)
      and nothing reconciles them. An infected file's bytes are kept deliberately, and no job
      sweeps them. And `scan_status` is still a `CHECK` constraint rather than the managed enum
      table `DB-05` wants, pending `Q-6`

#### Step 6 — audit log as a cross-cutting layer *(provisional — blocked by `Q-3`)*

- [ ] **6.1** `Q-3` answered, then the `audit_log` migration with partitioning (`DB-10`)
- [ ] **6.2** Automatic capture — a module is audited **without opting in**
- [ ] **6.3** Immutability enforced at the database, not by convention (`AUD-03`, `D-30`)
- [ ] **6.4** Request and correlation IDs propagated (`AUD-05`)
- [ ] **6.5** A test proving a new module is audited without touching audit code

#### Step 7 — seed data *(provisional)*

- [ ] **7.1** Roles and permissions as `resource.action.scope` data (`SEC-07`)
- [ ] **7.2** Managed lists: sectors, units, service types, delivery terms (`DB-05`)
- [ ] **7.3** Currencies with rounding unit and on/off (`D-52`, `D-65`), and FX rates
- [ ] **7.4** One test user per role (`DEV-08`)
- [ ] **7.5** Seeding repeatable and idempotent (Coding Standards §7)

**Tests**
- [ ] App runs · frontend talks to backend · database connects
- [x] Switching language flips direction — asserted server-side by `SpaShellTest` and confirmed
      client-side in Chrome: `ar`/`rtl` → `en`/`ltr` → `ar`/`rtl` without a reload
- [ ] A test job executes from the queue

---

## Module 1 — Identity & Permissions (Dynamic RBAC)

> As a team member, I want to log in with my email and password, so that I can access the system
> with my role's permissions.

**Tables** `users` · `roles` · `permissions` · `role_permissions` · `user_sessions`

**Endpoints**
- [ ] `POST /api/v1/auth/login` · `logout` · `change-password`
- [ ] `GET /api/v1/auth/me`
- [ ] CRUD `/api/v1/roles` · `/api/v1/permissions` · `/api/v1/users`

**Frontend** login page · role-based redirect · protected routes · role and permission management

**Acceptance criteria**
- [ ] Valid credentials → redirect to the role's default screen
- [ ] 5 failed attempts → account locks and an email is sent
- [ ] Session idle 8 hours → automatic logout
- [ ] Permission removed from a role → direct API call returns **403**
- [ ] Password under 8 characters or digits only → rejected with a clear message
- [ ] Deactivated employee → "Account suspended, please contact administration"
- [ ] Super Admin is hidden from every user list, for every role
- [ ] Manager cannot create Manager, CEO, or Super Admin accounts
- [ ] Login As is Super Admin only and always writes an audit entry

🚀 **First deployment point — deploy to the real server here, not at the end.**

---

## Module 2 — Settings, Managed Lists & Currencies

> As a system administrator, I want to configure company details, currencies and lists, so that the
> system runs on the company's real data.

**Tables** `settings` · `currencies` (+ rounding unit) · `fx_rates` + history · `enum_lists` ·
`system_limits`

**Acceptance criteria**
- [ ] FX rate edit → old rate stays in history + mandatory audit entry
- [ ] New sector added in settings → appears in the customer form **without a deployment**
- [ ] Currency rounding unit changes → **only new quotations** are affected
- [ ] Rounding units default correctly: EGP `1` · USD `0.01` · EUR `0.01`
- [ ] Rounding can be switched **off** per currency → final total stored unrounded, `rounding_diff` = `0` (`D-65`)

---

## Module 3 — Customers

> As a sales employee, I want to add and view my assigned customers, so that I can manage my deals.

**Tables** `customers` · `import_batches` · plus **`SearchService`** with `PostgresSearchDriver` (ILIKE)

**Endpoints** CRUD + `PATCH /:id/archive` · `PATCH /:id/assign` · `POST /import`

**Frontend** role-filtered list · add/edit form · detail page · archive (individual + select all) ·
Excel import · "customers of deactivated employees" filter

**Acceptance criteria**
- [ ] Sales employee sees only own customers · Team Leader sees team · Manager sees all
- [ ] Excel import with missing fields → record saves flagged **"incomplete"**, in a dedicated filter
- [ ] Incomplete records are **excluded from financial reports** until completed
- [ ] Name similar to an existing customer → **yellow warning**; the employee decides, no blocking
- [ ] Manually archived customer → visible only to Manager and Team Leader
- [ ] Change of sales owner → customer and full history transfer + audit entry
- [ ] **Every search call goes through `SearchService`** — no direct queries

---

## Module 4 — Catalog & Suppliers

> As a sales employee, I want to browse the product/service catalog and the supplier list, so that I
> can build a quotation.

**Tables** `catalog_items` · `suppliers`

**Acceptance criteria**
- [ ] New product appears under the Product tab, grouped by company
- [ ] Red-rated supplier → red chip beside their name on **every** screen
- [ ] Service appears under the Service tab, separate from products
- [ ] Deactivated product is hidden from new selection lists
- [ ] Catalog holds **no prices** — descriptive data only
- [ ] Every catalog edit is written to the audit log

---

## Module 5 — Requests / Deals ⭐

> As a Team Leader, I want to enter customer requests and assign them to employees, so that work
> flows down the right path.

**Tables** `deals` · `deal_documents` · `deal_status_history` · customer-status engine
(`recompute_customer_status`)

**Endpoints**
- [ ] CRUD `/api/v1/deals`
- [ ] `PATCH /api/v1/deals/:id/assign` · `/approve` · `/reject` · `/status`
- [ ] `POST /api/v1/deals/:id/documents`

**Acceptance criteria**
- [ ] Customer with an active deal + new request → **two independent deals**, separate statuses
- [ ] Deal reaches Won → customer status becomes **"Customer"** automatically and permanently
- [ ] All deals Lost → status **"Deal Not Completed"**
- [ ] Employee-entered request → "Pending Approval" for the Team Leader, inactive until approved
- [ ] Rejected request → mandatory reason + badge for the employee
- [ ] Status change → timeline entry with old status, new status, who, when
- [ ] Deal codes follow `DL-2026-0001`

---

## Module 6 — Supplier Quotations

> As a sales employee, I want to record a supplier's price offer and attach their file, so that I can
> use it to build the customer's quotation.

**Tables** `supplier_quotations` (**nullable** `deal_id`) · `supplier_quotation_items`

**Acceptance criteria**
- [ ] Offer containing a product not in the catalog → product added automatically
- [ ] Saved offer appears on the supplier page under "Linked Quotations"
- [ ] Offer not linked to a deal → saves normally, available to any deal
- [ ] File upload → type, size and **true MIME** validated, stored under a UUID name
- [ ] Shared screen — not restricted by ownership

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
