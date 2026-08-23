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
These four are not. They are blocked on an owner decision or on ordinary work, they were each
created by work already merged, and keeping them under a heading that says "wait for the server"
would hide them behind `OD-03` indefinitely.

- [ ] **`D-73` has no row in the master decision log** — created 2026-08-22 with the three-theme
      work (Midnight Obsidian · Warm Editorial · Clean Monochrome, 22 semantic tokens, contrast
      checked mathematically). `grep -n "D-73" docs/CRM_Documentation_EN.md` returns **nothing**
      — checked 2026-08-23. The decision is implemented, tested and shipped, and the log that is
      supposed to be the record of every decision does not mention it. `CRM_Documentation_EN.md`
      is hook-protected (`.claude/settings.json` blocks `Edit`/`Write` on it), so this needs the
      owner's explicit approval, not a workaround
- [ ] **The `D-72` note under `§15.1` states a fact that is no longer true** — it reads "the
      worker services sit behind a compose profile that is off by default". Point 8.1 deleted
      that profile. The note's *conclusion* still holds — `J-15` stays in the scheduler while
      Horizon does not exist — so this is a correction, not a reversal. Same hook, same need for
      approval
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
- [ ] **`docker-compose.yml` can change without CI running** — found 2026-08-23 while closing
      Point 8.5, by noticing that this point's own commit triggered no run. `php-image.yml` filters
      on `docker/php/**`, `crm/**` and itself. **`docker-compose.yml` is in none of them**, and yet
      `QueueConfigurationTest` parses that file to assert the worker `--queue=` flags match
      `config/queue.php` — the check Point 8.1 built precisely because those two live where neither
      can see the other. A commit that edits only compose would skip the one test that validates
      it. Owed: add `docker-compose.yml` to the `paths` filter. Not done here, because it is a
      change to CI behaviour and this point is a sign-off, not a fix

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

#### Step 6 — audit log as a cross-cutting layer *(point order approved 2026-08-22)*

- [x] **6.1** `Q-3` answered by `D-72`, then the `audit_log` migration. **Monthly `RANGE`
      partitioning on `created_at`.** `DB-10` required partitioning and named no granularity,
      and `OD-05` — expected daily workload — is still open, so **no row-count argument was
      available and none was invented**: the granularity comes from the documented access
      shapes instead. Three of the four indexes end in `created_at DESC`, which are
      recent-window questions, and a yearly partition reads a year to answer one about a
      fortnight. `D-30` keeps every row forever while `BK-01` backs up daily, so monthly
      boundaries turn a table that grows without end into frozen partitions plus one hot one.
      Written as raw DDL because Laravel's Blueprint has no `PARTITION BY`; a Blueprint here
      produces an ordinary table that satisfies every documented column and none of `DB-10`.
      **Three things in it are measured facts about PostgreSQL 17.5, not preferences.** The
      primary key is `(id, created_at)` because a unique constraint on a partitioned table
      must contain every partitioning column — `id` alone was refused outright. A `DEFAULT`
      partition is kept even though a row landing in it blocks creating the range partition
      that would have held it, because without one an insert outside every range fails, and
      `AUD-01` with `DB-11` put the audit write inside the business transaction, so a
      partition nobody created would take the business operation down with it. And the
      indexes are declared on the parent, which propagates them to every partition present
      and future — an index reaching only the parent answers nothing, since every scan runs
      against a partition.
      **`audit_log` is not a business table:** no `updated_at`, no `deleted_at`, no
      `created_by`/`updated_by`. `DB-01` and `DB-02` describe rows that change and are
      retired; `AUD-03` and `D-30` say this one never does either.
      **Two verifiers that could not fail, both caught by breaking them.** `down()` looped
      over `pg_inherits` dropping each partition before the parent, and the test written to
      protect that loop passed identically with the loop deleted — because dropping a
      partitioned parent drops its partitions already, measured. The loop was dead code and
      is gone. And the UTC assertion on the partition bounds could not fail: this stack
      connects with `TimeZone = UTC`, so a bound literal with no offset reads as UTC anyway.
      Rewritten to run the real migration under `Asia/Riyadh` and read the bounds back as
      UTC, where the defect is visible — the broken version starts August at **21:00 on 31
      July**, so the first three hours of every month land in the wrong partition. Two more
      false greens were found at RED: `hasColumn()` on a missing table is false for every
      column, and `QueryException` is also what "relation does not exist" throws, so the
      forbidden-column set and every required-column check passed against a database with no
      `audit_log` in it. Now anchored to `assertTrue(hasTable())` and to SQLSTATE `23502`.
      **Not covered:** nothing writes to this table yet — the recorder is 6.4, and until then
      the schema is proven and unused. `user_id` carries **no foreign key**, because Module 1
      replaces the users table rather than extending it; that is a live `DB-04` gap closed in
      Module 1, the same arrangement `standardActorForeignKeys()` already waits on. `id` is
      typed `UUID` and nothing enforces that it is **v7** — `D-61` is satisfied by the writer,
      not by the column. Immutability is **not enforced yet**: `UPDATE` and `DELETE` both
      work on this table today, and that is 6.2. And only two months are seeded, so **once
      2026-09 elapses every row lands in `DEFAULT`** until `J-15` exists (6.3)
- [x] **6.2** Immutability enforced at the database, not by convention (`AUD-03`, `D-30`).
      Ahead of the writer deliberately: a guard installed after there is something to protect
      has already been unnecessary for a while. One plpgsql function, three placements, and
      the split between them is measured rather than chosen. **`UPDATE` and `DELETE` — one
      row trigger on the parent**, because a row trigger on a partitioned table is enforced
      on its partitions too, including against a statement aimed straight at one, and
      PostgreSQL clones it onto partitions created *afterwards* — so `J-15` inherits this half
      for free. **`TRUNCATE` — one statement trigger per relation**, because that kind does
      not propagate in either direction. **Nothing stops `DROP TABLE`**, and the application
      connects as a PostgreSQL superuser, so this closes the accident and the application
      bug, not the deliberate superuser; the non-superuser role is now on the deployment-debt
      register.
      **A custom SQLSTATE, `AUD03`**, not plpgsql's default `P0001`. `P0001` is what every
      `RAISE EXCEPTION` in the system will eventually produce, so a test pinned to it would
      keep passing after it stopped meaning anything. Verified to reach PHP intact as
      `QueryException::getCode()`. Measured on the real database: `UPDATE`, `DELETE`,
      `TRUNCATE` of the parent and `TRUNCATE` of a partition directly all answer
      `AUD03: audit_log is append-only: <OP> is refused (AUD-03, D-30)`, and the row survives.
      **Three defects this point uncovered, none of them in the guard itself.** `sprintf()`
      and plpgsql's `RAISE` both claim `%`, so the migration died before any SQL ran —
      "3 arguments are required, 2 given". Then `migrate:fresh` turned out to drop tables but
      **not functions**, so the orphaned function collided on the *second* run of the suite
      with SQLSTATE 42723 and took 137 tests down with it; `CREATE OR REPLACE` closes that,
      and the suite now runs twice in a row. And **`migrate:rollback --step` counts
      migrations, not batches**, so Point 6.1's two down tests were quietly coupled to
      `audit_log` being the newest migration — adding this one turned both red without either
      `down()` having changed. They use `migrate:reset` now.
      One more false green caught at RED: the rollback test passed against a database where
      the function had never existed, because "no such function" and "down() removed it" look
      identical from the far side.
      **Not covered:** a partition created **after** this migration has no `TRUNCATE` guard
      until `J-15` arms it — the hole is asserted by
      `test_a_partition_created_after_the_guard_is_not_yet_truncate_guarded`, which Point 6.3
      must *invert* rather than delete. An `UPDATE` matching no rows is a silent no-op,
      because a row trigger has no row to fire on; nothing was changed, but a reader probing
      with a `WHERE` that matches nothing gets silence. Nothing writes to the table yet (6.4).
      And an `ALTER TABLE ... DISABLE TRIGGER`, like `DROP TABLE`, is beyond what any trigger
      can refuse
