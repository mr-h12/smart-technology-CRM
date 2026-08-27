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
These are not. They are blocked on an owner decision or on ordinary work, they were each
created by work already merged, and keeping them under a heading that says "wait for the server"
would hide them behind `OD-03` indefinitely.

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
- [ ] **`docker-compose.yml` can change without CI running** — found 2026-08-23 while closing
      Point 8.5, by noticing that this point's own commit triggered no run. `php-image.yml` filters
      on `docker/php/**`, `crm/**` and itself. **`docker-compose.yml` is in none of them**, and yet
      `QueueConfigurationTest` parses that file to assert the worker `--queue=` flags match
      `config/queue.php` — the check Point 8.1 built precisely because those two live where neither
      can see the other. A commit that edits only compose would skip the one test that validates
      it. Owed: add `docker-compose.yml` to the `paths` filter. Not done here, because it is a
      change to CI behaviour and this point is a sign-off, not a fix
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

---

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
- [ ] **3.3** `GET`/`POST /api/v1/fx-rates` — a new rate is a new row, with §3.12 rule 4's mandatory
      audit entry, behind `admin.fx_rates` (the Manager holds this one)
- [ ] **3.4** `/api/v1/managed-lists/{list}` and `/api/v1/system-limits`

**Acceptance criteria**
- [ ] FX rate edit → old rate stays in history + mandatory audit entry
- [ ] New sector added in settings → appears in the customer form **without a deployment**
- [ ] Currency rounding unit changes → **only new quotations** are affected
- [x] Rounding units default correctly: EGP `1` · USD `0.01` · EUR `0.01` *(Point 2.1 — seeded from
      `Currencies` and asserted against §5.3 as the documentation publishes it, not against the class
      that produced them)*
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
