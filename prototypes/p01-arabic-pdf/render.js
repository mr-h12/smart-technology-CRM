/**
 * P-01 — Arabic PDF prototype (MVP_Build_Plan_EN.md §0.1, risk R-02)
 *
 * Success criterion: a PDF containing a full Arabic paragraph, an items table
 * and numbers, correctly shaped, with no broken glyphs and embedded fonts.
 *
 * Renders in the Smart Technology house style, in both Arabic (RTL) and
 * English (LTR), from a single template — proving the R-02 shaping question
 * and the RTL/LTR parity requirement at the same time.
 *
 * Uses headless Chrome, the same engine Laravel's Browsershot drives, so this
 * transfers to Module 9 with only the wrapper changing.
 */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');
const { render } = require('./template');

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const OUT = path.join(__dirname, 'out');
const f = (p) => fs.readFileSync(path.join(__dirname, p)).toString('base64');

// Every font is embedded. The on-premise Linux server has no Arabic system
// fonts, and `sans-serif` resolves differently there than on macOS — an
// unembedded face would change metrics between dev and production.
const assets = {
  ar400: f('node_modules/@fontsource/noto-sans-arabic/files/noto-sans-arabic-arabic-400-normal.woff2'),
  ar700: f('node_modules/@fontsource/noto-sans-arabic/files/noto-sans-arabic-arabic-700-normal.woff2'),
  la400: f('node_modules/@fontsource/inter/files/inter-latin-400-normal.woff2'),
  la700: f('node_modules/@fontsource/inter/files/inter-latin-700-normal.woff2'),
  se700: f('node_modules/@fontsource/noto-serif/files/noto-serif-latin-700-normal.woff2'),
  logo: f('assets/logo.jpg'),
  band: f('assets/footer-band.jpg'),
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', '--font-render-hinting=none'],
  });

  const report = [];

  for (const locale of ['ar', 'en']) {
    const html = render(locale, assets);
    fs.writeFileSync(path.join(OUT, `quotation.${locale}.html`), html);

    const page = await browser.newPage();
    await page.setContent(html, { waitUntil: 'networkidle0' });
    await page.evaluateHandle('document.fonts.ready');

    const fonts = await page.evaluate(() =>
      [...document.fonts].filter((x) => x.status === 'loaded').length
    );

    await page.pdf({
      path: path.join(OUT, `QT-2026-0001.${locale}.pdf`),
      format: 'A4',
      printBackground: true,
      preferCSSPageSize: true,
    });

    await page.setViewport({ width: 1240, height: 1754, deviceScaleFactor: 2 });
    await page.screenshot({ path: path.join(OUT, `preview.${locale}.png`), fullPage: true });

    // A blank trailing page is the classic silent failure here: 3mm of
    // overflow costs a whole sheet. Assert it rather than trusting the eye.
    const buf = fs.readFileSync(path.join(OUT, `QT-2026-0001.${locale}.pdf`));
    const m = /\/Type\s*\/Pages[\s\S]*?\/Count\s+(\d+)/.exec(buf.toString('latin1'));
    const pages = m ? +m[1] : -1;

    report.push({ locale, fontsLoaded: fonts, pages, ok: pages === 1 });
    await page.close();
  }

  await browser.close();
  console.table(report);
  if (report.some((r) => !r.ok)) {
    console.error('FAIL: expected exactly 1 page per locale');
    process.exit(1);
  }
  console.log('P-01 PASS — output in', OUT);
})().catch((e) => {
  console.error('P-01 FAILED:', e.message);
  process.exit(1);
});