- [x] **6.3** `J-15 ensure_audit_partitions`, and the row for it in `§15` — a fifteenth job is
      an addition to a list that calls itself the single reference list, so the two land
      together. Neither `pg_partman` nor `pg_cron` is in the image, measured, which is why
      this is an application job at all. **`D-72` seeded two months and stopped**, which gave
      `audit_log` a working life of about eight weeks: after that every row falls into
      `DEFAULT`, and a row in `DEFAULT` is exactly what *blocks* creating the partition it
      belonged in. Silent when it starts, expensive when it is noticed.
      Three duties, in this order. **Create** the missing months — `§15` demands idempotency,
      and here that means a second run creates nothing rather than merely surviving; proven
      on the real database, `created 2, armed 2` then `created 0, armed 0`. **Arm** every
      unguarded partition, *not only the ones it made*: Point 6.2's `TRUNCATE` guard does not
      propagate, so a partition made by hand, by a restore or by a future migration has none,
      and this is what turns a permanent hole into a window one day wide. **Count** what is
      stranded in `DEFAULT` and exit non-zero — the alarm is last on purpose, because a run
      that refused to work while something else was wrong would leave next month uncreated
      too, and then there would be two problems.
      **The trap `D-72` named, handled rather than hit.** A row already in `DEFAULT` for a
      month makes `CREATE TABLE ... PARTITION OF` fail outright — `updated partition
      constraint for default partition "audit_log_default" would be violated by some row` —
      and an unhandled failure there stops the run before it arms anything. So the job asks
      first, records the month as blocked, and carries on with the rest. Measured by deleting
      the check: one test, the exact error above.
      **Split across four layers** so the decision is testable without a database:
      `AuditMonth` and `PartitionMaintenance` in Domain (no Illuminate — `deptrac.layers.yaml`
      gives Domain an empty ruleset), `EnsureAuditPartitions` in Application,
      `PostgresAuditPartitions` in Infrastructure, the command in Presentation.
      `app/Modules/Audit` enters `deptrac.modules.yaml` split as `AuditContract` /
      `AuditDriver`, the same shape Storage uses; proven by making the contract half name the
      driver, which produced `Violations 1 · Reason AuditContract`.
      **Scheduled, not queued, and that is a documented exception to `§15`.** Horizon is not
      installed and the worker services sit behind a compose profile that is off by default,
      so a queued `J-15` would wait in Redis while the table ran out of months — the exact
      failure it exists to prevent. Recorded in `§15` and on the debt register.
      **Two things the last two points taught, applied here before they could bite.** The
      UTC-boundary check runs the job under `Asia/Riyadh` and reads the bounds back as UTC,
      because on a UTC session an offsetless literal is indistinguishable from a correct one;
      breaking the normalisation produced `partition "audit_log_2026_10" would overlap
      partition "audit_log_2026_09"` from bounds ending `+03:00`. And the new
      `AUDIT_PARTITION_MONTHS_AHEAD` knob went into `crm/.env.example`, then was **proven
      live** — 3 by default, 7 when set — rather than assumed connected.
      **Not covered:** the job is not on the `maintenance` queue and appears in no Queue
      Monitor, because there is none; `Schedule::command()` carries no `onOneServer()` lock,
      which is harmless on one server and wrong on two; nothing alerts on the non-zero exit
      beyond the scheduler's own output (`OBS-06` is unbuilt); a partition is still unguarded
      for up to a day after something else creates it, which
      `AuditLogImmutabilityTest` now states as a window rather than a hole; and nothing
      writes to `audit_log` yet — that is 6.4
- [x] **6.4** `AuditRecorder` — the writer. Points 6.1 to 6.3 built a table, made it
      append-only and kept it supplied with months; nothing had ever written a row. The
      caller supplies only what it alone knows — the event and the thing it happened to —
      and actor, IP, user agent, request id, correlation id and the timestamp are filled in
      behind the interface, because a module that had to pass them would eventually pass them
      wrong.
      **`AuditEvent` is a validated name, not a free string.** Named constructors for the nine
      `§3.12` rule 4 requires, because a misspelled mandatory event is a row no audit query
      will ever find and `AUD-03` means it cannot be corrected. `of()` stays open for the
      vocabulary each later module brings; what it does not stay open to is a shape the
      column or a log line cannot carry — the `D` modifier on the pattern is what stops
      `"LOGIN_AS\n"` putting a line break into a permanent record.
      **Floats are refused outright** (`DB-07`). `json_encode(0.1 + 0.2)` writes
      `0.30000000000000004`, and this is where a price change is preserved *permanently*. The
      check is recursive, because the value that matters is rarely at the top level — it is
      the third line item's unit price.
      **`D-69` is enforced twice**, at the boundary by `AddRequestId` and again in
      `AuditContext`, because a caller can build a context by hand. The rule is duplicated —
      neither file can reference the other without pointing a module's domain at HTTP
      middleware or the reverse — so three constants are pinned to the middleware's by test,
      which is what notices the drift the duplication invites.
      **The user agent is truncated, the correlation id is not.** A `VARCHAR(512)` overflow
      would fail the insert and take the business transaction with it (`AUD-01`, `DB-11`) —
      denial of service through a header. `D-69` discards an overlong trace id instead,
      because a truncated one matches nothing upstream and is worse than none.
      `AUD-05` gets **its own JSON channel** with `days => 0`: `D-30` retains the audit
      permanently, and a log the rotation deletes after fourteen days would quietly contradict
      the table beside it. Verified on disk, one valid JSON line per record.
      **Three findings.** `TIMESTAMPTZ` made the UTC test unable to fail — shifting the
      entry's zone to `Asia/Riyadh` changed nothing, because the same instant reads back
      identically; `DB-08` is satisfied there by the *column type*, not by this code. What
      the normalisation actually protects is the log line, which is a formatted string, so
      the check now asserts `toLogContext()` and the same break fails it. **`jsonb` does not
      preserve key order** — measured, `tax_exempt` came back ahead of `tax_percent` — so
      anything depending on payload key order depends on something the column never promised.
      And **double-encoding is silent**: it produces a quoted string where `jsonb_typeof` says
      `string`, and every later `old_values->>'x'` returns nothing rather than failing.
      **Not covered:** `user_id` is **always null** — `RequestAuditContext` reads
      `$request->user()` and there is no users table until Module 1, so every row records a
      correct *absence* of actor rather than an actor. **Nothing calls the recorder yet:**
      `AUD-01` wants every create, update, delete, approve and transfer audited, and today
      the count of audited mutations in this system is zero — 6.5 is the test that makes that
      impossible to leave. The **log line is not transactional**: a rolled-back operation
      leaves no row but does leave a log record, which is deliberate and worth knowing. The
      request id does **not propagate into queued jobs**, so an audit row written from a job
      has none. And `AUD-05` is only half done — the application's own channels are still
      line-formatted
