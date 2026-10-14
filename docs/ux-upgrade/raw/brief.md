# F-42 · 2.1 code audit brief (read-only)

Repo: /Users/yousefhasabo/Projects/crm-system (Vue 3 + TS SPA under crm/resources/js, Tailwind + CSS tokens).
You are auditing UI CODE ONLY. Do NOT edit any repo file, do NOT commit, do NOT run tests/builds/npm.
The only file you may write is your own notes file named in your task.

## Rule sources (read them first)
1. Web Interface Guidelines: /private/tmp/claude-501/-Users-yousefhasabo-Projects-crm-system/712353ae-b08b-4bc1-bf7a-fad7b2017ce7/scratchpad/ux/wig.md (all rules).
2. ui-ux-pro-max: /Users/yousefhasabo/.claude/plugins/cache/ui-ux-pro-max-skill/ui-ux-pro-max/2.13.0/.claude/skills/ui-ux-pro-max/references/quick-reference.md (priorities 1,2,5,6,7,8,9 matter; skip charts/perf images/GSAP).
3. The PROJECT'S authority: docs/Design_System_EN.md lines 117-284 (§4 Foundations … §8 Accessibility gates) and tokens in crm/resources/css/tokens.css, crm/resources/css/app.css. Where a generic rule conflicts with Design_System_EN.md, the Design System wins — report the conflict as a note, not a finding.

## Context you must respect
- Arabic (RTL) is the default locale, English (LTR) second. Logical properties (ms-/me-/ps-/pe-/start/end) matter; physical left/right is a finding unless intentional.
- Three themes via [data-theme] on :root; colours must come from tokens (var(--color-*)), raw hex/rgb or Tailwind palette colours (text-gray-500 etc.) in a component is a finding.
- Desktop primary for office roles; must still work at 375px.
- UI-only scope: a finding's fix must not need an API, permission, route or calculation change. If it would, say "needs non-UI change" and still record it.
- Do not report things that are deliberate and documented in a code comment as a defect; quote the comment instead.

## Output — write to /private/tmp/claude-501/-Users-yousefhasabo-Projects-crm-system/712353ae-b08b-4bc1-bf7a-fad7b2017ce7/scratchpad/ux/notes-<slice>.md
One finding per line, pipe-separated, no prose between lines:
`id | file:line | source (WIG <rule> / UUPM <rule-id> / DS §x.y) | category (a11y, forms, focus, rtl, theme-token, layout-375, states, feedback, nav, typography, consistency, content) | severity (blocker / major / minor / polish) | what is in the code (quote ≤ 1 line) | the user impact, naming the role if role-specific`
Severity: blocker = a user cannot complete a task or a screen-reader/keyboard user is locked out; major = slows/misleads daily work or fails WCAG AA; minor = inconsistent/rough but works; polish = taste.
Then a final section "## Patterns" (≤ 10 lines): problems repeated across files, with counts from grep (paste the grep command).
Then "## Not checked" listing what you could not verify from code alone (e.g. actual contrast of a token pair in a theme, real focus order) — those go to the browser pass.
Every finding needs a real file:line you opened. No finding from memory.
Reply to me in ≤ 150 words: counts by severity, the 5 worst, and the notes file path.
