# Smart Technology CRM

Internal, on-premise CRM for the Sales and Procurement teams, built to expand into a full ERP.
Arabic and English from the first release, desktop web for office staff, and an online-only PWA
for the field team.

| | |
|---|---|
| **Hosting** | Physical server on company premises · Cloudflare Tunnel + Access for external reach (D-59) |
| **Users** | Tens to hundreds, internal only |
| **Languages** | Arabic + English (RTL / LTR) from day one |
| **Devices** | Desktop for office roles · Mobile PWA for Outdoor Sales |
| **Mode** | Online only — no offline mode |
| **Working days** | Sunday → Thursday |
| **Stack** | Laravel (D-57) · PostgreSQL · Redis · Meilisearch · REST `/api/v1` · WebSockets · Vue 3 + TS SPA and PWA (D-67) |

---

## Status

**Module 0 can start.** `D-66` moves development onto a production-matched Docker environment while the
company server is unavailable, so the remaining gates block deployment rather than development.

| Gate | Owner | Status |
|---|---|---|
| P-01 — Arabic PDF renders correctly | — | ✅ Passed |
| P-02 — Deploy to the real server, reachable over Cloudflare | Server administrator | ⏸ Deferred (D-66) |
| OD-01 — Are additional items taxable? | Accountant | ✅ Closed — no |
| OD-03 — Server specifications | Server administrator | ⬜ Blocks the server, not the code |

Everything the server would have proven is tracked as **deployment debt** in
[CHECKLIST.md](CHECKLIST.md) — paid off when the server arrives, not written off.

Full progress tracking lives in **[CHECKLIST.md](CHECKLIST.md)**. Schema design and the
decisions it still needs are in **[design/DATABASE.md](design/DATABASE.md)**.

Working notes live in [`memory/`](memory/00-index.md) as plain markdown — open questions,
the people who own them, and the reasoning behind decisions. Start at
[`memory/00-index.md`](memory/00-index.md). **A decision that lives only in `memory/` is a
decision the agents cannot see.** Approved decisions belong in `docs/` as `D-xx`.

---

## Getting started

From an empty machine to a running application. Steps 3 and 5 are the ones
[`runbooks/startup.md`](runbooks/startup.md) does not cover — everything else defers to that
runbook instead of repeating it, so there stays one place to correct when it changes.

**1 — Prerequisites.** Docker with Compose v2, and git. Nothing else is installed on the host: PHP,
Composer, Node and PostgreSQL all run in containers. That is the point of `D-66` — developing on
the host OS hides the Linux font gap `P-01` found, which is the defect `P-02` exists to catch.

**2 — Clone, and turn the hooks on.**

```bash
git clone https://github.com/mr-h12/smart-technology-CRM.git
cd smart-technology-CRM
git config core.hooksPath githooks
```

The third command is per clone and is not optional. It enables
[`githooks/pre-push`](githooks/pre-push), which refuses a direct push to `main`. `git config` writes
to `.git/config`, which is not tracked, so nobody can enable it on your behalf — and pointing
`core.hooksPath` at a directory that does not exist disables hooks **silently**, so run it from
inside the clone, after the clone.

**3 — Compose's environment.** `docker-compose.yml` reads a `.env` at the repository root, and five
of its variables are declared `:?` — required, with compose refusing to start rather than quietly
defaulting.

```bash
cp .env.example .env
```

`POSTGRES_PASSWORD`, `REDIS_PASSWORD` and `MEILI_MASTER_KEY` ship **empty on purpose** (`SEC-17` — a
committed credential is a shipped credential). Generate your own; `MEILI_MASTER_KEY` must be at
least 16 bytes. This file is compose's environment, not Laravel's: the application reads `crm/.env`,
which step 4 creates.

**4 — Start the stack, and build once.** Follow [`runbooks/startup.md`](runbooks/startup.md) →
*Start the stack*, then *First run, or after pulling*. It starts the services in the `ST-02` order
and produces the four things a fresh clone has none of: `vendor/`, `public/build`, `crm/.env` with
an `APP_KEY`, and the `crm_test` database. The application answers on `https://localhost:8443` with
a self-signed certificate, so the browser will warn.

**5 — Schema and accounts.** Neither is in the runbook, and the application is unusable without
both.

```bash
docker compose exec -T php php artisan migrate
```

Nothing applies migrations for you — not on a fresh clone, and not after pulling a branch that adds
one. `php artisan migrate:status` lists what has and has not run.

Then set `SEED_TEST_USER_PASSWORD` in `crm/.env` and seed. The password must satisfy `D-28` — at
least 8 characters, letters and numbers — because `UserSeeder` refuses to create an account the
login form would reject, and it has no default on purpose.

```bash
docker compose exec -T php php artisan db:seed
```

That creates eight accounts, one per `§3.1` role (`DEV-08`), each with the password you chose, all
on the `.test` domain that RFC 6761 reserves as never-resolvable:

`super.admin@example.test` · `ceo@example.test` · `manager@example.test` · `team.leader@example.test`
· `outdoor.supervisor@example.test` · `outdoor.sales@example.test` · `indoor.sales@example.test` ·
`procurement@example.test`

**Before your first commit**, read [`CHECKLIST.md`](CHECKLIST.md) → *Two developers*. It carries who
owns which module, and the rules for the files both developers edit on nearly every point.

> ⚠️ Paths passed **into** the container carry no `crm/` prefix — the container's working directory
> is already `crm/`. `./vendor/bin/pint app/Modules/...` works; `crm/app/Modules/...` is not
> readable. Host-side tools (`git`, `shasum`) still need the prefix.