- [x] **6.5** The check that makes an unaudited mutation a build failure. `AUD-01` wants every
      create, update, delete, approve and transfer recorded; 6.1 to 6.4 made that *possible*,
      and nothing made it *unavoidable* — a requirement depending on fourteen future modules
      each remembering is a requirement with a half-life.
      **What it enforces, stated without inflation:** it cannot prove a write was audited, which
      is a claim about behaviour in code nobody has written. What it removes is the *silent*
      option. Every class under `app/Modules` that writes to the database is **discovered by
      scanning, not by being declared**, and the set is compared against a register in the
      test file: a new writer fails the build until somebody says there whether it is audited
      or why it is not. A writer claiming `AUDITED` is checked against its own source rather
      than believed. An exempt writer must carry a reason long enough to be one. And
      `app/Http`, `app/Support` and `routes` may not write at all — a controller reaching the
      database is outside every boundary this file can police (Coding Standards §5).
      Separately and behaviourally: a stand-in module inside the test injects
      `AuditRecorderInterface`, records, and the row appears — **with nothing registered, no
      listener added, and `app/Modules/Audit` untouched**, which is this step's acceptance
      criterion read literally.
      **The scanner's first real finding is real debt, not a demo.**
      `DatabaseFileRepository::recordScan()` flips `files.scan_status` from `pending` to
      `clean` or `infected` (`SEC-15`, Point 5.5) — a security-relevant state change on a
      business entity, and `AUD-01` lists update among what is recorded. It is unaudited,
      and now unaudited *on the record*, owed by Module 5 when an upload endpoint and an
      actor exist.
      **Precision was earned, not assumed.** The first scanner flagged
      `LocalStorageService::delete()`, which removes a file from a disk; a database write is
      now a DML verb **plus** a connection signal. `create(` is deliberately not a verb —
      it is the most common method name in any codebase, and a scanner that cries wolf gets
      ignored. Two assertions were vacuous on arrival (an empty `AUDITED` set loops over
      nothing and passes forever); one now also checks every register entry points at a real
      file, and the other was verified by making the false claim on purpose.
      **PHPStan read the register's literal types** and called `!== AUDITED` always-true —
      right about today's register, wrong about every future one — so the register is a
      method with an explicit `array<class-string, string>` return rather than a constant.
      **Not covered:** the scanner reads text, so a write assembled at run time, reached
      through a variable method name, or performed by a vendor package is invisible to it —
      it raises the cost of an unaudited write, it does not make one impossible. It cannot
      tell an audited *call path* from a class that merely mentions the interface. No module
      is `AUDITED` yet, because none writes a business row, so that branch is proven only by
      deliberate breakage. And `user_id` is still always null until Module 1

**Step 6 is complete: 6.1 … 6.5.** `audit_log` is partitioned monthly (`D-72`), append-only at
the database (`AUD-03`), kept supplied with months by `J-15`, written through one recorder
(`AUD-02`, `AUD-05`), and defended by a build-failing boundary. What it is not, yet, is *used*:
no module records anything, because no module mutates anything.

#### Step 7 — seed data *(path and point order approved 2026-08-22)*

**The structural finding that set this order.** Not one table `DEV-08` names exists. `roles`,
`permissions`, `role_permissions` and `users` belong to Module 1; `enum_lists`, `currencies` and
`fx_rates` to Module 2 (`design/DATABASE.md` §4, §5). The `users` table that *does* exist is
Laravel's scaffold, which §4 says is **replaced** in Module 1 rather than extended — bigint key
against `D-61`, no `deleted_at` against `DB-01`, no `created_by` against `DB-02`. Seeding rows into
it would seed a table on its way out. Two open questions compound it: **`Q-4`** (does `users` keep
`email_verified_at`) and **`Q-5`** (one `enum_lists` table or one per list, assigned to Module 2).

So Module 0 delivers the **mechanism and the canonical definitions**; the seeders that insert rows
land with their tables in Modules 1 and 2. That is the same shape as Step 6 — the layer exists here,
and it is empty until the modules that need it arrive. `Coding Standards §7`'s actual requirement is
a property of the mechanism: seed data "versioned and repeatable".

- [x] **7.1** What a seeder is allowed to be in this project. `GuardedSeeder` is the only base a
      seeder extends and its `run()` is **`final`** — the sole extension point is `seed()`, so
      reaching it means the environment check already happened. A guard a subclass can forget to
      call is a guard that will be forgotten.
      **The guard is selective, and that is the requirement, not a nicety.** `DEV-08` lists test
      users beside roles, permissions, managed lists and currencies — and the last four are exactly
      what a fresh *production* database needs on day one. So `seedsTestData()` decides, and two
      tests hold both edges: fixtures refused in production, reference data still seeding there.
      It reads **both** `$app->environment()` and `config('app.env')`, because
      `TestingDatabaseGuard` was written after those two disagreed and let a run call itself
      testing while pointed at the development database.
      **Idempotency is measured, not declared.** The runner snapshots every table in the schema —
      row count plus an order-independent `md5(string_agg(t::text ORDER BY t::text))`, partitions
      excluded so `audit_log` is not counted twice — then runs, snapshots, runs again, snapshots.
      Its load-bearing assertion is the unobvious one: **the first run must have changed
      something**, because a seeder that does nothing is perfectly idempotent and so is a snapshot
      that reads nothing. Three companion tests run the verifier against deliberately broken
      seeders and assert it rejects them.
      **Laravel's scaffold is gone**: `DatabaseSeeder` no longer creates `test@example.com`, proved
      behaviourally by seeding an empty database and counting `users`.
      **Two verifiers that could not fail were found by breaking them.** The stand-in written to be
      non-idempotent upserted a counter back to `0` before incrementing it, landing on `1` every
      run — broken by design, correct by accident. And the `users` assertion passed with the
      scaffold restored: `PendingCommand::assertSuccessful()` only *records* an expected exit code
      and returns `$this`; the command runs in `__destruct()`, which the PHPStan fix had pushed
      past the count. `->run()` executes it where it is written.
      **Not covered:** no row is seeded anywhere — this is the mechanism, and 7.2 to 7.5 are the
      definitions it will carry. `app/Support/Seeding` sits outside `./app/Modules`, so **deptrac
      does not police it** (122 and 103 allowed dependencies, unchanged); it is beside
      `app/Support/Database` because it is framework-level infrastructure, not a business module.
      `database/seeders` is deliberately **not** added to Point 6.5's "nothing outside a module
      writes to the database" list — a seeder must write, and the contract is what makes that write
      legitimate instead of unexamined. Nothing checks that a seeder's writes are transactional,
      and nothing yet audits them: a seed run has no actor, and `AUD-01` is about user operations
