// Lesson 7 edge cases on the saved draft: 409 from two tabs, quantity warning, then the clean draft (qty 10).
const f = (id) => `[data-testid="quotation-builder-${id}"]`;
async function setVal(page, sel, v) {
    await page.click(sel);
    await page.keyboard.down('Control'); await page.keyboard.press('a'); await page.keyboard.up('Control');
    await page.keyboard.press('Backspace');
    await page.type(sel, v);
}
async function save(page, h) { await page.click(f('save')); await page.waitForNetworkIdle(); await h.sleep(1000); }
module.exports = async (page, h, l, id) => {
    const url = h.BASE + '/quotations/' + id + '/edit';
    await page.goto(url, { waitUntil: 'networkidle0' });
    await h.lang(l);
    await page.waitForSelector(f('existing-0-quantity'));
    const b = await page.browser().newPage();
    await b.setViewport({ width: 1280, height: 800, deviceScaleFactor: 2 });
    b.on('dialog', (d) => d.accept().catch(() => {}));
    await b.goto(url, { waitUntil: 'networkidle0' });
    await b.waitForSelector(f('existing-0-quantity'));
    // tab B saves first
    await setVal(b, f('existing-0-quantity'), '12');
    await save(b, h);
    console.log('B qty now', await b.$eval(f('existing-0-quantity'), (e) => e.value));
    await b.close();
    // tab A is now stale
    await setVal(page, f('discount_percent'), '5');
    await save(page, h);
    const c = await page.$(f('conflict'));
    console.log('conflict:', c ? await c.evaluate((e) => e.innerText) : null);
    if (c) await h.shot('08g-qt-409', l, f('conflict-reload'));
    await page.goto(url, { waitUntil: 'networkidle0' });
    await page.waitForSelector(f('existing-0-quantity'));
    await setVal(page, f('existing-0-quantity'), '10');
    await save(page, h);
    console.log('after save', page.url());
};
