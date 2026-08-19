# P-01 — Arabic PDF Prototype

Retires risk **R-02** (Arabic glyph shaping), the highest technical risk in
`docs/CRM_Documentation_EN.md` §19.2. Required by `docs/MVP_Build_Plan_EN.md` §0.1 before
any feature module begins.

## Result: PASS

| Criterion (§0.1) | Result |
|---|---|
| Full Arabic paragraph, correctly shaped | ✅ connected forms, lam-alef ligatures, hamza variants, taa marbuta, shadda/tanween |
| Items table | ✅ RTL column order, Arabic + English descriptions |
| Numbers | ✅ not reversed in RTL; Latin and Arabic-Indic both correct; tabular numerals |
| Embedded fonts | ✅ 4 faces embedded, zero OS fallback |
| No broken glyphs | ✅ verified visually against the rendered page |

## Run

```bash
npm install
node render.js
```

Outputs to `out/`, per locale: `QT-2026-0001.{ar,en}.pdf`, `preview.{ar,en}.png`,
`quotation.{ar,en}.html`. The script fails the build if either locale renders
more than one page.

## House style

Visual language taken from the company's Purchase Order #226. The logo and
footer band are the real assets, extracted from that PDF. Brand colours sampled
from it directly: `#5B9BD5` headers, `#DEEAF6` row tint, `#4472C4` title rules,
`#112131` footer band.

Arabic and English render from one template (`template.js`) with no duplicated
markup and no hard-coded user-facing strings, per the Module 0 requirement.

## Two deliberate departures from the reference

1. **"UNIT COST" is "UNIT PRICE" here.** The reference is a purchase order to a
   supplier, where cost is correct. This is a customer-facing quotation, and
   3.12 rule 2 forbids exposing cost or margin to a customer.
2. **Discount is applied before VAT**, per the documented chain in 5.2 and now
   confirmed as `D-64`. The reference purchase order applies it *after* VAT;
   that `10.32` difference is recorded as accepted in `D-64`, so PO #226 is no
   longer a reconciliation target for tax ordering.

## Why headless Chrome

Chrome's text engine performs Arabic shaping and bidi natively, which is where
naive PDF libraries fail. Laravel's Browsershot is a thin wrapper around this
same engine, so this result transfers directly to Module 9 — only the wrapper
changes, not the rendering.

## Two decisions worth keeping

1. **Fonts are embedded as base64, never referenced from the OS.** The
   on-premise Linux server has no Arabic system fonts. A template relying on
   system fonts passes on macOS and fails in production.
2. **Inter is embedded for Latin/digits** (`docs/Design_System_EN.md` §4.1). Without
   it, `sans-serif` resolves to Helvetica on macOS and DejaVu/Liberation on
   Ubuntu — same template, different metrics in dev vs production. Embedding it
   cut the PDF from 11 font faces to 4.

## Carried forward to Module 9

- Page numbering is hard-coded `صفحة 1 من 1`. Real quotations have variable item
  counts; use Chrome's `headerTemplate`/`footerTemplate` for live page numbers.
- Content ran 3mm over A4 and silently produced a blank second page. Spacing now
  leaves slack, but any template change must re-check page count.
- This is a layout prototype. It carries no supplier names, costs, or margins,
  matching §3.12 rule 2 — the production template must be fed by a customer-view
  model that structurally cannot contain those fields.
- OD-02 (final PDF template) is still open; this is a working baseline, not the
  approved design.