- [x] **7.2** The `§3` matrix written down once and checked: **9 sections · 57 permissions ·
      8 roles · 5 scopes · 212 grants** (143 explicit scopes, 69 bare checkmarks), in
      `app/Modules/Identity/Domain/Rbac`. *(Corrected 2026-08-22 during 7.5: this line first said
      200 grants and 59 checkmarks, which was my arithmetic over the section tables rather than a
      measurement. Point 7.5's wiring test counts the rows it actually writes and found it.)* Plain PHP with no
      Illuminate anywhere in it — `deptrac` gives both `Identity` and `Domain` empty rulesets, and
      Module 1's tables do not exist (approved Option C), so the definition stands alone until a
      seeder can carry it into them.
      **Why a test and not just a file.** Every cell is an authorisation decision. A row omitted
      locks a role out; a scope widened one step hands a sales employee the whole company's
      quotations. Transcribing 57 rows across **five different column layouts** is exactly where
      that happens quietly, so the transcription is checked against what the document states about
      itself: the per-section row counts, `resource.action` shape, every scope inside `§3.2`'s five,
      and both `delete` rows granting nobody anything (`§3.12` rule 3).
      **Super Admin is resolved, not transcribed.** `§3.3`–`§3.10` have no Super Admin column at
      all, so a matrix built only from cells would leave the developer role with nothing. `§3.1`
      gives it scope `All` unconditionally, `§3.12` rule 6 hides it, and both are properties of the
      role — one place rather than 57 — with a test that no *other* role may bypass the matrix and
      that Super Admin was not smuggled into the operational tables as a cell.
      **The document's bare ✅ had to be interpreted, so the interpretation is checked.** `SEC-07`
      has no value meaning "yes" — every permission carries a scope — and a plain ✅ means "at this
      role's scope for this section", the reading `§3.4` makes explicit by writing "✅ Own" and
      "✅ Asgn" in the one row where the answer differs. Each of the **69 checkmark cells** records
      that it was a checkmark, and a test resolves every one against its section's own view row.
      **`§3.11` is the exception and says so:** its first view-prefixed row is `admin.view_audit_log`,
      a capability rather than a section anchor, so all its cells are explicit and a test asserts it
      holds no checkmark.
      **On "export never exceeds view" (`§14.7`):** the citation is wrong — `§14.7` is the API
      table, `API-01`…`API-12`, and says nothing about export. The rule is enforced where the
      section has a plain `view` row, which is `§3.5`; it is **not** applied to `§3.10`, where the
      documented matrix would fail it — Sales is `❌` on `view received` and still exports its
      **own** reports, because those are two different permissions. Enforcing the rule literally
      would have required editing the specification.
      **Not covered:** nothing is seeded — this is the definition, and 7.5 carries it into Module 1.
      `§3.12` rule 7 (a Manager may not create Manager, CEO or Super Admin accounts) is a
      constraint on *values*, not a scope, and belongs to Module 1's use case. `§3.5`'s "Own
      (Draft)" is a state condition the matrix does not express. `Scope::includes()` deliberately
      leaves `Out` and `Asgn` incomparable to `Team`, because declaring an order the specification
      does not state is how a screen gets granted by accident. And no runtime enforces any of
      this yet: it is data, and `SEC-07`'s "enforce at the API" is Module 1
- [x] **7.3** Currencies, rounding and what an FX rate is, in
      `app/Modules/Admin/Domain/Money` *(7.3 and 7.4 swapped by the owner 2026-08-22 — currencies
      first, because `Q-5` still blocks the managed lists)*. Module 2 has no module directory of
      its own: `AP-02`'s twelve are Identity · Customers · Deals · Quotations · Suppliers · Catalog
      · Procurement · Outdoor · Reports · Notifications · Audit · Admin, and `§3.11` puts "system
      settings" and "FX rates" under Administration.
      **§5.3's table is the whole definition** — EGP rounds to the pound, USD and EUR to the cent
      (`D-52`) — with rounding switchable off per currency (`D-65`), in which case
      `final_total = total_before_round` and `rounding_diff = 0`, the same invariant
      `design/DATABASE.md` writes as `CHECK (rounding_enabled OR rounding_diff = 0)`. The unit is
      kept when the switch is off, because `D-65` moves a switch beside the unit rather than
      erasing it. `§5.2`'s worked example is pinned: `8315.9988 → 8316`, diff `0.0012`.
      **No exchange rate is seeded, and that is the finding.** `§13` screen 5 makes every rate
      manual and `J-12` alerts when one goes stale; the specification gives no rate values
      anywhere. `D-09` captures the rate onto a quotation at creation and forbids recomputing it,
      so a placeholder would not stay a placeholder — it would be frozen onto an issued document as
      though it were real. The only rate defined is the base against itself, which is an identity
      rather than a price. **USD and EUR cannot be quoted until the business supplies rates.**
      **Two assumptions, stated rather than buried.** *EGP is the base currency*: `§13` screen 5
      names the field and never says which currency fills it — EGP is the company's own, `§5.2`'s
      example is a pound PO and `§5.3` writes "1 pound", so it is the only reading, but it is a
      reading. *Rounding starts on*: `§5.3` describes switching it **off** as the edit, which makes
      on the state it is edited from. *Halves round up*: `§5.2` writes `round(total, unit)` and
      never says which way a half falls.
      **`DB-07` is enforced by reading tokens, not by arithmetic — measured.** A float
      implementation of the rounding passed **every** documented row: PHP prints a double at
      `precision=14`, so `(float) 0.145 / (float) 0.01` comes back as the string `"14.5"` and the
      cent is right. It takes a twelve-digit total against the cent — quotient `1e14`, printed
      `"1.0E+14"`, which BCMath refuses — before arithmetic notices. Both checks now exist: that
      case, and a scanner that tokenises the namespace and fails on a float literal, a `(float)`
      cast, or a call to `round()`/`floor()`/`ceil()`/`fdiv()`. PHPStan level 10 forced the rest:
      BCMath takes `numeric-string`, so `Decimal::of()` validates and every amount is a checked
      plain decimal — stricter than `is_numeric()`, which accepts `1e5` and leading whitespace.
      **Not covered:** nothing is seeded — 7.5 carries this into Module 2's `currencies` and
      `fx_rates` tables, which do not exist. `RoundingRule::apply()` is the currency's own rule and
      **not** the quotation calculation: `§5.2`'s subtotal, discount, tax base and net amount are
      Module 7. Rate *history* (`§13` screen 5) and the audit entry on an FX change (`§3.12` rule 4)
      are Module 2. Nothing converts an amount between currencies yet, and `D-68`'s stated
      quantisation at scale 6 is inherited, not re-examined
- [x] **7.4** The four lists `DB-05` names, in `app/Modules/Admin/Domain/Reference`:
      **6 sectors · 3 units · 4 service types · 0 delivery terms**.
      **`Q-5` did not have to be answered, and was not.** It asks whether `enum_lists` is one table
      with a `type` column or one table per list — a *persistence* question. What this point
      defines is the membership, which is identical either way, so `Q-5` stays open and Module 2
      decides it with the migration. Recording that here because 7.4 was listed as blocked by it.
      **Labels are values in both languages, not translation keys — and the reason is a
      requirement, not a preference.** `CLAUDE.md` forbids hard-coded user-facing strings, and the
      usual answer is a lang key; but `design/DATABASE.md` promises that adding a sector reaches
      the customer form **without a deployment**, and a sector added at runtime has no key. A key
      would push its label back into a file that needs deploying — the requirement inverted. So
      every entry carries `label_en` and `label_ar` bound for columns, and the screen renders the
      row. **The Arabic is read, not translated**: it comes from §4.2's sector row and §7.3's
      catalog table in `arabic/docs/CRM_Documentation.md` — حكومي · طبي · تجاري · صناعي · فنادق ·
      بنوك, قطعة · متر · كيلو, تركيب · إصلاح · صيانة · تجهيز — so the seeded lists say what the
      business already says. A test asserts each Arabic label contains Arabic script and **no Latin
      letters**, because the failure that matters is not a missing label but a copied one.
      **Delivery terms is defined and empty on purpose, with a test that says so.** `DB-05` names
      it in both languages and **neither document gives it a single value**; §6.2 keeps `delivery`
      as free text on the quotation beside payment (`D-26`) and warranty. A plausible-sounding
      default would be business content nobody wrote, printed on customer quotations under the
      company's name — the same rule that kept 7.3 from inventing an FX rate.
      **`3` units, not "piece, metre, kilo, etc."** §7.3 gives exactly three; the fourth was not
      invented. Order is data too (`position`, 1..n): without it the order on screen is whatever
      the query planner returned, which is not an order anybody chose.
      **Not covered:** nothing is seeded — 7.5 carries this into `enum_lists`, which does not
      exist. `ManagedList` is itself an enum, which `DB-05` does not forbid: what it forbids is the
      *membership* being code, while the set of lists is fixed by the columns that reference them.
      Nothing validates that a customer's sector is one of these — that is Module 3's foreign key.
      And no delivery term can be quoted until the business supplies them
- [x] **7.5** Eight test personas — one per role, `Role`'s own order, Super Admin included
      because `§3.12` rule 6 **hides** that account rather than omitting it — plus the wiring that
      proves Step 7's four registries can actually be seeded.
      **No password exists anywhere in the definitions.** `SEC-17` keeps secrets out of code, and a
      literal here would be worse than an ordinary one: a working credential identical on every
      checkout and every developer machine. `PasswordPolicy` carries the rule instead (`D-28`,
      `SEC-02`: eight characters, letters **and** numbers), the seeder reads
      `SEED_TEST_USER_PASSWORD` from the environment, and `.env.example` carries the key **empty**
      — the rule `DB_PASSWORD` and `REDIS_PASSWORD` are already held to. Addresses are on
      `example.test`, which RFC 6761 reserves as never-resolvable: a plausible company address in
      seed data is one mistyped environment away from a password reset reaching a real inbox.
      **The wiring is exercised, not asserted.** Two stand-in seeders inside the test read the
      registries through their public API and write into probe tables standing in for Module 1's
      and Module 2's: **212 permission grants · 3 currencies · 13 list entries · 8 users**, with
      the far end of each chain spot-checked (`quotation.view|team_leader` → `team`, EGP unit `1`,
      `government` → `حكومي`). Running it twice changes nothing; the registries compare equal
      afterwards; and a scan asserts **no definition references `Illuminate`, a seeder, or a
      connection at all**, so "consumed without modification" is a checked property rather than a
      hope. The production split is exercised both ways: the persona seeder is **refused** and
      writes nothing, the reference seeder **runs**.
      **This point corrected a published number.** The 7.2 entry said 200 grants and 59
      checkmarks. Both were my arithmetic over the section tables, and both were wrong — it is 212
      grants, 143 explicit scopes and 69 checkmarks. The wiring test counted the rows it wrote and
      found it; the 7.2 entry above is corrected and marked.
      **Two tooling defects, both real.** Pint's `php_unit_method_casing` renamed a private helper
      `testUserSeeder()` to `test_user_seeder()` and left its three call sites pointing at nothing
      — the suite had been green **before** the formatter ran, and only PHPStan caught it. And the
      first decoupling scanner read raw text, so it reported `TestPersonas.php` for the sentence in
      its own docblock explaining that `GuardedSeeder` refuses it in production; it now strips
      comments via `token_get_all`, the same fix the float scanner needed in 7.3.
      **Not covered:** still no row is seeded anywhere — the probe tables are created by the test
      and dropped with it. `PasswordPolicy` is the rule only; Argon2/bcrypt hashing (`SEC-02`) is
      Module 1 infrastructure, and nothing hashes yet. No `users`, `roles`, `permissions`,
      `currencies`, `fx_rates` or `enum_lists` table exists. The seeder that reads
      `SEED_TEST_USER_PASSWORD` does not exist either — the key and its test are the documented
      home for it, not the implementation

**Step 7 is complete: 7.1 … 7.5.** Module 0's share of `DEV-08` is a seeding mechanism that is
idempotent by verification and refuses test data in production, plus four canonical registries —
the `§3` permission matrix, the currencies and their rounding, the four `DB-05` lists, and eight
test personas — each strongly typed, framework-free, and proven consumable by a seeder that edits
none of them. What it is not, is *seeded*: every table `DEV-08` names belongs to Module 1 or
Module 2 (approved Option C), and three business facts are deliberately absent because no document
supplies them — **the USD and EUR exchange rates, the delivery terms, and the test-user password**.

#### Step 8 — queue and jobs infrastructure *(point order approved 2026-08-23)*

**This step has no number in the build plan, and that is why it was nearly missed.** The plan gives
Module 0 seven bullets; `CHECKLIST` numbered them, and the first bullet — "project structure ·
database connection · migration tooling" — was split across Steps 1 and 2. Seven bullets over eight
units of work, and the one left without a number is the sixth: **"Queue + jobs infrastructure — the
four queues (critical · pdf · reports · maintenance)"**. `PRF-01`'s `P95 < 500 ms` comes from
`§14.5`, not from the build plan.

- [x] **8.1** The four queue names in one place, checked against the services that drain them, and
      the workers off their profile gate.
      **The defect this closes is a queue with a producer and no consumer.** A queue name is a
      string in two files that never see each other: PHP dispatches to it, and a `--queue=` flag in
      `docker-compose.yml` drains it. Nothing connected them, so a typo on either side would not
      fail — it would leave jobs sitting in Redis looking exactly like jobs that have not run yet.
      `config/queue.php` now carries the four names in `§15.1`'s priority order (`AP-08`,
      configuration over code), `App\Support\Queue\QueueName` is the typed way to name one at a
      call site, and `QueueConfigurationTest` asserts the enum, the config and every worker command
      agree — plus `--tries` on each (`§15.1`: a fixed retry count, then `Failed`) and
      `service_healthy` on Redis and PostgreSQL (`ST-03`).
      **`docker-compose.yml` is not inside the bind mount** — the mount is `./crm`, the compose
      file is its parent's — so PHP simply could not see it. Checked in the container, not assumed.
      It is now mounted read-only at `/opt/crm/docker-compose.yml` for the php service and for the
      CI test container. **When it is absent the checks fail rather than skip**, proved by
      breaking it: 15 failures, 0 skipped. A cross-file check that quietly stops running is worse
      than no check, because the file still reads green.
      **The profile gate is gone.** It existed because `artisan` did not, and its own comment said
      to remove it "when the application lands" — which was step 1. `ST-01` requires every service
      enabled on boot with no manual startup, and `docker compose up -d` now brings all four
      workers up, verified by `docker inspect` reading back the four commands.
      **One value is an interpretation and is labelled as such.** Laravel's redis connection falls
      back to a queue literally named `default`, which no worker drains — a silent black hole the
      moment anything dispatches without `->onQueue()`. No document names a default, so the choice
      is mine: `maintenance`, the lowest of the four, because an unclassified job running late is
      survivable and one congesting `critical` — recovery and system health — is not.
      **A published sentence is now wrong.** The `D-72` note under `§15.1` reads "the worker
      services sit behind a compose profile that is off by default". Its conclusion still holds —
      `J-15` stays in the scheduler while Horizon and Queue Monitor do not exist — but the premise
      is false as of this point. `CRM_Documentation_EN.md` is hook-protected, so the correction is
      on the debt register rather than applied.
      **Not covered:** no job exists. Nothing has ever been dispatched or executed, so this proves
      the wiring is *consistent*, not that it *runs* — 8.2 is what proves that. The workers were
      started, but nothing was observed draining. `QueueName` lives in `app/Support`, which is
      outside both `deptrac` configs' `paths`, so neither counter moved and neither covers it.
      Horizon is still not installed, so `OBS-02` and `OBS-03` have no screen
