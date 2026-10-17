// Lesson 6: supplier offer (SQ) — supplier, deal, prices incl. tax, currency, a line, save, attachment after save.
const DEAL = { ar: 'DL-2026-0020', en: 'DL-2026-0021' };
const LINE = { ar: ['لاب توب تجريبي 14 بوصة', '25000', '10'], en: ['Demo 14-inch laptop', '25000', '10'] };
async function pick(page, h, id, text) {
    await page.click(h.tid(id));
    await page.type(h.tid(id), text);
    await page.waitForSelector(h.tid(id + '-option'));
    await h.sleep(500);
    await page.click(h.tid(id + '-option'));
    await h.sleep(300);
}
module.exports = async (page, h, l, mode) => {
    await h.go('/supplier-quotations');
    await h.lang(l);
    await pick(page, h, 'supplier-quotations-filter-supplier', 'Demo Supplier');
    await page.click(h.tid('supplier-quotations-filter-apply'));
    await page.waitForNetworkIdle();
    await h.shot('07-sq-new', l, h.tid('supplier-quotations-create'));
    await page.click(h.tid('supplier-quotations-create'));
    await page.waitForSelector(h.tid('supplier-quotation-form-supplier-id'));
    await pick(page, h, 'supplier-quotation-form-supplier-id', 'Demo Supplier');
    await pick(page, h, 'supplier-quotation-form-deal-id', DEAL[l]);
    await page.click(h.tid('supplier-quotation-form-prices-include-tax'));
    const cur = await page.$$eval(h.tid('supplier-quotation-form-currency-id') + ' option', (o) => o.map((x) => [x.value, x.textContent.trim()]));
    console.log('currencies', JSON.stringify(cur));
    const egp = cur.find((c) => /EGP|جنيه/.test(c[1]));
    await page.select(h.tid('supplier-quotation-form-currency-id'), egp[0]);
    await h.shot('07b-sq-header', l, h.tid('supplier-quotation-form-prices-include-tax'));
    await page.click(h.tid('supplier-quotation-form-add-line'));
    await page.waitForSelector(h.tid('supplier-quotation-line-0-product-name'));
    await page.type(h.tid('supplier-quotation-line-0-product-name'), LINE[l][0]);
    await page.type(h.tid('supplier-quotation-line-0-unit-price'), LINE[l][1]);
    await page.type(h.tid('supplier-quotation-line-0-quantity'), LINE[l][2]);
    await h.shot('07c-sq-line', l, h.tid('supplier-quotation-form-add-line'));
    await h.shot('07d-sq-save-first', l, h.tid('supplier-quotation-form-attachments-save-first'));
    if (mode === 'dry') return;
    await page.click(h.tid('supplier-quotation-form-save'));
    await page.waitForSelector(h.tid('supplier-quotation-form-tax-error'));
    await h.shot('07e-sq-tax-error', l, h.tid('supplier-quotation-form-tax-0'));
    await page.type(h.tid('supplier-quotation-form-tax-0'), '14');
    await page.click(h.tid('supplier-quotation-form-save'));
    await page.waitForNetworkIdle();
    await h.sleep(1000);
    await page.screenshot({ path: '/tmp/training/shots/_peek.png' });
    const ids = await page.$$eval('[data-testid]', (els) => els.filter((e) => e.offsetParent !== null).map((e) => e.dataset.testid));
    console.log([...new Set(ids)].join(' '));
};
