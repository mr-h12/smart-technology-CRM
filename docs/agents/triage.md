# Triage

What "triage" means in this repository, and what it does not.

## There is no label workflow

Measured 2026-09-10: `gh label list` returns the **nine GitHub defaults** and nothing else —
`bug`, `documentation`, `duplicate`, `enhancement`, `good first issue`, `help wanted`, `invalid`,
`question`, `wontfix`. None of them has ever been applied, because there are no issues to apply
them to.

A skill that speaks in canonical triage roles — `needs-triage`, `needs-info`, `ready-for-agent`,
`ready-for-human`, `wontfix` — has **no mapping here**. Four of those five labels do not exist, and
the fifth (`wontfix`) exists only because GitHub creates it with every repository. Do not apply
them, do not create them, and do not assume a skill's default vocabulary is live just because the
CLI accepts the command.

## What triage actually is here

Deciding what is worked next is the owner's, exercised through `CHECKLIST.md`, not through labels.
The states an item can be in are the ones the file itself writes:

| Signal in `CHECKLIST.md` | What it means |
|---|---|
| `[ ]` | Not started. |
| `[~]` | In progress — or, on an acceptance criterion, **partly built with the missing clause named in the entry itself**. |
| `[x]` | Done and verified. |
| ⏸ **deferred** | Postponed with a reason and a condition for resuming, not cancelled (e.g. `P-02` under `D-66`). |
| ⚠️ | A cost, gap or risk carried knowingly. The text beside it says what is not covered. |
| *owner decision, <date>* | Blocked on the owner. An agent must not resolve one of these by choosing a sensible default. |
| **Deployment debt — `D-66`** | Cannot be verified without the real server; nothing on this register counts as done until it has run there. |
| **Debt the server does not gate — `Point 8.5`** | Not blocked on the server. Blocked on an owner decision or on ordinary work. |

## The rule that matters most

An unbacked requirement **fails closed** and is recorded — it is never guessed at. The row scopes
are the worked example: §3.2 defines five, only two have a mechanism, and the other three resolve to
no rows rather than to a plausible interpretation. See `CustomerRowScope` and `DealRowScope`, whose
docblocks carry the reasoning.

When you find work that has no authoritative source behind it, the triage outcome is **an entry that
names the gap and an owner decision requested** — not an implementation with a sensible default, and
not silence.