- [x] **8.2** A job that actually reaches Redis and actually comes back out.
      `App\Support\Queue\Jobs\InfrastructureProbeJob` carries no business meaning and writes to
      nothing a module owns; what it leaves behind is evidence that cannot be produced any other
      way — **which queue it ran on, asked of the running job rather than echoed back from the
      dispatch call**, which attempt it was, and when (UTC, `DB-08`). The receipt is a Redis list,
      not a value, so retries append: that is what makes `§15.1`'s "fixed retry count, then
      `Failed`" observable instead of inferred.
      **`Queue::fake()` is deliberately absent.** It records dispatches and runs nothing, so it
      would have asserted that Laravel's dispatcher works — never in question — while leaving
      Redis, the serializer, the worker loop and `failed_jobs` untested. Every job here is pushed
      to a real Redis list, and the test asserts **the list is one deep and no receipt exists
      before any worker runs**: the one assertion a fake cannot make. Draining is a real
      `queue:work --once --tries=3` pass, the same bound the worker services carry.
      **The isolation was a correctness fix, not tidiness.** 8.1 made `docker compose up -d` start
      four workers, and they are draining `queues:critical` on Redis database 0 right now. A test
      pushing there races a container that will happily execute the job first. `phpunit.xml` now
      forces `REDIS_DB=15`, and the suite refuses to flush anything else.
      **`§15.1`'s retry rule is measured, not asserted.** Attempt one and two leave `failed_jobs`
      empty; the third records a row naming the connection, the queue and the exception. The
      receipts read `[1, 2, 3]`.
      **CI had no Redis at all** — the test step ran one PostgreSQL container and nothing else, so
      this suite would have failed there while passing locally. A `redis:7.4-alpine` container, the
      same image `docker-compose.yml` pins, now runs alongside it.
      **The first attempt to add it turned the build red, and the cause is worth keeping.**
      `--link ci-redis:redis` injects `REDIS_PORT=tcp://<ip>:6379` into the linked container, and
      Laravel's Dotenv never overrides a variable that is already set — so `.env`'s `6379` lost to
      a URL and phpredis rejected it: *"Argument #2 ($port) must be of type int, string given"*.
      **Docker Desktop injects no link variables at all**, so it was invisible here; verified by
      running `env` in a linked container and seeing none. The workflow now uses a user-defined
      network with aliases, which injects nothing anywhere, and the whole CI recipe was rebuilt
      locally — same network, no `.env.testing` — and run green before pushing again.
      **`.env.testing` governs the suite locally and does not exist in CI.** It is `.gitignore`d
      (`.env.*`), so `APP_ENV=testing` falls back to `.env` there. That divergence is why a Redis
      password worked on this machine and an empty one worked in CI, and it is now on the debt
      register rather than a thing to rediscover.
      **Not covered:** the four *worker containers* still execute nothing under test. The worker
      pass here runs in the test process, which proves Redis, the serializer, the worker loop and
      the retry bound, but not supervision, restart, or `ST-06` durability across a restart.
      Nothing measures throughput or latency (`PRF-01` is 8.4). The probe ships in the application
      image and is dispatchable in any environment — harmless, but it is production code whose only
      consumer is a test. `app/Support` is outside both `deptrac` configs, so neither counter moved
