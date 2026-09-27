{{--
    Module 9, Point 2.4 — the customer quotation, laid out as `D-89` approved:
    the company's own offer form, with the full totals block §5 computes.

    Every word comes from `lang/{ar,en}/pdf.php` through `$t`, which is bound to
    one locale for the whole render — the document's language is the customer's,
    not whoever pressed the button. The percentages are placeholders, never part
    of a label (the defect found against P-01 on 2026-09-13).

    Nothing here is fetched: the faces and the letterhead arrive as `data:` URIs
    from `PdfAssetsInterface` (Point 2.2), because the renderer runs on the `pdf`
    queue with no reason to reach the web.

    The three images carry `alt=""` on purpose: they are decorative here — a
    PDF has no assistive text layer, and the localisation guard is right that an
    `alt` with words in it is user-facing text. ⚠️ The consequence is recorded on
    the debt register: the footer band's address, phones and e-mails are pixels
    baked into the letterhead, so `§14.6`'s "company details from settings" is
    met for the name and the header only.

    Every optional field is *absent* rather than blank — the model refuses a
    present-but-empty string (Point 1.1), so `@if` here means "the fact is
    unknown", and a heading over nothing cannot be printed.
--}}
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ $t('title', ['code' => $view->code]) }}</title>
    <style>
        {!! $fontFaceCss !!}

        /* The margins live in the renderer (Point 2.5): Chrome draws the page
           number inside the bottom margin, so one place decides both. */
        @page { size: A4; }

        body {
            margin: 0;
            font-family: '{{ $direction === 'rtl' ? 'CRM Sans Arabic' : 'CRM Sans' }}', 'CRM Sans';
            font-size: 10.5pt;
            color: #1b1b1f;
        }

        /* The faded S.T.I.S mark the letterhead carries behind the page. */
        .watermark {
            position: fixed;
            top: 28mm; left: 50%; transform: translateX(-50%);
            width: 120mm; opacity: 0.06; z-index: -1;
        }

        header { display: flex; align-items: flex-start; justify-content: space-between; gap: 10mm; }
        .logo { width: 78mm; }
        .meta { font-size: 10pt; line-height: 1.6; white-space: nowrap; }
        .meta div { text-align: {{ $direction === 'rtl' ? 'left' : 'right' }}; }
        .meta span { font-weight: 700; }

        .intro { margin: 6mm 0 3mm; }

        table.items { width: 100%; border-collapse: collapse; }
        table.items th, table.items td { border: 0.6pt solid #2f2f38; padding: 1.8mm 2.2mm; }
        table.items thead th { background: #4472c4; color: #fff; font-weight: 700; text-align: center; }
        table.items tbody td { vertical-align: top; }
        table.items td.num { text-align: center; white-space: nowrap; }
        table.items td.money { text-align: {{ $direction === 'rtl' ? 'left' : 'right' }}; white-space: nowrap; }
        /* A row that splits across a page break is the defect D-79 carried
           forward as the one-page guard; the table may run on, a row may not. */
        table.items tr { break-inside: avoid; page-break-inside: avoid; }
        table.items thead { display: table-header-group; }

        table.totals { margin-top: 4mm; border-collapse: collapse; width: 72mm; margin-{{ $direction === 'rtl' ? 'right' : 'left' }}: auto; }
        table.totals td { padding: 1.2mm 2.2mm; }
        table.totals td.money { text-align: {{ $direction === 'rtl' ? 'left' : 'right' }}; white-space: nowrap; }
        table.totals tr.final td { border-top: 0.8pt solid #2f2f38; font-weight: 700; }

        ul.conditions { margin: 6mm 0 0; padding-{{ $direction === 'rtl' ? 'right' : 'left' }}: 6mm; }
        ul.conditions li { margin-bottom: 1.2mm; }

        .closing { margin-top: 8mm; }
        .signature { margin-top: 6mm; line-height: 1.7; }
        .signature .name { font-weight: 700; }

        .band { position: fixed; bottom: 0; left: 0; width: 100%; }
    </style>
</head>
<body>
<img class="watermark" src="{{ $watermark }}" alt="">

<header>
    <img class="logo" src="{{ $logo }}" alt="">
    <div class="meta">
        <div><span>{{ $t('header.date') }}:</span> {{ $ltr($view->quotationDate) }}</div>
        <div><span>{{ $t('header.to') }}:</span> {{ $view->customerName }}</div>
        @if ($view->customerContact !== null)
            <div id="contact-line"><span>{{ $t('header.attention') }}:</span> {{ $view->customerContact }}</div>
        @endif
        @if ($view->subject !== null)
            <div id="subject-line"><span>{{ $t('header.subject') }}:</span> {{ $view->subject }}</div>
        @endif
    </div>
</header>

<p class="intro">{{ $t('intro') }}</p>

<table class="items">
    <thead>
    <tr>
        <th>{{ $t('table.serial') }}</th>
        <th>{{ $t('table.item') }}</th>
        <th>{{ $t('table.unit_price') }}</th>
        <th>{{ $t('table.quantity') }}</th>
        <th>{{ $t('table.line_total') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($view->lines as $line)
        <tr>
            <td class="num">{{ $line->lineNo }}</td>
            <td>{{ $line->description }}</td>
            <td class="money">{{ $line->unitPrice }}</td>
            <td class="num">{{ $line->quantity }}</td>
            <td class="money">{{ $line->lineTotal }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td>{{ $t('totals.subtotal') }}</td>
        <td class="money">{{ $view->subtotal }}</td>
    </tr>
    @foreach ($view->additionalItems as $additional)
        <tr>
            <td>{{ $additional->description }}</td>
            <td class="money">{{ $additional->amount }}</td>
        </tr>
    @endforeach
    <tr>
        <td>{{ $t('totals.discount', ['percent' => $view->discountPercent]) }}</td>
        <td class="money">{{ $view->discountAmount }}</td>
    </tr>
    {{-- D-63: an exempt quotation renders no tax line at all, not a zero one. --}}
    @if ($view->taxPercent !== null && $view->taxAmount !== null)
        <tr id="tax-row">
            <td>{{ $t('totals.tax', ['percent' => $view->taxPercent]) }}</td>
            <td class="money">{{ $view->taxAmount }}</td>
        </tr>
    @endif
    @if ($view->roundingDiff !== '0.00')
        <tr id="rounding-row">
            <td>{{ $t('totals.rounding') }}</td>
            <td class="money">{{ $view->roundingDiff }}</td>
        </tr>
    @endif
    <tr class="final">
        <td>{{ $t('totals.final') }}</td>
        <td class="money">{{ $view->finalTotal }} {{ $view->currencyCode }}</td>
    </tr>
</table>

<ul class="conditions">
    <li>{{ $t('conditions.currency', ['currency' => $view->currencyCode]) }}</li>
    @if ($view->paymentTerms !== null)
        <li id="payment-terms">{{ $t('conditions.payment', ['terms' => $view->paymentTerms]) }}</li>
    @endif
    @if ($view->warranty !== null)
        <li id="warranty">{{ $t('conditions.warranty', ['terms' => $view->warranty]) }}</li>
    @endif
    @if ($view->deliveryTerms !== null)
        <li id="delivery-terms">{{ $t('conditions.delivery', ['terms' => $view->deliveryTerms]) }}</li>
    @endif
    @if ($view->validUntil !== null)
        <li id="validity">{{ $t('conditions.validity', ['date' => $ltr($view->validUntil)]) }}</li>
    @endif
</ul>

<p class="closing">{{ $t('closing') }}</p>

<div class="signature">
    <div>{{ $t('regards') }}</div>
    @if ($view->signatoryName !== null)
        <div id="signatory" class="name">{{ $view->signatoryName }}</div>
    @endif
</div>

<img class="band" src="{{ $footerBand }}" alt="">
</body>
</html>
