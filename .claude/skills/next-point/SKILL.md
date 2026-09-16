---
name: next-point
description: Open one checklist point the way CLAUDE.md requires — a fresh branch from origin/main, the point's line read from CHECKLIST.md, its criteria restated, the files it will touch listed — then stop for approval before any edit. Use at the start of every point.
disable-model-invocation: true
---

# Next Point

Arguments: `$ARGUMENTS` = `<module> <point>`, e.g. `10 1.1`. Both required; ask if either is missing.

CLAUDE.md § "Working Rhythm" is one point per turn on its own branch. The drift this skill
prevents was real: a point stacked onto the previous feature branch cost a full rebase and a
force-push. The branch is created here, from `origin/main`, and nowhere else.

## 1. Preflight — a dirty tree or a stale main is a stop, not a warning

```bash
git status --porcelain
git fetch origin
git rev-parse origin/main
git log --oneline -1 origin/main
```

`git status --porcelain` must print nothing (untracked `CLAUDE_HANDOFF.md` and `.claude/` are the
known exceptions). Anything else belongs to the previous point: report it and stop.

## 2. The point's line — read it, do not recall it

```bash
grep -nE "^\s*- \[[ x]\] \*\*<point>\*\*" CHECKLIST.md
```

Print the line and the lines under it until the next `- [`. Then confirm all three, or stop:

- the box is `[ ]` — a ticked point is finished; a missing line is an unpublished point list, and
  CLAUDE.md § "Working Rhythm" 3 says an unapproved point list is an unapproved plan;
- it belongs to Module `<module>` — the heading above it says so;
- its step's point list carries an "approved" note (Module 8's read `approved by merging #131`).

## 3. The branch

```bash
git checkout -b feature/<module-slug>-<point-slug> origin/main
git branch --show-current
```

`<module-slug>` is the module's name from the CHECKLIST heading, kebab-cased (`customer-response-pos`);
`<point-slug>` is two or three words from the point's line (`feature/customer-response-pos-po-migration`).
Existing branches show the shape: `git branch -r | grep feature/ | tail -5`.

## 4. Restate, then stop

Per CLAUDE.md § "Change Discipline": before coding, the request becomes observable success criteria.
Output, then wait for the owner:

1. **The point**, verbatim from CHECKLIST.md.
2. **Sources to re-read** — the `D-xx`, `§x`, `DB-xx` the line cites, and the Documentation_Map row
   for the module. Open them now; cite the line, not the memory of it.
3. **Given / When / Then** — one block per acceptance criterion the point closes.
4. **Files expected to touch**, each with the layer it lives in (`Presentation → Application →
   Domain → Infrastructure`) and whether it is inside the module's directory.
5. **The failing test to write first** (`Delivery Protocol` 1), by name.
6. **Branch**: the output of `git branch --show-current`.

No edit happens in this turn. The next turn, after approval, writes the failing test.