- [x] **8.3** The chain, not the links. Every piece of this was already asserted somewhere — the
      shell for language and direction, `/ping` for its envelope, the database for its precision,
      Redis for its queues — **four green suites that would all have stayed green if the SPA were
      asking a path the server does not serve.** `EndToEndConnectivityTest` walks shell → API →
      PostgreSQL → Redis in one pass and names the link that broke.
      **The seam is a file comparison, because it has to be.** `/api/v1` is written in two places
      that never see each other: `api.ts` prefixes it onto every call and `bootstrap/app.php`
      registers it as `apiPrefix`. PHP cannot run the TypeScript and the bundle cannot read the
      router — the same shape as the queue names in 8.1, and the same fix.
      **The localisation loop is closed both ways:** the shell tells the document its language, and
      `api.ts` sends that attribute back as `Accept-Language` on the next call. Asserted as a round
      trip, `ar` and `en`.
      **A real defect was found by the point's own RED.** The suite's *cache* connection was on
      Redis database **1** — the database the running application caches in. The probe wrote and
      deleted a key there before `phpunit.xml` was given `REDIS_CACHE_DB=15`, extending the
      isolation 8.2 established for queues. A test that evicts a developer's live cache is a test
      that will eventually be blamed for something else.
      **`ST-08` is held open by a check, not by memory.** The deferral is the owner's decision of
      2026-08-23; the test fails if any `/health` route appears *or* if the recorded reason is
      deleted from `routes/api.php`, so publishing it stays a decision rather than a commit.
      **`/up` is named so nobody reads it as coverage.** `bootstrap/app.php` registers Laravel's own
      boot probe. It answers 200 once the framework boots and reports nothing about PostgreSQL,
      Redis, Meilisearch, the queues or storage — it satisfies none of `ST-08`, it is unversioned,
      and until this point it was documented and tested nowhere.
      **Not covered:** this checks the dependencies *from the test process* and publishes that check
      to nobody — no endpoint, no dashboard, no alert. Meilisearch and the storage volume are not
      probed at all (`OBS-01` names both). Nothing here runs against nginx, TLS or the real browser:
      the shell assertions are Laravel's own test client, and the SPA's actual `fetch` was confirmed
      by hand in Chromium, not by this file. `ST-05` catch-up and `ST-06` durability across a
      restart remain untested
