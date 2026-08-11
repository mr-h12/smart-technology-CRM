/**
 * Customer quotation template — Smart Technology house style.
 *
 * Visual language taken from the company's Purchase Order #226 document:
 * logo lockup, blue rule pair around the title, #5B9BD5 header cells on
 * #DEEAF6 tinted rows, General Condition block, signature pair, footer band.
 *
 * One template renders both Arabic (RTL) and English (LTR) — no duplicated
 * markup, no hard-coded user-facing strings (Module 0 requirement).
 *
 * Customer-facing: carries no supplier name, supplier price, cost, or margin
 * under any circumstances (CRM_Documentation_EN.md 3.12 rule 2).
 */

const BRAND = {
  blue: '#5B9BD5',       // meta labels + table header
  tint: '#DEEAF6',       // alternating row tint
  rule: '#4472C4',       // rules above/below the title
  navy: '#112131',       // footer band
  ink: '#342833',        // logo dark
};

const STRINGS = {
  en: {
    dir: 'ltr', lang: 'en',
    docTitle: 'Quotation',
    date: 'Date', company: 'Company Name', to: 'TO',
    intro:
      'Further to the above-mentioned subject and reference we are pleased to submit our quotation ' +
      'to you for the below items:',
    section: 'QUOTATION INFORMATION',
    item: 'Item', name: 'Name', qty: 'QTY', unitPrice: 'UNIT PRICE', total: 'TOTAL',
    subtotal: 'Total Amount in Egyptian Pounds Ex VAT',
    discount: 'Discount 5%',
    net: 'Net Amount',
    additional: 'Delivery & Installation',
    vat: '14% VAT',
    rounding: 'Rounding',
    grand: 'Total After Taxes',
    conditions: 'General Condition',
    cond: [
      'Payment: 50% advance, balance upon delivery.',
      'Delivery: within two weeks of written confirmation.',
      'This quotation is valid for 30 days from the issue date.',
    ],
    preparedBy: 'Prepared By', approvedBy: 'Approved By',
    validUntil: 'Valid Until', dealRef: 'Deal Reference', currency: 'Currency',
    page: 'Page 1 of 1',
    customer: 'Al Amal Specialist Hospital',
    contact: 'Mr. Ibrahim Abdel Rahman',
    currencyValue: 'Egyptian Pound (EGP)',
    items: [
      ['Formatter M428dw for HP LaserJet M428dw', 'P/N. W1A28-60001', '1', '5,219.30', '5,219.30'],
      ['Fuser unit for printer Canon MF410', 'P/N. FM1-D112-000CN', '1', '2,149.12', '2,149.12'],
      ['Vital Signs Monitor — 15" display', 'P/N. VSM-1500', '3', '18,900.00', '56,700.00'],
    ],
    salesName: 'Ahmed Essam', mgrName: 'Yasmin Ali',
  },
  ar: {
    dir: 'rtl', lang: 'ar',
    docTitle: 'عرض سعر',
    date: 'التاريخ', company: 'اسم الشركة', to: 'إلى',
    intro:
      'إلحاقًا بالموضوع والمرجع المذكورين أعلاه، يسرّنا أن نتقدم إليكم بعرض الأسعار التالي للأصناف المبيَّنة أدناه:',
    section: 'بيانات عرض السعر',
    item: 'م', name: 'الصنف', qty: 'الكمية', unitPrice: 'سعر الوحدة', total: 'الإجمالي',
    subtotal: 'الإجمالي بالجنيه المصري قبل الضريبة',
    discount: 'الخصم 5%',
    net: 'الصافي',
    additional: 'التوصيل والتركيب',
    vat: 'ضريبة القيمة المضافة 14%',
    rounding: 'فرق التقريب',
    grand: 'الإجمالي بعد الضرائب',
    conditions: 'الشروط العامة',
    cond: [
      'الدفع: 50% مقدمًا والباقي عند التسليم.',
      'التسليم: خلال أسبوعين من تاريخ التأكيد الكتابي.',
      'هذا العرض ساري لمدة ثلاثين يومًا من تاريخ إصداره.',
    ],
    preparedBy: 'أُعدّ بواسطة', approvedBy: 'اعتمده',
    validUntil: 'صالح حتى', dealRef: 'رقم الصفقة', currency: 'العملة',
    page: 'صفحة 1 من 1',
    customer: 'مستشفى الأمل التخصصي',
    contact: 'الأستاذ / إبراهيم عبد الرحمن',
    currencyValue: 'جنيه مصري (EGP)',
    items: [
      ['وحدة الفورماتر لطابعة HP LaserJet M428dw', 'P/N. W1A28-60001', '1', '5,219.30', '5,219.30'],
      ['وحدة تثبيت الحبر لطابعة Canon MF410', 'P/N. FM1-D112-000CN', '1', '2,149.12', '2,149.12'],
      ['شاشة مراقبة العلامات الحيوية — 15 بوصة', 'P/N. VSM-1500', '3', '18,900.00', '56,700.00'],
    ],
    salesName: 'أحمد عصام', mgrName: 'ياسمين علي',
  },
};

