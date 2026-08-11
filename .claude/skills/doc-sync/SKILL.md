---
name: doc-sync
description: Check and repair semantic synchronization between the CRM documentation twins — CLAUDE.md against AGENTS.md, and each English document against its Arabic companion. Use after changing any project rule, decision, or acceptance criterion, and before sign-off. A project rule that differs between twins is a defect by the documents' own definition.
disable-model-invocation: true
---

# Doc Sync

The documentation set declares several pairs that must stay semantically synchronized. Divergence is a defect, not a style difference — `AGENTS.md` says so explicitly.

## The declared pairs

| English | Twin | Declared in |
|---|---|---|
| `CLAUDE.md` | `AGENTS.md` | Both files' own text |
| `CRM_Documentation_EN.md` | `CRM_Documentation.md` | Master header |
| `MVP_Build_Plan_EN.md` | `MVP_Build_Plan.md` | Build-plan header |
| `Design_System_EN.md` | `Design_System_AR.md` | Line 5 |
| `OpenAPI_Contract_EN.md` | `OpenAPI_Contract_AR.md` | Line 5 |
| `Documentation_Map_EN.md` | `Documentation_Map_AR.md` | Line 4 |

Several Arabic companions may not exist yet. A missing twin is a reportable gap, not a silent pass.

## What to compare

Compare **rules**, not wording. A twin may phrase things differently; it may not carry a different rule. Check that both sides agree on:

- Decision IDs and their content (`D-xx`, `DB-xx`, `J-xx`, `SEC-xx`, `MAIL-xx`, `OD-xx`)
- Numbers: thresholds, limits, counts, percentages, durations, screen counts
- Scope and permission statements
- Acceptance criteria and their Given/When/Then content
- Calculation formulas and the order of operations
- Which items are in MVP versus deferred

## Known divergence classes in this project

These have all occurred at least once — check them first:

1. **A section present in one twin and absent in the other.** `AGENTS.md` previously lacked the entire Internationalization/UI section, losing the specific VPN-disconnected-message rule.
2. **Off-by-one counts.** Source lists that say "N items" and then enumerate N+1.
3. **Code namespace collisions.** The same ID used for two unrelated things in different sections.
4. **Decision text drifting from its own formula.** A decision saying "total" where §5 computes from `subtotal`.
5. **A permission matrix cell contradicting a decision** that governs the same role.

## Procedure

1. List the pairs and whether both sides exist.
2. For each existing pair, diff by rule using the checklist above. Quote both sides for every divergence.
3. Classify each: **defect** (rules differ), **gap** (twin missing or section absent), or **cosmetic** (wording only — not actionable).
4. Report before editing. Never resolve a divergence by guessing which side is right: the master documentation wins over everything, and where a decision itself is ambiguous, ask.
5. Apply approved fixes to both sides in the same change.

## Guardrail

The three authoritative sources — `CRM_Documentation_EN.md`, `MVP_Build_Plan_EN.md`, `Coding_Standards_EN.md` — are protected by a `PreToolUse` hook. If a fix requires editing one of them, that is a documented decision change: record it as a new decision and get explicit approval first.
