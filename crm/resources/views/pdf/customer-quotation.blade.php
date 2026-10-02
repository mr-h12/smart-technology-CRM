{{--
    F-34 · 1.2 — the customer quotation, laid out as `D-100` approved: the
    Conta-style quote the owner chose on 2026-10-02, every word black, the
    column-title row light grey and the table lines a little darker. What is
    printed and when a line is omitted are still `D-89`'s; the totals are §5's.

    Every word comes from `lang/{ar,en}/pdf.php` through `$t`, which is bound to
    one locale for the whole render — the document's language is the customer's,
    not whoever pressed the button. The percentages are placeholders, never part
    of a label (the defect found against P-01 on 2026-09-13).

    Nothing here is fetched: the faces and the logo arrive as `data:` URIs from
    `PdfAssetsInterface` (Point 2.2), because the renderer runs on the `pdf`
    queue with no reason to reach the web. The company's name, address and
    phones are text from Settings (§14.6); `D-100` removed the footer band that
    carried them as pixels, and the faded watermark with it.

    The logo carries `alt=""` on purpose: it is decorative here — a PDF has no
    assistive text layer, and the localisation guard is right that an `alt`
    with words in it is user-facing text.

    Every optional field is *absent* rather than blank — the model refuses a
    present-but-empty string (Point 1.1), so `@if` here means "the fact is
    unknown", and a heading over nothing cannot be printed.

    One stylesheet serves both directions: `start`/`end` and the flex rows follow
    `dir`, so the Arabic page is the English page's mirror (`D-100`).
--}}
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ $t('title', ['code' => $view->code]) }}</title>
    <style>
        {!! $fontFaceCss !!}

        /* The top and bottom margins live in the renderer (Point 2.5): Chrome
           draws the page number inside the bottom one. The side margins are
           padding here: Chrome clips at the renderer's margins, and a full-width
           table's collapsed border spills half outside its own box. */
        @page { size: A4; }

        body {
            margin: 0;
            padding: 0 25mm;
            font-family: '{{ $direction === 'rtl' ? 'CRM Sans Arabic' : 'CRM Sans' }}', 'CRM Sans';
            font-size: 9pt;
            line-height: 1.4;
            color: #000;
        }

        .label { font-weight: 700; text-transform: uppercase; }

        header { display: flex; align-items: flex-start; justify-content: space-between; gap: 10mm; }
        .logo { width: 50mm; }
        .company { text-align: end; }
        .company h1 { margin: 0 0 4mm; font-size: 14pt; font-weight: 700; text-transform: uppercase; }
        hr { border: 0; border-top: 0.75pt solid #f1f1f1; margin: 6mm 0 7mm; }

        .parties { display: flex; justify-content: space-between; gap: 10mm; }
        .parties .meta { text-align: end; }
        .parties .meta > div + div { margin-top: 4mm; }

        .intro { margin: 10mm 0 3mm; }

        table { border-collapse: collapse; }
        table.items { width: 100%; }
        table.items th, table.items td { border: 0.75pt solid #d9d9d9; padding: 2.4mm 2.2mm; vertical-align: top; }
        table.items th { background: #f2f2f2; font-weight: 700; text-align: start; padding-top: 4.5mm; padding-bottom: 4.5mm; }
        table.items .serial { width: 1%; }
        table.items .description { width: 46%; }
        table.items .money { text-align: end; white-space: nowrap; }
        table.items .num { text-align: center; font-weight: 700; }
        /* A row that splits across a page break is the defect D-79 carried
           forward as the one-page guard; the table may run on, a row may not. */
        table.items tr { break-inside: avoid; page-break-inside: avoid; }
        table.items thead { display: table-header-group; }

        .below { display: flex; justify-content: space-between; align-items: flex-end; gap: 8mm; margin-top: 7mm; }
        .terms p { margin: 0; }
        table.totals { flex-shrink: 0; }
        table.totals td { padding: 1.6mm 0; padding-inline-start: 8mm; text-align: end; font-size: 7.5pt; text-transform: uppercase; white-space: nowrap; }
        table.totals tr.final td { padding-top: 4mm; font-size: 10pt; font-weight: 700; }
        table.totals tr.final td.money { font-size: 12pt; }

        .closing { margin-top: 9mm; }
        .closing .regards { margin-top: 4mm; }
        .closing .signatory { margin-top: 1mm; font-weight: 700; }
    </style>
</head>
<body>

<header>
    <img class="logo" src="{{ $logo }}" alt="">
    <div class="company">
        <h1>{{ $t('heading') }}</h1>
        <div class="label">{{ $view->companyName }}</div>
        @if ($view->companyAddress !== null)
            <div id="company-address">{{ $isolate($view->companyAddress) }}</div>
        @endif
        @if ($view->companyPhones !== null)
            <div id="company-phones">{{ $isolate($view->companyPhones) }}</div>
        @endif
    </div>
</header>

<hr>

<div class="parties">
    <div>
        <div class="label">{{ $t('parties.prepared_for') }}</div>
        <div>{{ $view->customerName }}</div>
        @if ($view->customerContact !== null)
            <div id="contact-line">{{ $t('header.attention') }}: {{ $view->customerContact }}</div>
        @endif
    </div>
    <div class="meta">
        @if ($view->subject !== null)
            <div id="subject-line"><div class="label">{{ $t('header.subject') }}</div>{{ $view->subject }}</div>
        @endif
        @if ($view->quotationDate !== null)
            <div id="issue-date"><div class="label">{{ $t('parties.issue_date') }}</div>{{ $ltr($view->quotationDate) }}</div>
        @endif
        @if ($view->validUntil !== null)
            <div id="valid-until-line"><div class="label">{{ $t('parties.valid_until') }}</div>{{ $ltr($view->validUntil) }}</div>
        @endif
    </div>
</div>

<p class="intro">{{ $t('intro') }}</p>

<table class="items">
    <thead>
    <tr>
        <th class="serial">{{ $t('table.serial') }}</th>
        <th class="description">{{ $t('table.item') }}</th>
        <th class="money">{{ $t('table.unit_price') }}</th>
        <th class="num">{{ $t('table.quantity') }}</th>
        <th class="money">{{ $t('table.line_total') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($view->lines as $line)
        <tr>
            <td class="serial">{{ $line->lineNo }}</td>
            <td>{{ $line->description }}</td>
            <td class="money">{{ $money($line->unitPrice) }}</td>
            <td class="num">{{ $plain($line->quantity) }}</td>
            <td class="money">{{ $money($line->lineTotal) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="below">
    <div class="terms">
        <div class="label">{{ $t('terms.heading') }}</div>
        <p>{{ $t('conditions.currency', ['currency' => $view->currencyCode]) }}</p>
        @if ($view->paymentTerms !== null)
            <p id="payment-terms">{{ $t('conditions.payment', ['terms' => $view->paymentTerms]) }}</p>
        @endif
        @if ($view->warranty !== null)
            <p id="warranty">{{ $t('conditions.warranty', ['terms' => $view->warranty]) }}</p>
        @endif
        @if ($view->deliveryTerms !== null)
            <p id="delivery-terms">{{ $t('conditions.delivery', ['terms' => $view->deliveryTerms]) }}</p>
        @endif
        @if ($view->validUntil !== null)
            <p id="validity">{{ $t('conditions.validity', ['date' => $ltr($view->validUntil)]) }}</p>
        @endif
    </div>

    <table class="totals">
        <tr>
            <td>{{ $t('totals.subtotal') }}</td>
            <td class="money">{{ $money($view->subtotal) }}</td>
        </tr>
        @foreach ($view->additionalItems as $additional)
            <tr>
                <td>{{ $additional->description }}</td>
                <td class="money">{{ $money($additional->amount) }}</td>
            </tr>
        @endforeach
        <tr>
            <td>{{ $t('totals.discount', ['percent' => $plain($view->discountPercent)]) }}</td>
            <td class="money">{{ $money($view->discountAmount) }}</td>
        </tr>
        {{-- D-63: an exempt quotation renders no tax line at all, not a zero one. --}}
        @if ($view->taxPercent !== null && $view->taxAmount !== null)
            <tr id="tax-row">
                <td>{{ $t('totals.tax', ['percent' => $plain($view->taxPercent)]) }}</td>
                <td class="money">{{ $money($view->taxAmount) }}</td>
            </tr>
        @endif
        {{-- D-99: keyed on the printed figure — the stored `0.000000` of D-65's "rounding off" is no row. --}}
        @if ($money($view->roundingDiff) !== '0.00')
            <tr id="rounding-row">
                <td>{{ $t('totals.rounding') }}</td>
                <td class="money">{{ $money($view->roundingDiff) }}</td>
            </tr>
        @endif
        <tr class="final">
            <td>{{ $t('totals.final') }}</td>
            <td class="money">{{ $money($view->finalTotal) }} {{ $view->currencyCode }}</td>
        </tr>
    </table>
</div>

<div class="closing">
    <div>{{ $t('closing') }}</div>
    <div class="regards">{{ $t('regards') }}</div>
    @if ($view->signatoryName !== null)
        <div id="signatory" class="signatory">{{ $view->signatoryName }}</div>
    @endif
</div>
</body>
</html>
