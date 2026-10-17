// Lesson 8a: the saved draft, then submit for approval.
const Q = { ar: '01a147eb-3dfb-73e4-b50c-29597435fae5', en: '01a147ec-c21e-721e-b4df-b49bae4e7a9a' };
module.exports = async (page, h, l) => {
    await h.go('/quotations/' + Q[l]);
    await h.lang(l);
    await h.shot('08h-qt-detail', l, h.tid('quotation-detail-totals'));
    await h.shot('09-qt-submit', l, h.tid('quotation-action-submit'));
    await page.click(h.tid('quotation-action-submit'));
    await h.sleep(800);
    if (await page.$(h.tid('confirm-accept'))) { await h.shot('09b-qt-submit-confirm', l, h.tid('confirm-accept')); await page.click(h.tid('confirm-accept')); }
    await page.waitForNetworkIdle(); await h.sleep(1000);
    await h.shot('09c-qt-submitted', l, h.tid('quotation-status'));
    console.log('status', await h.text(h.tid('quotation-status')));
};
