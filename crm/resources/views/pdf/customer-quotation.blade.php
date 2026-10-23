{{--
    F-40 · 1.5 — the customer quotation as `D-107`'s *Commercial Offer*. Every
    size, space and position is the reference HTML's CSS
    (`prototypes/quotation-client-layout/QT-2026-0003-commercial-offer-{en,ar}.html`),
    copied, not estimated; where the Arabic reference differs, `$rtl` picks its
    value. The owner's departures are named where they sit: the English page
    one point smaller and the regards line level with the signature label
    (F-40 · 1.6d), and the compact spacing (1.6c). What is printed and when a line is omitted are `D-89`'s and
    `D-107`'s; the figures are §5's and F-40 · 1.4's.

    Every word comes from `lang/{ar,en}/pdf.php` through `$t`, which is bound to
    one locale for the whole render — the document's language is the customer's,
    not whoever pressed the button. No label prints a percentage (`D-107`,
    `D-118`), and none is ever written into one (the defect found against P-01
    on 2026-09-13).

    Nothing here is fetched: the faces and the logo arrive as `data:` URIs from
    `PdfAssetsInterface` (Point 2.2), because the renderer runs on the `pdf`
    queue with no reason to reach the web. The company's details are text from
    Settings (§14.6), printed by Chrome's footer on every page
    (`CustomerQuotationHtml::footer()`, F-40 · 1.6), not by this page.

    The logo carries `alt=""` on purpose: it is decorative here — a PDF has no
    assistive text layer, and the localisation guard is right that an `alt`
    with words in it is user-facing text.

    Every optional field is *absent* rather than blank — the model refuses a
    present-but-empty string (Point 1.1), so `@if` here means "the fact is
    unknown", and a heading over nothing cannot be printed.
--}}
{{-- D-63 / D-107: an exempt quotation prints no VAT column, row or term. --}}
@php($rtl = $direction === 'rtl')
{{-- QS.2a: keyed on the VAT figure — the header rate is null on a tax-included quotation (D-165). --}}
@php($taxed = $view->taxAmount !== null)
{{-- D-168 (owner, 2026-10-23): the VAT column whenever there is tax — a tax-included page prints net prices with it and the note under the table. --}}
@php($vatColumn = $taxed)
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ $t('title', ['code' => $view->code]) }}</title>
    <style>
        {!! $fontFaceCss !!}

        /* F-40 · 1.6d (the owner, 2026-10-11): the English page is one point
           smaller than the reference, to read the size of the Arabic; the
           regards line sits level with the signature label (16 + 1.5 mm). */

        /* The top and bottom margins live in the renderer (Point 2.5): Chrome
           draws the footer inside the bottom one, and its top one is a later
           page's 41.7 mm (D-107). Page one begins 8 mm down — measured: Chrome
           takes this over the renderer's margin. The side margins are padding
           here: Chrome clips at the renderer's margins, and a full-width
           table's collapsed border spills half outside its own box. */
        @page { size: A4; }
        @page :first { margin-top: 8mm; }
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0 22mm;
            font-family: '{{ $rtl ? 'CRM Sans Arabic' : 'CRM Sans' }}', '{{ $rtl ? 'CRM Sans' : 'CRM Sans Arabic' }}';
            font-size: {{ $rtl ? '11.5pt' : '10pt' }};
            line-height: 1.45;
            color: #000;
        }

        .h { font-weight: 700; font-size: {{ $rtl ? '12.5pt' : '11.5pt' }}; }

        .logo { width: 75mm; display: block; margin-left: -1.58mm; {{ $rtl ? 'margin-right: auto;' : '' }} }
        .title { text-align: center; margin-top: 2.14mm; font-size: {{ $rtl ? '14pt' : '13pt' }}; font-weight: 700; }

        .info { margin-top: 8mm; }
        .info > div + div { margin-top: 1.5mm; }

        .intro { margin: 12mm 0 4mm; }

        table { border-collapse: collapse; }
        .items { width: 100%; }
        .items th, .items td { border: 0.75pt solid #bfbfbf; padding: {{ $rtl ? '2.4mm 1.4mm' : '2.4mm 2mm' }}; text-align: center; vertical-align: middle; }
        .items th { background: #f2f2f2; white-space: nowrap; }
        .items .desc { width: 52%; }
        .items td.desc { text-align: start; }
        /* A row that splits across a page break is the defect D-79 carried
           forward; the table may run on, a row may not, and its header repeats. */
        .items tr { break-inside: avoid; page-break-inside: avoid; }
        .items thead { display: table-header-group; }
        .items-next { break-before: page; }

        .note { margin-top: 2mm; }

        .totals { margin: {{ $rtl ? '5mm auto 0 0' : '5mm 0 0 auto' }}; break-inside: avoid; }
        .totals td { padding: {{ $rtl ? '1.2mm 10mm 1.2mm 0' : '1.2mm 0 1.2mm 10mm' }}; text-align: end; white-space: nowrap; font-size: {{ $rtl ? '10.5pt' : '9.5pt' }}; }
        .totals td.h { font-size: {{ $rtl ? '11pt' : '10pt' }}; }
        .totals tr.final td { border-top: 0.75pt solid #000; padding-top: 2.4mm; font-weight: 700; }

        .terms { margin-top: 6mm; }
        .terms ol { margin: 3mm 0 0; {{ $rtl ? 'padding-right: 6mm;' : 'padding-left: 6mm;' }} }

        .contact { margin-top: {{ $rtl ? '10.9mm' : '10.6mm' }}; }
        .closing { break-inside: avoid; display: flex; justify-content: space-between; align-items: flex-start; margin: {{ $rtl ? '-2.9mm 0 0.5mm' : '-2.6mm 0 3.7mm' }}; }
        .closing > div:first-child { padding-top: 17.5mm; }
        .closing .name { margin-top: 1mm; }
        .closing .name, .closing .role { {{ $rtl ? 'margin-right: 1.27mm' : 'margin-left: 1.4mm' }}; }
        .sign { width: 60mm; text-align: center; }
        .sign .line { border-bottom: 0.75pt solid #000; height: 16mm; }
        .sign .h { margin-top: 1.5mm; }

        /* D-107's longer offer (QT-2026-0006-commercial-offer-en.html), the
           same in both languages (the owner, 2026-10-11). */
        .multi-page .contact { margin-top: 21mm; }
        .multi-page .closing { margin: 3mm 0 3.7mm; }

        /* F-40 · 1.6c (the owner, 2026-10-11): a short offer that overflows by
           its ending is tightened to one page; the signature line stays 16 mm
           and the regards line stays level with it (the owner, 2026-10-11). */
        .compact .info { margin-top: 4mm; }
        .compact .intro { margin: 6mm 0 2mm; }
        .compact .totals { margin-top: 3mm; }
        .compact .terms { margin-top: 3mm; }
        .compact .contact { margin-top: 5mm; }
    </style>
</head>
@if ($multiPage)
<body class="multi-page">
@elseif ($compact)
<body class="compact">
@else
<body>
@endif

<img class="logo" src="{{ $logo }}" alt="">

<div class="title">{{ $t('heading') }}</div>

<div class="info">
    <div><span class="h">{{ $t('info.offer_no') }}</span> {{ $ltr($view->code) }}</div>
    @if ($view->quotationDate !== null)
        <div id="issue-date"><span class="h">{{ $t('info.issue_date') }}</span> {{ $ltr($view->quotationDate) }}</div>
    @endif
    <div><span class="h">{{ $t('info.prepared_for') }}</span> {{ $view->customerName }}</div>
    @if ($view->customerContact !== null)
        <div id="contact-line"><span class="h">{{ $t('info.attention') }}</span>
            {{ $view->customerContactTitle === null ? $view->customerContact : $t('info.attention_name', ['title' => $view->customerContactTitle, 'name' => $view->customerContact]) }}
        </div>
    @endif
    @if ($view->subject !== null)
        <div id="subject-line"><span class="h">{{ $t('info.subject') }}</span> {{ $view->subject }}</div>
    @endif
</div>

<p class="intro">{{ $t('intro') }}</p>

{{-- Each row's cells: description, unit, quantity, unit price, VAT (null when exempt), total (D-142: the stored line amount).
     D-107: an additional item is a row after the lines — no unit, one of it, untaxed (D-62). --}}
@php($lineCells = fn ($line) => [$line->description, ($rtl ? $line->unitAr : $line->unitEn) ?? '—', $plain($line->quantity), $money($line->unitPrice), $vatColumn ? $money($line->vatAmount) : null, $money($line->lineTotal)])
@php($additionalCells = fn ($additional) => [$additional->description, '—', '1', $money($additional->amount), $vatColumn ? $money('0') : null, $money($additional->amount)])
@php($rows = [...array_map($lineCells, $view->lines), ...array_map($additionalCells, $view->additionalItems)])
{{-- F-40 · 1.6b (the owner, 2026-10-11): six rows on page one, at most eight on each later page, each page its own table.
     1.6c: page one may keep fewer, and when it keeps none it prints no empty table. --}}
@php($later = array_slice($rows, $firstPageRows))
@php($pages = [array_slice($rows, 0, $firstPageRows), ...array_chunk($later, 8)])
@foreach ($pages as $page)
@continue($page === [] && ! $loop->last)
<table class="items{{ $loop->first ? '' : ' items-next' }}">
    <thead class="h">
    <tr>
        <th class="desc">{{ $t('table.description') }}</th>
        <th>{{ $t('table.unit') }}</th>
        <th>{{ $t('table.quantity') }}</th>
        <th>{{ $t('table.unit_price') }}</th>
        @if ($vatColumn)
            <th>{{ $t('table.vat') }}</th>
        @endif
        <th>{{ $t('table.total') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($page as $row)
        <tr>
            <td class="desc">{{ $row[0] }}</td>
            <td>{{ $row[1] }}</td>
            <td>{{ $row[2] }}</td>
            <td>{{ $row[3] }}</td>
            @if ($vatColumn)
                <td>{{ $row[4] }}</td>
            @endif
            <td>{{ $row[5] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endforeach

@if ($view->pricesIncludeTax && $taxed)
    <div id="prices-include-tax" class="note">{{ $t('conditions.vat_included') }}</div>
@endif

<table class="totals">
    <tr><td class="h">{{ $t('totals.excl_vat') }}</td><td>{{ $money($view->totalExcludingVat) }}</td></tr>
    <tr><td class="h">{{ $t('totals.discount') }}</td><td>{{ $money($view->discountAmount) }}</td></tr>
    @if ($taxed)
        <tr id="tax-row"><td class="h">{{ $t('totals.tax') }}</td><td>{{ $money($view->taxAmount) }}</td></tr>
    @endif
    {{-- D-99: keyed on the printed figure — the stored `0.000000` of D-65's "rounding off" is no row. U-6: the one figure that can be negative is isolated, so «-0.40» keeps its minus in Arabic. --}}
    @if ($money($view->roundingDiff) !== '0.00')
        <tr id="rounding-row"><td class="h">{{ $t('totals.rounding') }}</td><td>{{ $ltr($money($view->roundingDiff)) }}</td></tr>
    @endif
    <tr class="final"><td class="h">{{ $t('totals.final') }}</td><td>{{ $money($view->finalTotal) }} {{ $view->currencyCode }}</td></tr>
</table>

<div class="terms">
    <div class="h">{{ $t('terms.heading') }}</div>
    {{-- D-103's order, then D-107's VAT line; the numbers are the list's own,
         Latin in both languages, as the reference HTML draws them. A term with
         no name prints the PDF's label. --}}
    <ol>
        <li>{{ $t('conditions.currency', ['currency' => $view->currencyCode]) }}</li>
        @foreach ($view->terms as $term)
            <li>{{ $t('conditions.term', ['name' => $term->title ?? $t('terms.labels.'.$term->key), 'body' => $term->body]) }}</li>
        @endforeach
        @if ($view->validUntil !== null)
            <li id="validity">{{ $t('conditions.validity', ['date' => $ltr($view->validUntil)]) }}</li>
        @endif
        @if ($taxed)
            <li id="vat-included">{{ $t('conditions.vat_included') }}</li>
        @endif
    </ol>
</div>

<div class="contact">{{ $t('closing') }}</div>

<div class="closing">
    <div>
        <div>{{ $t('regards') }}</div>
        @if ($view->signatoryName !== null)
            <div id="signatory" class="name">{{ $view->signatoryName }}</div>
        @endif
        @php($signatoryTitle = $rtl ? $view->signatoryTitleAr : $view->signatoryTitleEn)
        @if ($signatoryTitle !== null)
            <div id="signatory-title" class="role">{{ $signatoryTitle }}</div>
        @endif
    </div>
    <div class="sign">
        <div class="line"></div>
        <div class="h">{{ $t('signature') }}</div>
    </div>
</div>

</body>
</html>
