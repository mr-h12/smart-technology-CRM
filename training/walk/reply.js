// Lesson 11: customer reply. mode 'partial' → counter/rejected/accepted forms shown, then partial recorded.
// mode 'accept' → accepted with PO reference and date, recorded.
const r = (x) => `[data-testid="quotation-respond-${x}"]`;
module.exports = async (page, h, l, q, mode) => {
    await h.go('/quotations/' + q);
    await h.lang(l);
    if (mode === 'partial') await h.shot('12-reply-open', l, h.tid('quotation-action-respond'));
    await page.click(h.tid('quotation-action-respond'));
    await page.waitForSelector(r('dialog'));
    await h.sleep(500);
    if (mode === 'partial') {
        for (const o of ['counter', 'rejected']) {
            await page.click(r('outcome-' + o)); await h.sleep(400);
            console.log(o, 'record disabled:', await page.$eval(r('record'), (b) => b.disabled), 'hint:', await h.text(r('hint')).catch(() => null));
            await h.shot('12-reply-' + o, l, r('reason'));
        }
        await page.click(r('outcome-partial')); await h.sleep(400);
        await h.shot('12-reply-partial', l, await h.label(r('outcome-partial')));
        await page.click(r('record'));
        await page.waitForNetworkIdle(); await h.sleep(1500);
        await h.shot('12-reply-partial-result', l, 'h1');
        console.log('now at', page.url());
        return;
    }
    await page.click(r('outcome-partial')); await h.sleep(400);
    await h.shot('12-reply-partial', l, await h.label(r('outcome-partial')));
    await page.click(r('outcome-accepted')); await h.sleep(400);
    console.log('accepted record disabled:', await page.$eval(r('record'), (b) => b.disabled), 'hint:', await h.text(r('hint')).catch(() => null));
    await h.shot('12-reply-accepted-empty', l, r('po-reference'));
    await page.type(r('po-reference'), l === 'ar' ? 'PO-DEMO-AR-001' : 'PO-DEMO-EN-001');
    await page.type(r('po-date'), '10/17/2026');
    await h.shot('12-reply-accepted', l, r('record'));
    await page.click(r('record'));
    await page.waitForNetworkIdle(); await h.sleep(1500);
    console.log('status', await h.text(h.tid('quotation-status')), page.url());
    await page.screenshot({ path: '/tmp/training/shots/_peek.png' });
};