- [x] **8.4** `PRF-01` measured — **P95 between 4.1 ms and 7.3 ms against a 500 ms budget**, and
      the method is as much of the deliverable as the number. One figure would have been a
      misrepresentation: five 200-request runs were taken, three consecutive settled ones gave
      `4.121` · `4.714` · `4.150` ms, and the `7.257` ms outlier was the first run after
      `config:cache`, with opcache cold for the freshly written cache file. Two orders of magnitude
      of headroom, on the one route that exists.
      **The method, in full.** 200 measured requests preceded by 20 discarded warm-up requests,
      issued from inside `crm-php` to `https://nginx/api/v1/ping` over the compose network — one
      container hop, no host boundary, the closest local analogue of §14.5's "internal network"
      while `OD-03` withholds the real one. One `curl` handle is reused for the whole run, so the
      TCP connection and TLS session are established once and the handshake is charged to the
      warm-up; that is what a SPA does across a session. `config:cache` and `route:cache` were
      applied first, and `opcache.enable` is `On` under the FPM SAPI — *measured with `php-fpm -i`,
      not with `php -r`, which reports `false` only because `opcache.enable_cli=0`.* The percentile
      is nearest-rank over the 200 samples: `rank = ceil(95 × 200 ÷ 100) = 190`, the 190th smallest.
      Reproduce with `php artisan crm:measure-api-latency --insecure`.
      **Whole microseconds, no float on the path to the verdict.** Durations come from
      `CURLINFO_TOTAL_TIME_T`, an integer. `CURLINFO_TOTAL_TIME_US` **does not exist** in this
      build — checked against the container's curl 7.88.1 — and `CURLINFO_TOTAL_TIME` is a float in
      seconds. Nearest rank *selects* a sample rather than averaging, and the ceiling is
      `intdiv(p × n + 99, 100)` rather than `ceil()`, so the number compared against the budget is a
      duration that was actually observed. `DB-07` governs money, not latency, but the reason
      carries: a float has no business deciding a pass.
      **There is no benchmark binary, and that was checked rather than assumed.** `ab`, `wrk`,
      `hey` and `siege` are each `MISSING` under `command -v` inside `crm-php:app`. `curl` and `php`
      are present, so the probe is ext-curl.
      **A fast error is not a pass.** Any response that is not `200` fails the run outright, and so
      does a run that returned fewer samples than were asked for — a short set is a measurement that
      did not happen, not a fast one. `CURLOPT_FOLLOWLOCATION` is off on purpose: plain `http://`
      on this stack answers `301`, and following it would measure two round trips while reporting
      the `200` from the second, so a wrong URL would look like a working one.
      **The budget is read out of §14.5, not written down here.** Defect #4 on this project's list
      is "a check that is self-consistent rather than correct" — the precision test compared columns
      against the constants that built them, and `QueueConfigurationTest` compared the enum to its
      own literal. `LatencyBudgetTest` parses the `| PRF-01 | API P95 < 500 ms … |` row out of
      `docs/CRM_Documentation_EN.md` and asserts the command's constants match it. That needed a
      mount: `./crm` is the only application bind mount, so the master document is now mounted
      read-only at `/opt/crm/docs/CRM_Documentation_EN.md` in both compose and the CI test
      container — the same shape, and the same reason, as `docker-compose.yml` in 8.1. Every
      pattern carries `/u`; the file is bilingual.
      **CI runs no web server, so the split is deliberate.** `.github/workflows/php-image.yml`
      starts `ci-postgres` and `ci-redis` on `ci-net` and nothing else. What CI guarantees is the
      arithmetic and the verdict — 14 unit tests, no socket. The number itself is measured against
      the running stack and recorded here.
      **Three deliberate failures, each restored and checked by `shasum -a 256 -c`, not by eye.**
      (1) A real `usleep(600_000)` in the ping route: `P95 617.825 ms`, `FAIL`, exit 1 — proving the
      probe measures actual latency rather than merely comparing two numbers. (2) `BUDGET_MS`
      drifted `500 → 400`: "Failed asserting that 400 is identical to 500", where the 500 came from
      the master document. (3) The nearest-rank ceiling replaced by truncation: three tests failed.
      All three files verified `OK` on restore.
      **Not covered — and the gap is wide.** `/api/v1/ping` is **the only route the application
      serves without authentication**, and it touches neither PostgreSQL nor Redis: it returns
      `now()` and a request id. So this measures nginx → TLS → php-fpm → Laravel boot → route → JSON
      and nothing else. **It says nothing about any endpoint that queries, joins, paginates or
      renders**, which is every endpoint Modules 1–15 will add, and it is those that `PRF-01` will
      actually be judged on. The load generator shares CPU with php-fpm on one machine; there is no
      concurrency, no second client, and no LAN — 200 sequential requests are a latency floor, not a
      load test. `validate_timestamps` is still `On` here, where a mounted `zzz-production.ini`
      would set it `0`, so this figure is marginally pessimistic on that one setting. And per the
      deployment-debt register above, **the host is arm64 and the server will not be: `PRF-01`…
      `PRF-03` measured locally are not comparable to the server.** The command itself — the curl
      loop — is exercised only by the runs recorded here; CI cannot reach it. `PRF-02` (first screen
      < 2 s), `PRF-03` (search < 300 ms) and `PRF-05`…`PRF-08` are untouched