---

## Repository layout

```
├── CLAUDE.md              Execution guide for Claude Code   (must stay at root)
├── AGENTS.md              Same rules, tool-neutral          (must stay at root)
├── CHECKLIST.md           Detailed MVP progress checklist
├── docs/                  The specifications — authoritative
├── design/                Schema and ERD, before any migration
├── memory/                Working notes — not a source
├── prompts/               Prompts authored for the coding agent
├── arabic/                Arabic reading copies (not part of the system)
└── prototypes/
    └── p01-arabic-pdf/    Arabic PDF prototype — retires risk R-02
```

`CLAUDE.md` and `AGENTS.md` stay at the repository root deliberately: agent tooling discovers
them from the working directory and its parents, so moving them into `docs/` would silently
stop them loading.

## The documents

Read in this precedence order. When two sources disagree, the higher one wins.

| # | Document | What it governs |
|---|---|---|
| 1 | [CRM_Documentation_EN.md](docs/CRM_Documentation_EN.md) | Master requirements and the decision log (D-01…D-65). **Authoritative.** |
| 2 | [MVP_Build_Plan_EN.md](docs/MVP_Build_Plan_EN.md) | Module order and acceptance criteria |
| 3 | [Coding_Standards_EN.md](docs/Coding_Standards_EN.md) | Mandatory engineering practices |
| 4 | [OpenAPI_Contract_EN.md](docs/OpenAPI_Contract_EN.md) | API conventions — read before any endpoint |
| 4 | [Design_System_EN.md](docs/Design_System_EN.md) | Tokens, components, RTL/LTR — read before any screen |
| 4 | [User_Personas_EN.md](docs/User_Personas_EN.md) | The eight roles |
| — | [Documentation_Map_EN.md](docs/Documentation_Map_EN.md) | **Start here.** Maps each task to the exact sections to read |

Start with the Documentation Map. It exists so you load the few sections a task actually needs
instead of reading three thousand lines every time.

**Arabic:** [`arabic/`](arabic/README.md) holds Egyptian-Arabic translations of the
specifications and working files. **They are reading copies only — not part of the system, not
authoritative, and not maintained in step with the English.** Everything the project runs on is
the English set above. Some English headers still name an Arabic companion; those lines predate
this decision.

## Build order

Modules ship strictly in this sequence — each depends on the ones before it. Settings,
currencies, and permissions land before quotations because quotations need all three.

```
0  Foundation          4  Catalog & Suppliers   8  Approvals        12  Outdoor Visits
1  Identity & RBAC     5  Deals                 9  PDF              13  Reports
2  Settings & FX       6  Supplier Quotations  10  Customer Response 14  Dashboard
3  Customers           7  Customer Quotations  11  Procurement      15  Meilisearch
```

A module is not done until its acceptance criteria, tests, permission enforcement, audit
coverage, RTL/LTR states, and a reversible migration all pass.

## Rules that are easy to break

- **Money is `Decimal`.** Floating point is forbidden anywhere near a price.
- **All calculations run in the backend.** The UI may preview; it is never the source of truth.
- **Rounding applies to the final total only**, by the configured unit for that currency — and rounding is optional per currency (D-65).
- **The discount is subtracted before tax** (D-64), and delivery/installation are never taxed (D-62).
- **Permissions are enforced at the API and row level.** Hiding a button is not authorization.
- **Nothing is hard-deleted.** Deactivate or archive.
- **Customer PDFs never show supplier names, supplier prices, costs, or margins.**
- **No hard-coded user-facing strings**, enums, permissions, or theme values.

## Prototypes

### P-01 — Arabic PDF (passed)

Retires `R-02`, the highest technical risk in the project: Arabic glyph shaping in PDF.

```bash
cd prototypes/p01-arabic-pdf
npm install
node render.js
```

Renders the quotation in Arabic and English from one template, in the Smart Technology house
style. All fonts are embedded, and the build fails if either locale spills past one page.
See its [README](prototypes/p01-arabic-pdf/README.md) for what was found and fixed.

## Working with agents

The repository ships its own automation in `.claude/`:

| | |
|---|---|
| `/module-kickoff` | Starts a module with the right context loaded and a full scaffold |
| `/doc-sync` | Checks the English ↔ Arabic and CLAUDE ↔ AGENTS document pairs |
| `permission-matrix-auditor` | Audits endpoints against the permission matrix |
| `pricing-invariant-reviewer` | Checks money code against the §5 calculation chain |

A `PreToolUse` hook blocks edits to the three authoritative documents. That is deliberate:
changing a documented decision should be a decision, not an edit. Disable it via `/hooks` when
you genuinely need to change one.

## Open questions

| # | Question | Owner |
|---|---|---|
| OD-03 | Server specifications | Server administrator |
| OD-02 | PDF template | Effectively answered by P-01 — confirm |
| OD-05 | Expected daily workload | Management |
| OD-06 | Company holiday calendar | HR |
| — | **Is the PO's «إشعار خصم» line a sale discount or a separate credit note?** If it is a credit note it does not belong on the quotation at all. Raised by D-60, still open under D-64. | Accountant |
| — | Five English headers still declare an Arabic companion, but Arabic is now reading-only. Correcting them touches hook-protected files. | You |

Closed: **OD-01** (additional items are not taxed — D-62) · **OD-04** (Cloudflare instead of VPN — D-59) ·
**VAT ordering** (tax is calculated after the discount — D-64) · **rounding** (optional per currency — D-65).
