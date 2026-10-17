// Lessons 9–10: PDF (language → generate → download), then send to the customer.
const Q = { ar: '01a147eb-3dfb-73e4-b50c-29597435fae5', en: '01a147ec-c21e-721e-b4df-b49bae4e7a9a' };
module.exports = async (page, h, l) => {
    await h.go('/quotations/' + Q[l]);
    await h.lang(l);
    await page.click(h.tid('pdf-language-' + l));
    await h.shot('11-pdf-language', l, await h.label(h.tid('pdf-language-' + l)));
    await h.shot('11b-pdf-generate', l, h.tid('pdf-generate'));
    await page.click(h.tid('pdf-generate'));
    await page.waitForSelector(h.tid('pdf-download'), { timeout: 90000 });
    await h.sleep(500);
    await h.shot('11c-pdf-download', l, h.tid('pdf-download'));
    console.log('pdf status:', await h.text(h.tid('pdf-status')));
    await h.shot('10-qt-send', l, h.tid('quotation-action-send'));
    await page.click(h.tid('quotation-action-send')); await h.sleep(800);
    if (await page.$(h.tid('confirm-accept'))) { await h.shot('10b-qt-send-confirm', l, h.tid('confirm-accept')); await page.click(h.tid('confirm-accept')); }
    await page.waitForNetworkIdle(); await h.sleep(1000);
    console.log('status', await h.text(h.tid('quotation-status')));
    await h.shot('10c-qt-sent', l, h.tid('quotation-status'));
    const ids = await page.$$eval('[data-testid]', (els) => els.filter((e) => e.offsetParent !== null).map((e) => e.dataset.testid));
    console.log(ids.filter((x) => x.startsWith('quotation-action')).join(' '));
};
