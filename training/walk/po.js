// Lesson 12: Purchase Orders — search by the customer's PO reference.
module.exports = async (page, h, l) => {
    await h.go('/purchase-orders');
    await h.lang(l);
    await page.type(h.tid('purchase-orders-search'), 'PO-DEMO');
    await page.keyboard.press('Enter');
    await page.waitForNetworkIdle(); await h.sleep(1200);
    console.log(await page.$$eval(h.tid('purchase-orders-row'), (r) => r.map((x) => x.innerText.replace(/\s+/g, ' '))));
    await h.shot('13-po-search', l, h.tid('purchase-orders-search'));
};