// Figures follow the documented chain in CRM_Documentation_EN.md 5.2:
// discount on subtotal, tax on (net + additional), rounding on the final total only.
const FIGURES = {
  code: 'QT-2026-0001',
  dealCode: 'DL-2026-0043',
  date: '2026-08-11',
  validUntil: '2026-09-10',
  subtotal: '64,068.42',
  discount: '−3,203.42',
  net: '60,865.00',
  additional: '1,200.00',
  vat: '8,689.10',
  rounding: '−0.10',
  grand: '70,754',
};

function render(locale, assets) {
  const t = STRINGS[locale];
  const f = FIGURES;

  const itemRows = t.items
    .map(
      ([name, pn, qty, unit, total], i) => `
      <tr>
        <td class="c idx">-${i + 1}</td>
        <td class="nm"><strong>${name}</strong><span class="pn">${pn}</span></td>
        <td class="c">${qty}</td>
        <td class="c">${unit}</td>
        <td class="c">${total}</td>
      </tr>`
    )
    .join('');

  const totalRow = (label, value, strong = false) => `
      <tr class="tot${strong ? ' grand' : ''}">
        <td colspan="4">${label}</td>
        <td class="c">${value}</td>
      </tr>`;

  return `<!doctype html>
<html lang="${t.lang}" dir="${t.dir}">
<head>
<meta charset="utf-8">
<title>${f.code}</title>
<style>
  @font-face{font-family:'Noto Sans Arabic';font-weight:400;src:url(data:font/woff2;base64,${assets.ar400}) format('woff2')}
  @font-face{font-family:'Noto Sans Arabic';font-weight:700;src:url(data:font/woff2;base64,${assets.ar700}) format('woff2')}
  @font-face{font-family:'Inter';font-weight:400;src:url(data:font/woff2;base64,${assets.la400}) format('woff2')}
  @font-face{font-family:'Inter';font-weight:700;src:url(data:font/woff2;base64,${assets.la700}) format('woff2')}
  @font-face{font-family:'Noto Serif';font-weight:700;src:url(data:font/woff2;base64,${assets.se700}) format('woff2')}

  @page { size: A4; margin: 10mm 10mm 0 10mm; }
  * { box-sizing: border-box; }

  body{
    font-family:'Inter','Noto Sans Arabic',sans-serif;
    font-size:10pt; line-height:1.5; color:#1A1A1A; background:#fff; margin:0;
    font-variant-numeric:tabular-nums; font-feature-settings:'tnum' 1;
    position:relative;
  }

  /* Watermark: brand mark, kept faint enough to never fight the text. */
  .wm{
    position:fixed; top:38%; inset-inline-start:18%; width:64%;
    opacity:.05; z-index:0; pointer-events:none;
  }
  .sheet{ position:relative; z-index:1; }

  .brand img{ height:52px; }

  .title-block{ text-align:center; margin:14px 0 18px; }
  .title-block .rule{ height:3px; background:${BRAND.rule}; width:46%; margin:0 auto; }
  .title-block .rule.thin{ height:1.5px; }
  .title-block h1{
    font-family:'Noto Serif','Noto Sans Arabic',serif; font-weight:700;
    font-size:19pt; margin:9px 0; letter-spacing:.2px;
  }

  table{ width:100%; border-collapse:collapse; }

  .meta{ width:78%; margin-bottom:10px; font-size:9.5pt; }
  .meta th{
    background:${BRAND.blue}; color:#fff; font-style:italic; font-weight:700;
    text-align:start; padding:4px 10px; width:26%; border:1px solid #fff;
  }
  .meta td{ background:${BRAND.tint}; padding:4px 10px; border:1px solid #fff; }
  .meta tr:nth-child(even) td{ background:#EDF4FB; }

  .intro{ margin:8px 0 14px; }

  .section-title{ text-align:center; margin:16px 0 8px; }
  .section-title span{
    display:inline-block; font-weight:700; font-size:11pt; padding:3px 0;
    border-top:1px solid ${BRAND.rule}; border-bottom:1px solid ${BRAND.rule};
    min-width:42%;
  }

  .items{ font-size:9.5pt; border:1px solid ${BRAND.blue}; }
  .items thead th{
    background:${BRAND.blue}; color:#fff; font-weight:700; text-align:center;
    padding:7px 6px; border:1px solid #fff;
  }
  .items tbody td{ padding:8px 8px; border:1px solid #BFD6EC; vertical-align:middle; }
  .items tbody tr:nth-child(odd) td{ background:${BRAND.tint}; }
  .items td.c{ text-align:center; }
  .items td.idx{ font-weight:700; width:7%; }
  .items td.nm{ width:53%; }
  .items td.nm .pn{ display:block; font-size:8.5pt; color:#4A5A6A; margin-top:2px; }
  .items th:nth-child(3),.items td:nth-child(3){ width:9%; }
  .items th:nth-child(4),.items td:nth-child(4){ width:15%; }
  .items th:nth-child(5),.items td:nth-child(5){ width:16%; }

  .items tr.tot td{ text-align:center; font-weight:700; background:#fff; }
  .items tr.tot:nth-child(even) td{ background:${BRAND.tint}; }
  /* Specificity must beat .items tr.tot:nth-child(even) td (0,3,2), or the
     grand total renders white-on-tint and fails WCAG AA (Design_System 8). */
  .items tbody tr.grand.grand td{ background:${BRAND.blue}; color:#fff; font-size:11pt; }

  .conditions{ margin-top:16px; }
  .conditions h2{
    font-size:12pt; font-weight:700; text-decoration:underline;
    text-underline-offset:3px; margin:0 0 6px;
  }
  .conditions ol{ margin:0; padding-inline-start:22px; }
  .conditions li{ margin-bottom:3px; font-weight:600; }

  .signatures{
    display:flex; justify-content:space-between;
    margin-top:26px; padding:0 6px;
    font-family:'Noto Serif','Noto Sans Arabic',serif; font-weight:700; font-size:10.5pt;
  }
  .signatures .who{ margin-bottom:16px; }
  .signatures .line{ border-top:1px solid #99A6B4; padding-top:3px; min-width:150px; }

  .band{ position:fixed; bottom:0; inset-inline:0; }
  .band img{ width:100%; display:block; }
  .pageno{
    position:fixed; bottom:26mm; inset-inline-end:2mm;
    font-size:8pt; color:#5A6672;
  }
</style>
</head>
<body>

<img class="wm" src="data:image/jpeg;base64,${assets.logo}" alt="">

<div class="sheet">

  <div class="brand"><img src="data:image/jpeg;base64,${assets.logo}" alt="Smart Technology"></div>

  <div class="title-block">
    <div class="rule"></div>
    <h1>${t.docTitle} #${f.code}</h1>
    <div class="rule thin"></div>
  </div>

  <table class="meta">
    <tr><th>${t.date}</th><td>${f.date}</td></tr>
    <tr><th>${t.company}</th><td>${t.customer}</td></tr>
    <tr><th>${t.to}</th><td>${t.contact}</td></tr>
    <tr><th>${t.dealRef}</th><td>${f.dealCode}</td></tr>
    <tr><th>${t.currency}</th><td>${t.currencyValue}</td></tr>
    <tr><th>${t.validUntil}</th><td>${f.validUntil}</td></tr>
  </table>

  <p class="intro">${t.intro}</p>

  <div class="section-title"><span>${t.section}</span></div>

  <table class="items">
    <thead>
      <tr>
        <th>${t.item}</th><th>${t.name}</th><th>${t.qty}</th>
        <th>${t.unitPrice}</th><th>${t.total}</th>
      </tr>
    </thead>
    <tbody>
      ${itemRows}
      ${totalRow(t.subtotal, f.subtotal)}
      ${totalRow(t.discount, f.discount)}
      ${totalRow(t.net, f.net)}
      ${totalRow(t.additional, f.additional)}
      ${totalRow(t.vat, f.vat)}
      ${totalRow(t.rounding, f.rounding)}
      ${totalRow(t.grand, f.grand, true)}
    </tbody>
  </table>

  <section class="conditions">
    <h2>${t.conditions}</h2>
    <ol>${t.cond.map((c) => `<li>${c}</li>`).join('')}</ol>
  </section>

  <div class="signatures">
    <div>
      <div class="who">${t.preparedBy}</div>
      <div class="line">${t.salesName}</div>
    </div>
    <div>
      <div class="who">${t.approvedBy}</div>
      <div class="line">${t.mgrName}</div>
    </div>
  </div>

</div>

<div class="pageno">${f.code} · ${t.page}</div>
<div class="band"><img src="data:image/jpeg;base64,${assets.band}" alt=""></div>

</body>
</html>`;
}

module.exports = { render, BRAND, STRINGS, FIGURES };