- [x] **8.5** Module 0 closed — and closed by reading every row rather than by ticking the ones
      that were convenient.
      **The audit came first, and it found the sign-off already almost done.** Across Module 0's
      range — Steps 0 to 8 — exactly **one** unchecked box existed when this point started: this
      one. Every other unchecked box in the file belongs to Phase 0, to the debt register, or to
      Modules 1–15, and **none of them was ticked here.** A sign-off point that improves its own
      numbers by ticking other people's rows is not a sign-off.
      **The "definition of done" block was not ticked, on purpose.** It says of itself "Copy this
      block per module", and it is the template every module measures against. Marking it `[x]`
      in place would read as *every* module being complete. It is copied below with Module 0's own
      evidence instead, which is what the block asks for.
      **Two false statements were found in this file and corrected.** The `J-15` debt entry
      argued from a `workers` compose profile that Point 8.1 deleted — the conclusion survives,
      the premise did not. And Point 8.2's narrative said the `.env.testing` divergence "is now on
      the debt register"; `grep` found no such row. It has one now. Defect #5 on this project's
      list is "claiming something is recorded when it is not", and both of these were that defect,
      sitting in the register that exists to prevent it.
      **`D-73` is absent from the master decision log — verified, not assumed.**
      `grep -n "D-73" docs/CRM_Documentation_EN.md` returns nothing. The themes are built, tested
      and shipped; the log does not know they were decided. `.claude/settings.json` blocks
      `Edit`/`Write` on that file, and **that guard was respected rather than bypassed with
      `sed`** — going around an owner's hook to close a checkbox would defeat the point of both.
      It is a register row awaiting explicit approval, together with the `§15.1` `D-72` sentence
      that Point 8.1 falsified.
      **The register was given a second half.** Its header promises that everything in it needs
      the real server. Documentation and process debt does not, so four such items now sit under
      their own heading instead of hiding behind `OD-03`.
      **Not covered:** this point verified rows, not the work behind them — it re-read what each
      point recorded and checked the file's internal claims, and it re-ran every gate, but it did
      not re-derive Steps 0–7 from scratch. The three items above that need the owner (`D-73`,
      the `§15.1` correction, and the `--color-overlay`/`color-scheme` tokens already recorded in
      `Design_System_EN.md` §10) are **recorded, not resolved**, and Module 0 is signed off with
      them open. Nothing here shortens the deployment-debt register by a single row: `P-02` and
      `OD-03` are exactly as blocking as they were

**Tests**
- [x] App runs · frontend talks to backend · database connects — point 8.3:
      `EndToEndConnectivityTest` walks the shell, the versioned API, a real PostgreSQL query and a
      real Redis round trip in one pass, and pins the `/api/v1` prefix the client and the router
      each write down separately
- [x] Switching language flips direction — asserted server-side by `SpaShellTest` and confirmed
      client-side in Chrome: `ar`/`rtl` → `en`/`ltr` → `ar`/`rtl` without a reload
- [x] A test job executes from the queue — point 8.2: a real job on each of the four queues,
      pushed to real Redis and drained by a real `queue:work` pass, with the retry bound spending
      three attempts into `failed_jobs`

**Step 8 is complete: 8.1 … 8.5.** The four `§15.1` queues are named once and checked against the
services that drain them, a real job reaches Redis and comes back out of each of them, the chain
from shell to API to PostgreSQL to Redis is asserted as a chain rather than as four independent
links, and `PRF-01` is measured with its method written down. What Step 8 is *not* is observability:
Horizon is not installed, so `OBS-02` Queue Monitor and `OBS-03` Scheduler have no screen, and
`ST-08`'s `/health` is deliberately deferred behind a test that fails if anyone quietly adds one.

### Module 0 — definition of done

The block from the top of this file, copied per its own instruction and answered with Module 0's
evidence. Seven items, and the seventh is the deferred one `D-66` redefines.

- [x] **All acceptance criteria pass** — the build plan gives Module 0 five: *app runs · frontend
      talks to backend · database connects · switching language flips direction · a test job
      executes from the queue*. All five are ticked above, each against a named test rather than
      an impression
- [x] **Permission checks enforced at the API, not just the UI** — for the one protected surface
      Module 0 has. `GET /api/v1/files/{file}/download` is gated by the parent entity (`D-38`,
      `§17`), returns 401 unauthenticated and **404 rather than 403** when the caller may not see
      the parent, so a refusal cannot confirm the file exists. There is no RBAC to enforce yet —
      `SEC-07`'s matrix is Module 1 — and the canonical registry Step 7 built is a definition, not
      an enforcement point
- [x] **Audit log records this module's operations** — the layer, and an architectural test that
      fails when a module escapes it (`D-72`, Point 6.5). `audit_log` is monthly `RANGE`
      partitioned, append-only at the database through triggers rather than by convention
      (`AUD-03`), carries correlation IDs, and has `J-15` maintaining its partitions. Module 0
      performs no business operation, so what is proven is that the recorder works and that
      coverage is enforced — not that any business event has been written, because none exists
- [x] **Loading / empty / error states on every screen** — the base states shipped with the
      design-system work and are used by the diagnostics page, which is the only screen Module 0
      owns
- [x] **Screen works in both RTL and LTR** — server-rendered `lang`/`dir` on first paint, the SPA
      reading its starting locale from the document, `ar`/`rtl` → `en`/`ltr` → `ar`/`rtl` without
      a reload, a scanner that fails the build on hard-coded user-facing strings, and a test that
      fails on physical CSS properties (`left`/`right`, `ml-`, `border-l`, `text-left`)
- [x] **Migration runs and reverses cleanly (up + down)** — `DEV-03`, and enforced rather than
      asserted: CI runs `migrate` → `migrate:reset` → `migrate` before the suite, so a migration
      without a working `down()` fails the build instead of being discovered during a rollback
- [x] **Passes on the production-matched local environment, and its deployment debt is recorded**
      (`D-66`) — Linux containers matching `§14.2`, and the debt register above carries **20
      server-gated items plus 4 that the server does not gate**. Per the note under the original
      block, this item **reverts to "deployed to the server and the smoke test passes" the moment
      the server exists**, and Module 0 does not get grandfathered

**Module 0 is signed off with three items open and named:** the `D-73` row and the `§15.1` `D-72`
correction both need the owner's approval on a hook-protected file, and `--color-overlay` plus a
`color-scheme` declaration are owed to the token set (already recorded in `Design_System_EN.md`
§10). None of them blocks Module 1; all three are on a register rather than in someone's memory.

---

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
