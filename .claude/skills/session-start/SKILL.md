---
name: session-start
description: Load CLAUDE_HANDOFF.md, verify every claim in it against real source (git, gh, docker, CHECKLIST.md), and report what's confirmed vs stale before touching anything. Use at the start of every session.
disable-model-invocation: true
---

# Session Start

`CLAUDE_HANDOFF.md` is written at the end of a session and can go stale the moment a PR merges
or a point lands after it was written. Trust nothing in it until it's checked against the real
repo — that check is the entire point of this skill, not a formality before the real work.

## 1. Read

- `CLAUDE_HANDOFF.md` in full.
- `CHECKLIST.md` — just the section the handoff's "Current Goal" points at.

## 2. Verify every claim in one pass

```bash
git status
git log --oneline -10
git branch --show-current
docker compose ps --format '{{.Service}}\t{{.State}}'
```

For every PR the handoff names as open, green, unmerged, or stacked:

```bash
gh pr view <n> --json number,state,mergedAt,statusCheckRollup,baseRefName,headRefOid
```

Compare `headRefOid` to the SHA the handoff recorded, and `state`/`mergedAt` to what the handoff
claims. A PR the handoff calls "green and unmerged" that is actually merged isn't a footnote —
it changes what the next point even is.

## 3. Report — confirmed vs stale

One short table: each claim in the handoff's "Current Goal" and "Completed Actions," and whether
this session's checks confirm it, contradict it, or can't be verified (say why, don't guess).
A stale claim gets corrected out loud, not silently — the owner reads this table too.

## 4. State the next point — from verified reality, not the handoff's memory

If the checks change what point is next (the handoff's "next" point turns out already merged,
a blocker cleared, a new PR landed it didn't know about), say so explicitly before proposing
anything.

## 5. Stop

Per `CLAUDE.md` Working Rhythm: publish the point (or, if the step's point list isn't yet
approved, the point list) and wait for the owner. Do not start implementing on this turn —
verification is the deliverable of this skill, not a preamble to one.
