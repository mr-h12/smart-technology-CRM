# Modules

The twelve modules `AP-02` names, each with the four layers `AP-03` requires.
Empty for now — the structure exists before the code so that no module is ever
"temporarily" put somewhere else.

## The layers, and what may depend on what

```
Presentation  →  Application  →  Domain  ←  Infrastructure
```

| Layer | Holds | Never holds |
|---|---|---|
| `Presentation` | HTTP controllers, form requests, API resources, console commands | business calculations, permission decisions, direct queries |
| `Application` | use cases, transaction coordination, authorization invocation | UI concerns, raw SQL |
| `Domain` | entities, value objects, policies, state transitions, domain events | **HTTP, UI, framework, storage, queue or vendor SDK calls** |
| `Infrastructure` | repositories, storage/queue/PDF/mail adapters | unbounded business or permission logic |

`Domain` depending on nothing is the load-bearing rule. Coding Standards §3.1
lists "framework" among what the domain must not contain, which is why
`deptrac.layers.yaml` gives `Domain` an empty ruleset — it may not reference
`Illuminate\*` at all.

## Crossing a module boundary

A module owns its domain rules, use cases, persistence interface and API
surface. It must not reach into another module's classes — `CLAUDE.md` is
explicit that cross-module work goes through interfaces or domain events, never
another module's models.

`deptrac.modules.yaml` currently forbids **every** module-to-module dependency.
That is deliberately stricter than the final state: when a legitimate shared
interface appears, it is added as an explicit exception with a written reason,
which makes each crossing a decision instead of a habit.

## Why this is enforced and not just written down

`ERP-01` expects each module to be extractable as a service later. A boundary
that exists only in a README is one refactor away from not existing. Both rules
run in CI, and both were confirmed to fail on a deliberate violation before
being trusted.
