// Lesson 7: build the quotation (QT). FX-missing refusal (EUR, no rate) and quantity warning triggered for real.
const DEAL = { ar: '01a147e3-62d5-71e2-95ee-2cfccf90627a', en: '01a147e3-7adb-70fb-a841-e1808f5c2311' };
const SQ = { ar: 'SQ-2026-0012', en: 'SQ-2026-0013' };
const f = (id) => `[data-testid="quotation-builder-${id}"]`;
async function setVal(page, sel, v) {
    await page.click(sel);
    await page.keyboard.down('Control'); await page.keyboard.press('a'); await page.keyboard.up('Control');
    await page.keyboard.press('Backspace');
    await page.type(sel, v);
}
async function selectByText(page, sel, re) {
    const opts = await page.$$eval(sel + ' option', (o) => o.map((x) => [x.value, x.textContent.trim()]));
    const o = opts.find((x) => re.test(x[1]));
    if (!o) throw new Error('no option ' + re + ' in ' + JSON.stringify(opts));
    await page.select(sel, o[0]);
}
module.exports = async (page, h, l, mode) => {
    await h.go('/deals/' + DEAL[l]);
    await h.lang(l);
    await h.shot('08-qt-new', l, h.tid('deal-detail-new-quotation'));
    await page.click(h.tid('deal-detail-new-quotation'));
    await page.waitForSelector(f('currency'));
    await h.sleep(800);
    // the clean header/supplier/line shots first (EGP, qty 10), nothing saved yet
    await selectByText(page, f('currency'), /EGP/);
    await setVal(page, f('default_margin'), '15');
    await setVal(page, f('discount_percent'), '5');
    await setVal(page, f('tax-0'), '14');
    await page.click(f('add-supplier'));
    await page.waitForSelector(f('supplier-0-pick'));
    await selectByText(page, f('supplier-0-pick'), new RegExp(SQ[l]));
    await page.waitForSelector(f('line-0-0-quantity'));
    await h.sleep(500);
    await page.type(f('line-0-0-quantity'), '10');
    await setVal(page, f('line-0-0-margin_percent'), '5');
    await h.shot('08b-qt-header', l, f('default_margin'));
    await h.shot('08c-qt-supplier', l, f('supplier-0-pick'));
    await h.shot('08f-qt-line-margin', l, f('line-0-0-margin_percent'));
    // FX refusal: EUR has no recorded rate
    await selectByText(page, f('currency'), /EUR/);
    await page.click(f('save'));
    await page.waitForNetworkIdle(); await h.sleep(800);
    const err = await page.$(f('form-error'));
    console.log('fx error:', err ? await err.evaluate((e) => e.innerText) : null);
    await h.shot('08e-qt-fx', l, err ? f('form-error') : f('currency'));
    // quantity warning: 12 > 10 recorded, saved as a draft
    await selectByText(page, f('currency'), /EGP/);
    await setVal(page, f('line-0-0-quantity'), '12');
    await page.click(f('save'));
    await page.waitForNetworkIdle(); await h.sleep(1200);
    const warn = await page.$(f('line-0-0-warning'));
    console.log('qty warning:', warn ? await warn.evaluate((e) => e.innerText) : null);
    if (warn) await h.shot('08d-qt-qty-warning', l, f('line-0-0-quantity'));
    console.log('saved link', await page.$eval(f('saved-link'), (a) => a.getAttribute('href')));
    if (mode === 'throwaway') {
        await page.click(f('saved-link')); await page.waitForSelector(h.tid('quotation-action-delete'));
        await page.click(h.tid('quotation-action-delete')); await h.sleep(800);
        await page.screenshot({ path: '/tmp/training/shots/_peek.png' });
    }
};
