{{--
    Module 13, slice 4 (`D-132`, spec §4.2) — the stored PDF of one submitted
    report version: the frozen copy's content (`D-126`, `D-130`, `D-131`,
    `D-133`), in Arabic only. Styled as the quotation's family
    (`customer-quotation.blade.php`): the same faces, the same greys and hairlines,
    the same side margins; the page margins live in the renderer.

    Every word comes from `lang/ar/pdf.php`'s `report` group through `$t`. Times
    arrive in UTC and are printed through `$time`, in the company's zone
    (`locale.timezone`). Selling totals only — never cost, margin or profit.

    Nothing is fetched: the faces and the logo are `data:` URIs from
    `PdfAssetsInterface`, as in `customer-quotation.blade.php`. The logo's
    `alt=""` is deliberate, as there.

    A group with no rows is left out — its tile in the summary already says 0 —
    and `none` prints once only when every group is empty. Follow-ups and notes
    are the author's own sections, so an empty one says so. A move prints its
    from and to status, a customer response its response, through `$word` from
    `lang/ar/pdf.php` (`report.deal_status.*`, `report.response.*`, kept equal to the
    SPA's words by `ReportPdfLabelsTest`). A code with no label prints raw; no from is a dash.

    A weekly report (`D-126`, `D-134`) names its week (`meta.period_weekly`, `summary_weekly`), then
    prints its own sections from `report.weekly.*` — its daily reports with their status
    (`report.status.*`, the SPA's `reports.status.*`), the POs received, the new customers — and has
    no follow-ups (`D-137`).
--}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ $t('title', ['code' => $report['code']]) }}</title>
    <style>
        {!! $fontFaceCss !!}

        /* Page one begins 8 mm down, as the quotation's does; later pages take
           the renderer's top margin, trimmed here (Chrome honours @page). */
        @page { size: A4; margin: 16mm 0 27.5mm; }
        @page :first { margin: 8mm 0 27.5mm; }
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0 22mm;
            font-family: 'CRM Sans Arabic', 'CRM Sans';
            font-size: 10.5pt;
            line-height: 1.45;
            color: #000;
        }

        h1, h2, h3 { margin: 0; font-weight: 700; }
        .num { font-variant-numeric: tabular-nums; }

        .report-header { display: flex; justify-content: space-between; align-items: flex-end; padding-bottom: 3mm; border-bottom: 1pt solid #000; }
        .logo { width: 62mm; display: block; margin-left: -1.3mm; }
        .report-header h1 { font-size: 16pt; line-height: 1.2; }
        .report-header .code { font-size: 10pt; color: #555; text-align: end; }

        .report-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5mm 8mm; margin-top: 5mm; }
        .report-meta .label { font-weight: 700; }

        .report-summary { margin-top: 6mm; }
        .report-summary h2, .report-weekly h2, .report-follow-ups h2, .report-notes h2 { font-size: 12.5pt; padding-bottom: 1.2mm; border-bottom: 0.75pt solid #bfbfbf; }
        .kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 3mm; margin-top: 3mm; }
        .kpi { background: #f2f2f2; border: 0.75pt solid #bfbfbf; padding: 2.4mm 3mm; break-inside: avoid; }
        .kpi .n { font-size: 17pt; font-weight: 700; line-height: 1.2; }
        .kpi .l { font-size: 9pt; color: #333; }

        .report-group, .report-weekly, .report-follow-ups, .report-notes { padding-top: 7mm; }
        .report-group h3 { font-size: 11.5pt; margin-bottom: 2mm; break-after: avoid; break-inside: avoid; }
        .report-group h3 .count, .report-weekly h2 .count { font-weight: 400; color: #555; }

        table { border-collapse: collapse; width: 100%; }
        th, td { padding: 1.9mm 1.6mm; text-align: start; vertical-align: top; border-bottom: 0.5pt solid #d9d9d9; }
        th { background: #f2f2f2; border-top: 0.75pt solid #bfbfbf; border-bottom: 0.75pt solid #bfbfbf; font-weight: 700; white-space: nowrap; }
        .report-group th:first-child { width: 34%; }
        td.amount, th.amount { text-align: end; white-space: nowrap; }
        td.when { white-space: nowrap; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; page-break-inside: avoid; }

        .report-weekly table, .report-follow-ups table { margin-top: 3mm; }
        .report-follow-ups td.note { width: 55%; }
        .report-weekly ol, .report-notes ol { margin: 3mm 0 0; padding-inline-start: 6mm; }
        .report-weekly li, .report-notes li { margin-bottom: 1.5mm; break-inside: avoid; }
        .empty { margin: 3mm 0 0; color: #666; }
    </style>
</head>
<body>

<header class="report-header">
    <img class="logo" src="{{ $logo }}" alt="">
    <div>
        <h1>{{ $t($report['type'] === 'weekly' ? 'heading_weekly' : 'heading') }}</h1>
        <div class="code num">{{ $ltr($report['code']) }}</div>
    </div>
</header>

<section class="report-meta">
    <div><span class="label">{{ $t('meta.version') }}</span> {{ $report['version'] }}</div>
    <div><span class="label">{{ $t('meta.author') }}</span> {{ $report['author_name'] !== '' ? $report['author_name'] : $t('no_value') }}</div>
    <div><span class="label">{{ $t($report['type'] === 'weekly' ? 'meta.period_weekly' : 'meta.period') }}</span> {{ $ltr($report['period_start']) }}@if ($report['period_end'] !== $report['period_start']) – {{ $ltr($report['period_end']) }}@endif</div>
    <div><span class="label">{{ $t('meta.submitted_at') }}</span> {{ $ltr($time($report['submitted_at'])) }}</div>
</section>

@php($groups = array_filter($report['activity']['sections']))

<section class="report-summary">
    <h2>{{ $t($report['type'] === 'weekly' ? 'summary_weekly' : 'summary') }}</h2>

    <div class="kpis">
        @foreach ($report['activity']['sections'] as $group => $rows)
            <div class="kpi" data-group="{{ $group }}">
                <div class="n num">{{ $report['activity']['counts'][$group] ?? count($rows) }}</div>
                <div class="l">{{ $t('groups.'.$group) }}</div>
            </div>
        @endforeach
    </div>

    @if ($groups === [])
        <p class="empty">{{ $t('none') }}</p>
    @endif

    @foreach ($groups as $group => $rows)
        {{-- A group of deals or status moves has no money: no empty total column. --}}
        @php($priced = array_column($rows, 'final_total') !== [])
        <section class="report-group">
            <h3>{{ $t('groups.'.$group) }} <span class="count num">({{ $report['activity']['counts'][$group] ?? count($rows) }})</span></h3>
            <table>
                <thead>
                    <tr>
                        <th>{{ $t('columns.code') }}</th>
                        <th>{{ $t('columns.customer') }}</th>
                        @if ($group === 'deal_status_moves')
                            <th>{{ $t('columns.from') }}</th>
                            <th>{{ $t('columns.to') }}</th>
                        @elseif ($group === 'customer_responses')
                            <th>{{ $t('columns.response') }}</th>
                        @endif
                        @if ($priced)
                            <th class="amount">{{ $t('columns.total') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="num">{{ $ltr((string) ($row['code'] ?? $row['po_number'] ?? '')) }}</td>
                            <td>{{ $row['customer_name'] ?? '' }}</td>
                            @if ($group === 'deal_status_moves')
                                <td>{{ $word('deal_status', $row['from_status'] ?? null) }}</td>
                                <td>{{ $word('deal_status', $row['to_status'] ?? null) }}</td>
                            @elseif ($group === 'customer_responses')
                                <td>{{ $word('response', $row['response'] ?? null) }}</td>
                            @endif
                            @if ($priced)
                                <td class="amount num">@if (isset($row['final_total'])){{ $ltr($money((string) $row['final_total']).' '.($row['currency'] ?? '')) }}@endif</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endforeach
</section>

@if ($report['type'] === 'weekly')
    @php($dailyReports = $report['daily_reports'] ?? [])
    @php($ordersReceived = $report['purchase_orders_received'] ?? 0)
    @php($newCustomers = $report['new_customers']['items'] ?? [])

    <section class="report-weekly">
        <h2>{{ $t('weekly.daily_reports') }}</h2>

        @if ($dailyReports === [])
            <p class="empty">{{ $t('weekly.no_daily_reports') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ $t('columns.code') }}</th>
                        <th>{{ $t('columns.day') }}</th>
                        <th>{{ $t('columns.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dailyReports as $row)
                        <tr>
                            <td class="num">{{ $ltr($row['code']) }}</td>
                            <td class="when num">{{ $ltr($row['period_start']) }}</td>
                            <td>{{ $word('status', $row['status']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="report-weekly">
        <h2>{{ $t('weekly.purchase_orders_received') }}@if ($ordersReceived > 0) <span class="count num">({{ $ordersReceived }})</span>@endif</h2>

        @if ($ordersReceived === 0)
            <p class="empty">{{ $t('weekly.no_purchase_orders') }}</p>
        @endif
    </section>

    <section class="report-weekly">
        <h2>{{ $t('weekly.new_customers') }}@if ($newCustomers !== []) <span class="count num">({{ $report['new_customers']['count'] }})</span>@endif</h2>

        @if ($newCustomers === [])
            <p class="empty">{{ $t('weekly.no_new_customers') }}</p>
        @else
            <ol>
                @foreach ($newCustomers as $customer)
                    <li>{{ $customer['name'] }}</li>
                @endforeach
            </ol>
        @endif
    </section>
@else
<section class="report-follow-ups">
    <h2>{{ $t('follow_ups') }}</h2>

    @if ($report['follow_ups'] === [])
        <p class="empty">{{ $t('no_follow_ups') }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>{{ $t('columns.customer') }}</th>
                    <th>{{ $t('note') }}</th>
                    <th>{{ $t('follow_up_time') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['follow_ups'] as $followUp)
                    <tr>
                        <td>{{ $followUp['customer_name'] }}</td>
                        <td class="note">{{ $followUp['note'] }}</td>
                        <td class="when num">{{ isset($followUp['followed_up_at']) ? $ltr($time($followUp['followed_up_at'])) : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
@endif

<section class="report-notes">
    <h2>{{ $t('notes') }}</h2>

    @if ($report['notes'] === [])
        <p class="empty">{{ $t('no_notes') }}</p>
    @else
        <ol>
            @foreach ($report['notes'] as $note)
                <li>{{ $note['body'] }}</li>
            @endforeach
        </ol>
    @endif
</section>

</body>
</html>
