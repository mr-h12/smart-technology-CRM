// Renders src/sales.<lang>.html to sales-<lang>.pdf with the worker-pdf Chromium (fonts embedded).
// usage (in the container, from /tmp/training): node render.js ar en
const puppeteer = require('/usr/local/lib/node_modules/puppeteer');
(async () => {
    const browser = await puppeteer.launch({ executablePath: process.env.PUPPETEER_EXECUTABLE_PATH, args: ['--no-sandbox', '--allow-file-access-from-files'] });
    for (const lang of process.argv.slice(2)) {
        const page = await browser.newPage();
        await page.goto(`file:///tmp/training/src/sales.${lang}.html`, { waitUntil: 'networkidle0' });
        await page.evaluate(() => document.fonts.ready);
        const missing = await page.evaluate(() => [...document.images].filter((i) => !i.complete || i.naturalWidth === 0).map((i) => i.src));
        if (missing.length) console.log('MISSING IMAGES', missing);
        await page.pdf({ path: `/tmp/training/sales-${lang}.pdf`, format: 'A4', landscape: true, printBackground: true, preferCSSPageSize: true });
        console.log('rendered', lang);
        await page.close();
    }
    await browser.close();
})();
