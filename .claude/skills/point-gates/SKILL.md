---
name: point-gates
description: Run the six quality gates for the current point and read the CI conclusion for the exact commit at HEAD. Use before reporting any point complete and before any merge. Refuses to call a point done on a green run that belongs to a different commit.
disable-model-invocation: true
---

# Point Gates

`CLAUDE.md` § "A point is not done until its checks have been read" exists because it was broken:
**point 0.6 was reported complete with "all checks passed" while its CI run had already failed.**
The failure was real — `USER www-data` left Chromium unable to create its user-data directory,
silently breaking the PDF capability proved one point earlier. The verifier caught it on the first
try. Nobody read it.

This skill runs the gates and reads them. Running a check is not the same as reading its result.

## Preflight

```bash
docker compose ps --format '{{.Service}}\t{{.State}}'
```

`php` and `postgres` must be `running`. If they are not, nothing below is a gate — it is an error
message, and reporting it as a pass is the 0.6 failure in a new costume.

## Why every gate runs in a container

`D-66`: build on Linux containers matching §14.2, **never on the host OS directly**. Developing on
macOS hides the Linux font gap `P-01` found, which is the exact defect `P-02` exists to catch.

There is a second, measured reason. `crm/node_modules/@rolldown/` contains
`binding-linux-arm64-gnu` and `binding-linux-arm64-musl` and **no darwin binding at all** — the
tree is Linux-installed. That is why `npm run test:unit` on the macOS host dies with
`Cannot find native binding`. The dependencies are fine; the host is the wrong platform for them.

## The six gates

| # | Command | Proves |
|---|---|---|
| 1 | `docker compose exec -T php php artisan test` | `DEV-07` — backend behaviour against real PostgreSQL |
| 2 | `docker run --rm -v "$PWD/crm:/app" -w /app node:22-bookworm-slim npx vitest run` | frontend unit tests |
| 3 | `docker compose exec -T php ./vendor/bin/pint --test` | style |
| 4 | `docker compose exec -T php ./vendor/bin/phpstan analyse --memory-limit=1G` | strict typing |
| 5 | `docker compose exec -T php ./vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml` | `Presentation → Application → Domain → Infrastructure` |
| 6 | `docker compose exec -T php ./vendor/bin/deptrac analyse --config-file=deptrac.modules.yaml` | module isolation |

### Gate 2 — read this before deciding to skip it

The project has carried the belief that `npm run test:unit` "does not run on this host, CI only".
That is true of the **host** and false of the **environment**. Measured 2026-09-04: the command in
the table above runs the whole frontend suite in **~8 s** — 34 files, 589 tests — by reusing the
Linux `node_modules` already in the tree.

- `npx vitest run`, **not** `npm ci`. The dependencies are already installed and already Linux; a
  reinstall costs minutes and proves nothing new.
- `node:22-bookworm-slim` is the image CI uses (`.github/workflows/php-image.yml`, step
  "Type-check, test and build the frontend"). Same image, same result, no third opinion.
- This gate does **not** cover `vue-tsc --noEmit` or `vite build`. CI runs both in the same step.
  A point that changes `.ts` or `.vue` is type-checked only in CI — say so rather than implying
  gate 2 covered it.

## Then read CI — the part that gets skipped

```bash
git rev-parse HEAD
gh run list --branch "$(git branch --show-current)" --limit 1 \
  --json headSha,status,conclusion,displayTitle
```

**The `headSha` must equal `git rev-parse HEAD`.** A green run on the *previous* commit is the exact
shape of the 0.6 failure, and `conclusion` alone cannot tell the two apart — it says `success`
either way. Compare the SHAs before you read the conclusion, not after.

`status` must be `completed`. A `conclusion` of `null` on an `in_progress` run is not a pass; wait
for it. If the run has not started because nothing was pushed, say that — an unpushed commit has no
CI result, and "CI should pass" is an unverified claim in a different costume.

## The refusal rule

A point is reported complete only when every line below is a fact you have read:

1. all six gates ran and their real output was read,
2. CI `status` is `completed` and `conclusion` is `success`,
3. the CI `headSha` equals local `HEAD`.

If any one of them cannot be established, say so plainly in the point report instead of substituting
confidence for evidence. "A check runs somewhere you cannot see from here" is an acceptable
sentence. "Should work" is not.

## Output

Per gate: the command, its last meaningful line (counts, `PASS`, `[OK]`, `0 violations`), and
pass/fail. Then the three CI facts, with both SHAs printed side by side. Then one line: **done** or
**still in progress, because —**.
