// Click one quotation action (submit/send) on quotation <q>, confirming if asked. No shots.
module.exports = async (page, h, q, action) => {
    await h.go('/quotations/' + q);
    await page.click(h.tid('quotation-action-' + action)); await h.sleep(800);
    if (await page.$(h.tid('confirm-accept'))) await page.click(h.tid('confirm-accept'));
    await page.waitForNetworkIdle(); await h.sleep(800);
    console.log(await h.text(h.tid('quotation-detail-title')), '→', await h.text(h.tid('quotation-status')));
};
