// Creates the one demo supplier (no lesson; Sales may add suppliers under catalog.manage).
module.exports = async (page, h) => {
    await h.go('/suppliers');
    await page.click(h.tid('suppliers-create'));
    await page.waitForSelector(h.tid('supplier-form-name'));
    await page.type(h.tid('supplier-form-name'), 'مورّد تجريبي — Demo Supplier');
    await page.click(h.tid('supplier-form-save'));
    await page.waitForNetworkIdle();
    await h.sleep(800);
    const errs = await page.$$eval('[role="alert"], [data-testid$="-error"]', (e) => e.map((x) => x.innerText).filter(Boolean));
    console.log('errors:', errs.join(' | '), 'form open:', !!(await page.$(h.tid('supplier-form'))));
    await page.screenshot({ path: '/tmp/training/shots/_peek.png' });
};
