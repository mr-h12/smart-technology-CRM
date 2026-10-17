// Lesson 5b: change status by hand (won → purchasing), and the refusal when another page moved it first.
const DEAL = { ar: '01a147e3-62d5-71e2-95ee-2cfccf90627a', en: '01a147e3-7adb-70fb-a841-e1808f5c2311' };
module.exports = async (page, h, l) => {
    const url = h.BASE + '/deals/' + DEAL[l];
    await page.goto(url, { waitUntil: 'networkidle0' });
    await h.lang(l);
    console.log('status', await h.text(h.tid('deal-detail-status')));
    await h.shot('06c-deal-status', l, h.tid('deal-status-next'));
    const b = await page.browser().newPage();
    await b.goto(url, { waitUntil: 'networkidle0' });
    await b.click(h.tid('deal-status-next')); await h.sleep(500);
    await b.click(h.tid('confirm-accept')); await b.waitForNetworkIdle(); await h.sleep(500);
    await b.close();
    await page.click(h.tid('deal-status-next')); await h.sleep(500);
    await h.shot('06d-deal-status-confirm', l, h.tid('confirm-accept'));
    await page.click(h.tid('confirm-accept')); await page.waitForNetworkIdle(); await h.sleep(800);
    const e = await page.$(h.tid('deal-status-error'));
    console.log('error:', e ? await e.evaluate((x) => x.innerText) : null);
    if (e) await h.shot('06e-deal-status-refused', l, h.tid('deal-status-error'));
    await page.click(h.tid('confirm-cancel')).catch(() => {});
    await page.goto(url, { waitUntil: 'networkidle0' });
    console.log('status now', await h.text(h.tid('deal-detail-status')));
};
