---
name: session-end
description: Compress this session's work into CLAUDE_HANDOFF.md, verified against git/gh/docker rather than memory, so the next session's /session-start has something true to check. Use at the end of any session.
disable-model-invocation: true
---

# Session End

Counterpart to `/session-start`: that skill verifies `CLAUDE_HANDOFF.md` against the real repo
before trusting it. This skill is what makes that check worth running — every fact it writes
must already be true when written, not remembered as true from earlier in the session.

## 1. Verify before writing anything

Re-run these now, even for facts you're sure of from earlier this session — "I already know
that" is how a handoff ships wrong:

```bash
git status
git log --oneline -10
git branch --show-current
git rev-parse HEAD
```

For every PR opened, merged, or discussed this session:

```bash
gh pr view <n> --json number,state,mergedAt,statusCheckRollup,baseRefName,headRefOid,url
```

If gates or CI ran this session, cite the actual last-read result — not "should still pass."
Anything not re-checked at the end goes in the doc marked unverified, not stated as fact.

## 2. Overwrite CLAUDE_HANDOFF.md, keeping its existing shape

Match the current file's section structure (Current Goal · Key Findings · Completed Actions ·
Plan · Environment · Non-code items) — a reader who's seen one handoff shouldn't have to relearn
the format on the next. If the file doesn't exist yet, open with a one-line title naming the
module/step/point state, then those same sections.

**Current Goal** — the single next action, named precisely: a point number, not "keep going."

**Key Findings** — only what the next session would otherwise pay to re-derive: exact SHAs and
PR numbers (verified this pass, not recalled from earlier), the file inventory the current step
owns, API shapes, facts that cost a real failure to learn, owner decisions still open and
exactly what they block. If it's one `grep` away, leave it out.

**Completed Actions** — what actually landed, with evidence (PR numbers, merged state, CI
conclusion), not intent.

**Plan** — the immediate next point in enough detail to start cold, plus the remaining point
list with status.

**Environment** — the exact copy-pasteable commands that work in this repo right now.

**Non-code items** — anything blocked on a person, carried forward verbatim until actually
resolved.

## 3. Close

State in one line what changed since the last handoff — points closed, PRs merged, decisions
made — so the owner can tell this pass actually updated it rather than restating the old one.
