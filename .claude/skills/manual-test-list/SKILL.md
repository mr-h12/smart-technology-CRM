---
name: manual-test-list
description: Write the Arabic manual front-end test list that closes a module — every acceptance criterion as an action ⇒ expected result, grouped by screen, role named — and append it to the module's checklist section on a docs branch. Use when a module's last point is done, before the next module's first point.
disable-model-invocation: true
---

# Manual Test List

Argument: `$ARGUMENTS` = the module number, e.g. `10`.

CLAUDE.md § "At the end of every module — the manual test list" defines the deliverable: the gates
prove the code compiles; only a person at the screen proves the module does what the owner wanted.
A module is not handed over until this list exists.

## 1. Collect — the list is derived, not composed

```bash
grep -nE "^## Module <n> " CHECKLIST.md checklist/module-*.md
```

Read, in order:

- **the module's acceptance criteria** — the `- [x]` lines directly under its heading, above the
  point list. Every one of them must appear in the list by name; that is the completeness bar.
- **every point's one-line note** `*(date, #PR — fact)*` — each names a screen, a refusal, or a
  state the list must click.
- **the previous module's list** as the format to match, headed `#### قائمة الاختبار اليدوي`
  (`checklist/module-06.md` has the fullest example: a ⚠️ preamble, lettered screen groups with the
  criterion in italics, a `| # | الدور | الخطوة ⇒ المتوقَّع |` table).
- **the roles** — `docs/User_Personas_EN.md` for who each role is, and the module's permission
  rows in `docs/CRM_Documentation_EN.md` §3.3–3.12 for who is refused. The role *is* half of
  what is tested (`SEC-07`).

## 2. Write — one check per line, action ⇒ result

Every row is `افتح … ثم اضغط … ⇒ يجب أن ترى …` with a role. "اختبر شاشة X" is not a check.

Groups follow the walk a person takes — sidebar, list, detail, form, refusal — so the list runs
top to bottom without switching accounts mid-group. Each group names its criterion in italics.

The rows a happy path hides, each present at least once:

- Arabic RTL and English LTR, desktop and phone width;
- empty, loading, error;
- refused-by-permission — the role that must see a 403 or no nav item;
- every state transition the module added, forward and the reversal if one exists.

Close with **«لا يمكن اختباره بعد»**: each criterion whose screen belongs to a later module, and
which module. A gap the owner should see, never one the list skips.

## 3. Deliver — the message and the file, both

```bash
git fetch origin && git checkout -b docs/module-<nn>-handover origin/main
```

Append the list under the module's section — in `CHECKLIST.md` while the module is still live
there, or in `checklist/module-<nn>.md` once frozen. Print the whole list in the message as well;
CLAUDE.md says the message, not only the file.

Then the check that the list is complete, pasted:

```bash
# every criterion line under the module heading, and the row that names it
grep -cE "^- \[x\]" <section>   # criteria count
```

One number per criterion: the row(s) in the list that cover it. A criterion with no row is a stop.
Stop after the message; the owner reviews the list before the PR opens.
