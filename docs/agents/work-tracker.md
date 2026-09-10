# Work tracker: `CHECKLIST.md`

How an agent should find, read and update this project's work items.

## GitHub Issues are not the tracker

This repository has **never had an issue** (`gh issue list --state all` returns nothing, measured
2026-09-10). Nothing is filed there, nothing is read from there, and no skill should create one
without the owner asking for it first. An agent that opens an issue here is writing into a surface
neither developer reads.

The tracker is **`CHECKLIST.md` at the repository root**.

## How the tracker is shaped

`CHECKLIST.md` is derived from `docs/MVP_Build_Plan_EN.md` and tracks progress against it; it does
**not** create requirements. Its own preamble is explicit: *"If a box here disagrees with the build
plan, the build plan wins."*

The nesting is **Module → Step → Point**. A point is the smallest unit that can be verified on its
own, and it is the unit of delivery: one point → one PR → merged → the next point.

State is carried by the checkbox, per the file's own legend:

| Box | Meaning |
|---|---|
| `[ ]` | not started |
| `[~]` | in progress |
| `[x]` | done **and verified** |

`[x]` is not "the code is written". The definition of done is a section of its own
(`## Definition of done — applies to every module`), and a point that has not had its checks run
and pasted is not `[x]`.

## Where each kind of item lives

| Looking for | Read |
|---|---|
| A module's points and their state | `## Module N — …` in `CHECKLIST.md` |
| Who owns a module | `## Two developers — module ownership and the shared trunk` |
| Work that cannot be verified without the real server | `## Deployment debt — D-66` |
| Work blocked on an owner decision or ordinary effort | `## Debt the server does not gate — Point 8.5` |
| A change to `CLAUDE.md` / `AGENTS.md` | `## Agent guide revisions — owner-directed` (`G-xx`) |
| A change to the shell, outside any module | `## Shell revisions — owner-directed` |

⚠️ The ownership table's **State** column is maintained by hand and is only updated when a module is
**entirely** finished. Mid-module it reads stale by design. Check git history and the module's own
point entries rather than trusting that column at a glance.

## The decision record is `D-xx`, not ADRs

There is no `docs/adr/` and no `CONTEXT.md` in this repository. Decisions live in the master
documentation:

- **`D-xx`** — `docs/CRM_Documentation_EN.md` §2, the Decision Log.
- **`OD-xx`** — the same file §19.1, Open Decisions & Risks.
- **`DB-xx`, `SEC-xx`, `ST-xx`, `J-xx`, `BK-xx`, `OBS-xx`** — the same master documentation.
- **`G-xx`** — agent-guide revisions, recorded in `CHECKLIST.md` itself.

Precedence when sources conflict is set out in `CLAUDE.md`; the master documentation wins, and
`CLAUDE.md` / `AGENTS.md` never override it. **Never silently reinterpret or edit a documented
decision** — record a proposed change as a new decision and flag it for approval.

If no authoritative source supports a behaviour you are about to build, that is not a gap to fill
with a sensible default. Stop and request a decision.

## Pull requests

PRs are the delivery surface, and `gh` is the right tool for them:

- **Read**: `gh pr view <number> --comments`, `gh pr diff <number>`
- **Checks**: `gh pr checks <number>`
- **Open**: `gh pr create --base main --head <branch>`
- **Merge**: `gh pr merge <number> --merge` — a plain merge commit, never squash or rebase; each
  developer merges their own PR once CI is green.

⚠️ **Verify the base before merging anything.** `gh pr view <n> --json baseRefName` — a PR can be
open, green and `MERGEABLE` while being based on another feature branch rather than `main`. On
2026-09-01 five PRs merged "successfully" into their own stacked bases and never reached `main`.
After a merge, confirm the commits are actually reachable:
`gh api repos/<owner>/<repo>/compare/main...<sha>` should report the branch behind, not ahead.

## Before editing `CHECKLIST.md`

It is a shared file both developers append to every point. Append **inside your own block**; never
reorder or reformat somebody else's. The same rule governs the other shared files listed in
`CLAUDE.md`.
