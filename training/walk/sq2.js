// Lesson 6 tail: the saved SQ in the list, then edit → attachment field now available.
async function pick(page, h, id, text) {
    await page.click(h.tid(id)); await page.type(h.tid(id), text);
    await page.waitForSelector(h.tid(id + '-option')); await h.sleep(500);
    await page.click(h.tid(id + '-option')); await h.sleep(300);
}
module.exports = async (page, h, l) => {
    await h.go('/supplier-quotations');
    await h.lang(l);
    await pick(page, h, 'supplier-quotations-filter-supplier', 'Demo Supplier');
    await page.click(h.tid('supplier-quotations-filter-apply'));
    await page.waitForNetworkIdle();
    console.log('rows:', await page.$$eval(h.tid('supplier-quotations-row'), (r) => r.map((x) => x.innerText.replace(/\s+/g, ' '))));
    await h.shot('07f-sq-list', l, h.tid('supplier-quotations-row-edit'));
    await page.click(h.tid('supplier-quotations-row-edit'));
    await page.waitForSelector(h.tid('supplier-quotation-form-attachment-file'));
    await h.sleep(800);
    await h.shot('07g-sq-attach', l, await page.$eval(h.tid('supplier-quotation-form-attachment-file'), (e) => e.closest('label')).then(() => page.evaluateHandle((s) => document.querySelector(s).closest('label'), h.tid('supplier-quotation-form-attachment-file'))));
    await page.click(h.tid('supplier-quotation-form-cancel'));
};
