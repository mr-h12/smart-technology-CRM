# CRM System — Design System Specification

> **Status:** Design baseline for implementation.  
> **Applies to:** Desktop web and the Outdoor Sales PWA, Arabic/English, RTL/LTR.  
> **Arabic:** reading-only translation in `arabic/`; not a maintained companion (D-58).

## 1. Purpose and Boundaries

This design system makes the CRM consistent, accessible, and efficient for data-heavy daily work. It implements the documented requirement for a design system before the first screen and the user's requirement for three selectable main UI themes.

It is a presentation specification only. It must never change permissions, API behavior, calculation rules, status transitions, audit requirements, or information visibility.

### 1.1 Design principles

1. **Operational clarity first:** Status, owner, age, required action, and validation must be easy to scan.
2. **One system, three appearances:** Components and interaction behavior are identical across themes; only visual tokens change.
3. **Arabic is first-class:** Arabic is not a translated afterthought. RTL and LTR are equal supported layouts.
4. **Dense but calm:** CRM screens may show high information density, with deliberate hierarchy and whitespace.
5. **Safe actions are explicit:** Destructive-equivalent actions (archive, deactivation, return, rejection) require clear confirmation and reason inputs where required.

## 2. Odoo-Inspired Pattern Library

The first theme adopts Odoo CRM interaction patterns, not Odoo branding, code, or visual assets. Odoo documents a stage-based opportunity Kanban, with cards that show essential information and support stage movement; it also distinguishes list, form, search, Kanban, graph, pivot, calendar, and map views. This CRM uses only the patterns that fit its documented scope. [Odoo CRM](https://www.odoo.com/app/crm) · [Odoo view documentation](https://www.odoo.com/documentation/19.0/developer/reference/user_interface/view_records.html)

### 2.1 Patterns to adopt

| Pattern | CRM implementation |
|---|---|
| Persistent application shell | A collapsible module sidebar, placed on the left in LTR and right in RTL. It contains only screens permitted for the signed-in role. |
| Context header | Page title, breadcrumb where useful, scoped search, filters, view switcher, and permitted primary action. |
| Kanban pipeline | Deal cards grouped by documented deal status. Cards show code, customer, owner, latest activity, and permitted financial/status indicators. Dragging is permitted only when the API authorizes the transition. |
| Data list | Server-paginated table with column sorting, filters, saved user view choices where documented, bulk actions only when permission permits them, and an empty state with the permitted next action. |
| Record form | Clear field groups, read-only calculated fields, inline validation, audit/timeline access, and explicit Save/Cancel behavior. |
| Detail workspace | Summary header plus tabs/panels for activity timeline, related quotations, supplier quotations, documents/attachments, and role-permitted actions. |

### 2.2 Patterns deliberately not copied

- Do not add Odoo activities, automatic follow-ups, email ingestion, marketing, chat, or external integrations: these are deferred or out of MVP scope.
- Do not introduce views unsupported by the relevant role or CRM module merely because Odoo offers them.
- Do not limit the CRM to Odoo's visual Kanban implementation. The CRM's documented deal-status model is authoritative; terminal stages can collapse and the board can horizontally scroll or use a stage selector on smaller screens.
- Never allow a visual drag-and-drop interaction to bypass API permission or status-transition validation.

## 3. Theme Architecture

### 3.1 User-selectable theme behavior

- The three themes are **Warm Editorial**, **Clean Monochrome**, and **Midnight Obsidian** (`D-73`).
- Theme selection is a per-user presentation preference, persisted in the user profile; before sign-in, use the product default **Warm Editorial**.
- Changing a theme updates color, border, shadow, and focus tokens only. It does not reset language, direction, filters, permissions, or data.
- The selected theme must apply before the main application shell paints, avoiding a visible flash of another theme.
- System language controls direction independently: Arabic = RTL, English = LTR.
- Every theme must meet WCAG 2.1 AA contrast for normal text and interactive controls.

### 3.2 Semantic token contract

Components consume semantic tokens only. Do not use theme hex values directly in components.

```css
--color-canvas; --color-surface; --color-surface-raised; --color-surface-muted;
--color-text; --color-text-muted; --color-text-inverse; --color-border; --color-border-strong;
--color-primary; --color-primary-hover; --color-primary-active; --color-primary-text;
--color-focus-ring; --color-link;
--color-success; --color-warning; --color-danger; --color-info;
--color-status-neutral; --shadow-1; --shadow-2;
```

### 3.3 Theme token values

| Token | Warm Editorial | Clean Monochrome | Midnight Obsidian |
|---|---:|---:|---:|
| `canvas` | `#FAF8F5` | `#F4F4F5` | `#0B0F19` |
| `surface` | `#FFFFFF` | `#FFFFFF` | `#111827` |
| `surface-raised` | `#FFFFFF` | `#FFFFFF` | `#1B2437` |
| `surface-muted` | `#F5F1EC` | `#FAFAFA` | `#0F1524` |
| `text` | `#292524` | `#18181B` | `#E0E7FF` |
| `text-muted` | `#57534E` | `#52525B` | `#AFBAD4` |
| `text-inverse` | `#FFFFFF` | `#FFFFFF` | `#0B0F19` |
| `border` | `#E7E5E4` | `#E4E4E7` | `#1F2937` |
| `border-strong` | `#78716C` | `#71717A` | `#6B7280` |
| `primary` | `#C2410C` | `#2563EB` | `#818CF8` |
| `primary-hover` | `#9A3412` | `#1D4ED8` | `#A5B4FC` |
| `primary-active` | `#7C2D12` | `#1E3A8A` | `#C7D2FE` |
| `primary-text` | `#FFFFFF` | `#FFFFFF` | `#0B0F19` |
| `focus-ring` | `#C2410C` | `#2563EB` | `#A5B4FC` |
| `link` | `#9A3412` | `#1D4ED8` | `#A5B4FC` |
| `success` | `#166534` | `#166534` | `#5DDB90` |
| `warning` | `#9A4700` | `#9A4700` | `#FFCA6A` |
| `danger` | `#B42318` | `#B42318` | `#FF9B91` |
| `info` | `#1D4ED8` | `#1D4ED8` | `#7CC4FF` |
| `status-neutral` | `#57534E` | `#52525B` | `#AFBAD4` |

`shadow-1` and `shadow-2` are elevation, not colour, so they are listed separately rather than
forced into the hex table. Roles are fixed by §4.2 and §6.6: `shadow-1` for resting cards and
menus, `shadow-2` for dialogs and popovers only.

| Token | Warm Editorial and Clean Monochrome | Midnight Obsidian |
|---|---|---|
| `shadow-1` | `0 1px 2px 0 rgb(0 0 0 / 0.06), 0 1px 3px 0 rgb(0 0 0 / 0.10)` | `0 1px 2px 0 rgb(0 0 0 / 0.40), 0 1px 3px 0 rgb(0 0 0 / 0.30)` |
| `shadow-2` | `0 8px 24px -4px rgb(0 0 0 / 0.14), 0 2px 8px -2px rgb(0 0 0 / 0.08)` | `0 8px 24px -4px rgb(0 0 0 / 0.55), 0 2px 8px -2px rgb(0 0 0 / 0.40)` |

The dark theme carries markedly higher alpha because a 6%-black shadow over `#0B0F19` is invisible.

> ✅ **The contrast gap recorded here under `D-70` is closed.** The retired Odoo palette paired
> `success` `#15803D` with `surface-muted` `#F1EEF0` at **4.35:1**, under the 4.5:1 §8 requires, and
> the pair was pinned in the automated test while it waited on a decision. `D-73` replaced both
> values: `success` is `#166534`, which measures **6.34:1** on Warm Editorial's `surface-muted` and
> **7.13:1** on its `surface`. No pair in the current palette sits below its threshold.

### 3.4 Theme intent

| Theme | Character | Use |
|---|---|---|
| Warm Editorial | Paper-warm, editorial calm, business-application density with a terracotta primary. | Default. Users who read and write long-form content — quotations, notes, report narratives — for most of the day. |
| Clean Monochrome | Neutral zinc, low chroma, cobalt reserved for action. | Users who prefer maximum visual simplicity, and screens where colour should mean status rather than decoration. |
| Midnight Obsidian | Near-black canvas with indigo highlights and high-contrast text. | Users who prefer a dark workspace or work for long periods in low light. |

## 4. Foundations

### 4.1 Typography

| Context | Arabic | English / numbers | Weight and use |
|---|---|---|---|
| UI sans | Noto Sans Arabic | Inter | 400 body; 500 labels; 600 headings/actions; 700 key totals only |
| Monospace | Noto Sans Mono | Noto Sans Mono | Codes, IDs, audit metadata only |

- Body base: `14px / 1.55`; compact table text: `13px / 1.45`.
- Page title: `24px / 1.3 / 600`; section title: `18px / 1.35 / 600`; card title: `16px / 1.4 / 600`.
- Use tabular numerals for money, counts, codes, and dates. Do not reverse numeric order in RTL.
- Never use color alone for a required state or status.

### 4.2 Spacing, shape, and elevation

| Token | Value | Primary use |
|---|---:|---|
| `space-1` | 4px | Icon gap, compact internal spacing |
| `space-2` | 8px | Field/control spacing |
| `space-3` | 12px | Card internals, table-cell padding |
| `space-4` | 16px | Standard component padding |
| `space-5` | 20px | Form groups |
| `space-6` | 24px | Section spacing |
| `space-8` | 32px | Major page sections |
| `space-10` | 40px | Large desktop separation |

- Radius: `6px` controls, `8px` cards, `12px` dialogs; do not use pill shapes except badges/chips.
- `shadow-1`: subtle resting card/menu elevation; `shadow-2`: dialog and popover only.
- Standard desktop content max width: `1600px`; application shell remains full width for data tables.

### 4.3 Responsive layout

| Breakpoint | Layout behavior |
|---|---|
| `< 640px` | Outdoor-first mobile; one content column; sidebar becomes a drawer; tables become cards or horizontally scrollable only when essential. |
| `640–1023px` | Two-column forms when fields are short; compact filter controls. |
| `≥ 1024px` | Persistent collapsible sidebar; multi-column forms; full tables and split views. |

## 5. Application Shell and Navigation

### 5.1 Shell structure

```text
LTR: [Sidebar] [Top context bar] [Page content]
RTL: [Page content] [Top context bar] [Sidebar]
```

- Sidebar width: `256px` expanded, `72px` collapsed. Use logical CSS properties, not left/right-only positioning.
- Group navigation by business module. Show a badge only for MVP counters: Requests, Approvals, Reports, and My Quotations.
- Do not show a module, action, count, or record link that the role is not permitted to access.
- The top context bar holds page title, optional breadcrumb, role-scoped search/filter controls, view toggle, language, theme preference, and user menu.

### 5.2 Views

| View | Use | Requirements |
|---|---|---|
| Kanban | Deals, quotations when status scanning is useful, and visits where appropriate. | Status columns, count, terminal folding, API-authorized movement only, accessible non-drag alternative. |
| Table/List | Customers, suppliers, reports, quotations, audit-heavy and high-volume records. | Pagination, server-side filters/sort/search, column priorities, empty/loading/error states. |
| Detail | A single customer, deal, quotation, supplier offer, report, or visit. | Summary first, related data/timeline second, action controls only by permission. |
| Form/Builder | Create/edit customer, deal, supplier offer, quotation, visit, report. | Clear sections, required markers, inline validation, calculated values read-only, unsaved-change warning. |
| Dashboard | Manager, Team Leader, CEO, Super Admin. | Role-specific metrics, time filter, accessible charts, no CEO drill-down. |

## 6. Component Specifications

### 6.1 Shared interaction requirements

- Every interactive element has visible keyboard focus using `focus-ring`.
- Buttons and inputs are reachable by keyboard; Enter activates primary form action only when safe; Escape closes dialogs/menus without discarding silently.
- Use logical start/end icons to mirror appropriately in RTL; do not mirror universally understood symbols such as plus, check, or alert.
- Loading controls retain their width and show a text alternative. Disabled controls explain why when the reason is not obvious.
- Display server validation near the affected field and preserve entered values on validation failure.

### 6.2 Buttons and actions

| Variant | Use | Prohibited use |
|---|---|---|
| Primary | One main permitted action in a context: Save, Submit for approval, Create. | Multiple competing primary actions in one section. |
| Secondary | Supporting actions: Preview, Add item, Refresh prices. | Destructive operations. |
| Ghost | Low-emphasis table/card actions. | Critical workflow confirmation. |
| Danger | Archive, deactivate, reject, or irreversible-equivalent action. | Normal Save, Send, or status progression. |

All button variants have default, hover, active, focus, disabled, and loading states.

### 6.3 Form controls

| Component | Standard |
|---|---|
| Text input | Label above, optional help text, explicit required marker, inline error, and max-length/count where applicable. |
| Select / combobox | Search only when the option volume needs it; show inactive/deactivated selection warnings when the CRM permits use. |
| Currency field | Keep amount and currency visibly paired; use decimal input rules; format only for display; calculated totals are read-only. |
| Date field | Locale-aware presentation, ISO-safe value, calendar keyboard support, and distinct working-day validation where relevant. |
| Smart term input | Free-text input with reusable suggestions; suggestions never force a structured payment schedule. |
| File upload | Shows allowed formats, configured size limit, upload/scan status, and parent-entity permission requirement. |

### 6.4 Status, badge, and alert system

| Type | Meaning | Presentation |
|---|---|---|
| Success | Approved, Accepted, Won, Delivery Complete. | Green icon + text/chip; never color alone. |
| Warning | Pending, stale, expired offer, deactivated selected item, self-approval. | Amber icon + label; self-approval uses a yellow badge. |
| Danger | Rejected, Lost, validation block, failed job. | Red icon + label; mandatory reason/action is clear. |
| Info | Draft, assigned, neutral workflow guidance. | Blue/neutral icon + label. |

Use the documented supplier rating colors (green, yellow, red, white) as supplier-rating chips only; do not repurpose them for generic statuses.

### 6.5 Tables and Kanban cards

- Tables use a sticky header on long lists, readable row height, column alignment by data type, and a clear row focus/selection state.
- Right-align monetary values and use tabular numerals; use logical alignment to preserve correct RTL reading order.
- Every list is server-paginated. Do not create a UI that requires loading all records.
- Kanban cards show only the fields needed to decide the next action. Full information belongs in Detail.
- All drag operations have keyboard-accessible Move action with an API-authorized target-stage list.

### 6.6 Dialogs, confirmations, and toasts

- Dialogs trap focus, label the purpose, support Escape where cancellation is safe, and return focus to the invoking control.
- Archive, deactivate, rejection, return, and approval actions show their consequence and collect mandatory reasons/notes before submission where required.
- Use inline validation for expected form mistakes. Use a toast only for concise completion/failure feedback; a toast must not be the only place an error is explained.

## 7. CRM-Specific Patterns

### 7.1 Deal pipeline

- Visualize only documented statuses: Lead, Contacted, Waiting Customer Request, Supplier RFQ, Supplier Quotation, Quotation Sent, Negotiations, Won, Purchasing, Delivery, Delivery Complete, and Lost.
- Keep terminal status columns visually distinct and collapsible. A user can expand them without losing data.
- Every card must expose a non-drag action route for status change. The server decides authorization and allowed transition.
- Show stale-deal status and last activity where the role can view the deal.

### 7.2 Quotation builder and approval

- Keep supplier selection, internal cost, margin, customer price, discount, tax, additional items, and final total in distinct visual groups.
- Mark backend-calculated values and prevent direct editing where the documented business rule requires calculation.
- Emphasize warning vs block: missing supplier price blocks save; quantity above recorded availability shows a red inline warning without blocking.
- Supplier price changes in Draft/Pending and deactivated items in open quotations show the documented warning plus permitted action.
- Customer PDF preview and output must exclude supplier names, supplier prices, costs, and margins unconditionally.
- A self-approved quotation displays the documented yellow badge in every relevant approval/report view.

### 7.3 Outdoor visit form

- Mobile-first; the only mandatory fields are company name, contact person, and outcome.
- Use simple controls for yes/no, checkboxes, and dropdowns. Keep optional free-text intentional.
- Show a specific connection-unavailable state and preserve the form draft during a brief connection drop.
- Outcomes use clear text plus icon: Rejected requires a reason, Open retains a follow-up date, Successful shows the handover state.

## 8. Accessibility and Quality Gates

Before a component or screen is complete, verify:

- WCAG 2.1 AA contrast for text and controls in all three themes.
- Full keyboard path, visible focus, and no keyboard trap outside dialogs.
- Semantic headings, labels, error association, announced status changes, and meaningful icon labels.
- RTL visual order, cursor behavior, punctuation, tables, numerals, charts, and logical start/end positioning.
- Touch targets of at least `44 × 44px` for primary mobile actions.
- Loading, empty, error, disabled, and permission-denied states.
- No component hard-codes a color, radius, spacing, shadow, or direction that bypasses tokens.

## 9. Implementation Acceptance Criteria

1. Given any authenticated user, when they choose one of the three themes, then the preference persists for their account and applies on the next session.
2. Given Arabic or English, when the user changes theme, then language and direction stay unchanged.
3. Given a role-restricted action, when the user sees a theme variant, then the action remains hidden or disabled only as a visual complement to API enforcement.
4. Given a deal Kanban board, when a user cannot drag or uses a keyboard, then they can use an accessible status-change action subject to the same API validation.
5. Given any component in any theme, when it enters focus, error, loading, or disabled state, then the state is visible without relying on color alone.
6. Given a customer-facing quotation PDF, when it is generated from any theme, then the PDF content remains theme-independent and excludes all supplier/cost/margin information.

---

## 10. Decisions recorded here

`D-70` is recorded in `docs/CRM_Documentation_EN.md` §2 with the rest of the decision log. The
entry below is recorded in this document because it changes only this document, and because the
master log is write-protected against unattended edits — **the `D-73` row still owes an entry
there**, and that is outstanding rather than done.

### D-73 — The three themes are replaced (recorded 2026-08-22, approved by the owner)

**What changed.** §3.1's three named themes — Odoo-inspired, Clean White, Dark Blue — are retired
and replaced by **Warm Editorial**, **Clean Monochrome** and **Midnight Obsidian**. §3.3's value
table is rewritten, §3.4's intent table follows, and Warm Editorial takes the product-default slot
on `:root`. The count stays at three, so §1, §2 ("One system, three appearances") and §9's first
acceptance criterion are unaffected.

**Why the default is Warm Editorial.** The retired default was warm and light; so is this one. The
mapping across the whole set is by character rather than by position — Odoo-inspired → Warm
Editorial, Clean White → Clean Monochrome, Dark Blue → Midnight Obsidian — so a user's existing
preference has an obvious successor. Nothing in §3 requires this particular default; it is one
line in `tokens.css` and one in `theme.ts` if the owner prefers another.

**A twenty-second token: `--color-border-strong`.** The brief asked for 3:1 on every border.
Measured, the three specified divider colours are `#E7E5E4`, `#E4E4E7` and `#1F2937`, which sit at
**1.26:1**, **1.27:1** and **1.21:1** against their own surfaces. Raising them to 3:1 turns every
rule in a data-dense table into a cage, and WCAG 2.1 SC 1.4.11 does not ask for it — its 3:1 floor
covers the boundary that *identifies* a control, not decorative separation. One token cannot serve
both roles, so there are two: `border` stays a divider with no ratio floor, and `border-strong`
carries input outlines, checkbox edges and control boundaries at ≥3:1. Only the second is asserted.

**Two primaries were adjusted, and one of the two adjustments had to be inverted.**
- `#EA580C` (Terracotta) carries white at **3.56:1** and its own text colour at **4.26:1** — no
  label reaches AA on it in either direction. Replaced with `#C2410C`, which carries white at
  **5.18:1**.
- `#6366F1` (Electric Iris) carries white at **4.47:1**, missing 4.5 by 0.03, and dark text at
  **4.29:1**. The agreed remedy was to darken it; on a dark canvas that is the wrong direction, and
  measurement said so — `#4F46E5` reads **2.82:1** on `surface`, **2.47:1** on `surface-raised` and
  **2.90:1** on `surface-muted`, all under SC 1.4.11's 3:1. A dark theme needs a *lighter* primary
  carrying dark text, which is the shape the retired Dark Blue theme used. **`#818CF8` with
  `primary-text` `#0B0F19`**: 5.95:1 against the surface, 6.42:1 for the label.

**A gap closed rather than inherited.** `success` `#15803D` measured 4.35:1 on the retired Odoo
`surface-muted` and had been pinned in the contrast test awaiting a decision (see `D-70`).
`#166534` reads 6.34:1 on Warm Editorial's muted surface, so the pinned-exception list is now
empty. The pinning mechanism is kept — a known gap should keep a floor at its measured value — but
there is nothing in it.

**What was not decided here.** The remaining sixteen tokens per theme were not specified by the
brief and were derived to satisfy §8's contrast requirements; they are ordinary design values and
an owner may restate any of them. Nothing about spacing, typography, direction or component
behaviour changes: §3.1's rule that a theme moves colour, border, shadow and focus only still
holds.

### Debt owed to the token set (recorded 2026-08-23, during the shell elevation)

Two things the shell needs and §3.2 does not have. Both were found by looking at rendered pixels,
not by any check in this repository, and neither is applied — a token change is an owner's
decision, not an implementation detail.

- **No overlay colour.** A scrim over the mobile drawer has to darken in every theme, and no token
  behaves: `--color-text` is near-black in the two light themes and near-white in Midnight
  Obsidian, so tinting with it lays a *light* veil over dark content — measured as `#E0E7FF` at
  40% opacity. `--color-canvas` fails the same way in the opposite direction. `AppSidebar.vue`
  therefore dims with `backdrop-filter: brightness()`, which takes what is actually behind the
  element and needs no colour at all. A **23rd token, `--color-overlay`**, dark in all three
  themes, is the better answer and would make the scrim declarative again.
- **No `color-scheme` declaration.** The guideline is standard and correct: without
  `color-scheme: dark` on the root, native scrollbars, `<select>` menus, date pickers and form
  controls keep painting light while Midnight Obsidian is active. It belongs beside the theme
  blocks in `tokens.css`, which `ThemeTokenTest` pins and `D-73` documents as carrying exactly
  22 tokens, so adding it is an amendment to that record rather than a component change.

**One accessibility defect was fixed rather than deferred.** The sidebar's collapse control
rendered a visible label *and* an `sr-only` copy of the same string, so its computed accessible
name was `"Collapse sidebar Collapse sidebar"` — read out of the browser's accessibility tree, and
invisible to every text-scanning check here. The hidden label now exists only when the collapsed
rail has no visible one.
