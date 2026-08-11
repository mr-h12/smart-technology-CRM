/**
 * P-01 — Arabic PDF prototype (MVP_Build_Plan_EN.md §0.1, risk R-02)
 *
 * Success criterion: a PDF containing a full Arabic paragraph, an items table
 * and numbers, correctly shaped, with no broken glyphs and embedded fonts.
 *
 * Renders through headless Chrome. This is the same engine Laravel's
 * Browsershot drives, so a pass here transfers directly to the Laravel
 * implementation in Module 9 — only the wrapper changes.
 */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const ARABIC_DIR = path.join(__dirname, 'node_modules/@fontsource/noto-sans-arabic/files');
const LATIN_DIR = path.join(__dirname, 'node_modules/@fontsource/inter/files');
const OUT = path.join(__dirname, 'out');

const b64 = (dir, f) => fs.readFileSync(path.join(dir, f)).toString('base64');

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  // Embed fonts. Never rely on OS fonts: the on-premise Linux server has none.
  const html = fs
    .readFileSync(path.join(__dirname, 'template.html'), 'utf8')
    .replace('__FONT_400__', b64(ARABIC_DIR, 'noto-sans-arabic-arabic-400-normal.woff2'))
    .replace('__FONT_700__', b64(ARABIC_DIR, 'noto-sans-arabic-arabic-700-normal.woff2'))
    .replace('__INTER_400__', b64(LATIN_DIR, 'inter-latin-400-normal.woff2'))
    .replace('__INTER_700__', b64(LATIN_DIR, 'inter-latin-700-normal.woff2'));

  const selfContained = path.join(OUT, 'quotation.embedded.html');
  fs.writeFileSync(selfContained, html);

  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', '--font-render-hinting=none'],
  });

  const page = await browser.newPage();
  await page.setContent(html, { waitUntil: 'networkidle0' });
  await page.evaluateHandle('document.fonts.ready');

  // Confirm the embedded face actually loaded rather than silently falling back.
  const fontStatus = await page.evaluate(() => {
    const loaded = [...document.fonts].map((f) => `${f.family} ${f.weight} ${f.status}`);
    return { count: document.fonts.size, loaded, ready: document.fonts.status };
  });

  await page.pdf({
    path: path.join(OUT, 'QT-2026-0001.pdf'),
    format: 'A4',
    printBackground: true,
    preferCSSPageSize: true,
  });

  await page.setViewport({ width: 1240, height: 1754, deviceScaleFactor: 2 });
  await page.screenshot({ path: path.join(OUT, 'preview.png'), fullPage: true });

  await browser.close();

  console.log(JSON.stringify({ fonts: fontStatus, out: OUT }, null, 2));
})().catch((e) => {
  console.error('P-01 FAILED:', e.message);
  process.exit(1);
});
