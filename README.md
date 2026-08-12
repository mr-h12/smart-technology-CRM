# Smart Technology CRM

Internal, on-premise CRM for the Sales and Procurement teams, built to expand into a full ERP.
Arabic and English from the first release, desktop web for office staff, and an online-only PWA
for the field team.

| | |
|---|---|
| **Hosting** | Physical server on company premises · VPN required for external access |
| **Users** | Tens to hundreds, internal only |
| **Languages** | Arabic + English (RTL / LTR) from day one |
| **Devices** | Desktop for office roles · Mobile PWA for Outdoor Sales |
| **Mode** | Online only — no offline mode |
| **Working days** | Sunday → Thursday |
| **Stack** | Laravel (D-57) · PostgreSQL · Redis · Meilisearch · REST `/api/v1` · WebSockets · SPA + PWA |

---

## Status

**Not yet started on Module 0.** Three gates are still closed, and none of them are technical.

| Gate | Owner | Status |
|---|---|---|
| P-01 — Arabic PDF renders correctly | — | ✅ Passed |
| P-02 — Deploy to the real server over VPN | Server administrator | ⬜ Blocked |
| OD-01 — Are additional items taxable? | Accountant | ⬜ Blocked |
| OD-03 — Server specifications | Server administrator | ⬜ Blocked |

`docs/MVP_Build_Plan_EN.md` §6 lists OD-01 and OD-03 as required *before writing code*.

Full progress tracking lives in **[CHECKLIST.md](CHECKLIST.md)**.

---

## Repository layout

```
├── CLAUDE.md              Execution guide for Claude Code   (must stay at root)
├── AGENTS.md              Same rules, tool-neutral          (must stay at root)
├── CHECKLIST.md           Detailed MVP progress checklist
├── docs/                  The specifications
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
| 1 | [CRM_Documentation_EN.md](docs/CRM_Documentation_EN.md) | Master requirements and the decision log (D-01…D-57). **Authoritative.** |
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
- **Rounding applies to the final total only**, by the configured unit for that currency.
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
| OD-01 | Are additional items taxable? | Accountant |
| OD-03 | Server specifications | Server administrator |
| OD-02 | PDF template | Effectively answered by P-01 — confirm |
| OD-04 | VPN type and concurrent capacity | Server administrator |
| OD-05 | Expected daily workload | Management |
| OD-06 | Company holiday calendar | HR |
| — | **VAT before or after discount?** The company's PO charges VAT on the pre-discount amount; §5.2 discounts first. Different tax base. | Accountant |
| — | Five English headers still declare an Arabic companion, but Arabic is now reading-only. Correcting them touches hook-protected files. | You |
