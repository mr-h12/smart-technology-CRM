// Runs one walk script against a persistent Chromium (sales :9222, manager :9223).
// usage: node run.js <port> <walk/script.js> [args...]
const puppeteer = require('/usr/local/lib/node_modules/puppeteer');
const fs = require('fs');
const path = require('path');
const BASE = 'https://nginx';
const OUT = '/tmp/training/shots';

(async () => {
    const [port, script, ...args] = process.argv.slice(2);
    const browser = await puppeteer.connect({ browserURL: `http://127.0.0.1:${port}`, defaultViewport: null });
    const pages = await browser.pages();
    const page = pages[0];
    await page.setViewport({ width: 1280, height: 800, deviceScaleFactor: 2 });
    page.setDefaultTimeout(15000);
    // An unsaved builder raises beforeunload; leaving is always intended here.
    page.on('dialog', (d) => d.accept().catch(() => {}));
    fs.mkdirSync(OUT, { recursive: true });

    const h = {
        BASE,
        sleep: (ms) => new Promise((r) => setTimeout(r, ms)),
        async go(p) { await page.goto(BASE + p, { waitUntil: 'networkidle0' }); },
        async lang(l) {
            await page.evaluate((l) => localStorage.setItem('crm.locale', l), l);
            await page.reload({ waitUntil: 'networkidle0' });
        },
        tid: (id) => `[data-testid="${id}"]`,
        // Saves NN-slug.lang.png + .json with the target's box (CSS px, viewport-relative).
        async shot(name, lang, target, extra = {}) {
            await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur());
            await new Promise((r) => setTimeout(r, 300));
            let box = null;
            if (target) {
                const el = typeof target === 'string' ? await page.$(target) : target;
                if (!el) throw new Error('target not found: ' + target);
                if (!extra.noScroll) await el.evaluate((e) => e.scrollIntoView({ block: 'center', inline: 'center' }));
                await new Promise((r) => setTimeout(r, 300));
                box = await el.boundingBox();
            }
            const base = `${OUT}/${name}.${lang}`;
            await page.screenshot({ path: base + '.png' });
            fs.writeFileSync(base + '.json', JSON.stringify({ box, ...extra }));
            console.log('shot', name, lang, JSON.stringify(box));
        },
        label: (sel) => page.evaluateHandle((s) => { const e = document.querySelector(s); return e.closest('label') || e; }, sel),
        async text(sel) { return page.$eval(sel, (e) => e.innerText); },
    };
    const mod = require(path.resolve(script));
    try { await mod(page, h, ...args); }
    catch (e) { console.log('ERROR', e.message); await page.screenshot({ path: OUT + '/_error.png' }); }
    browser.disconnect();
})();
