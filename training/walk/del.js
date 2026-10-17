// Deletes one demo draft quotation this walk created (soft delete through the UI's own action).
module.exports = async (page, h, q) => {
    await h.go('/quotations/' + q);
    await page.click(h.tid('quotation-action-delete')); await h.sleep(600);
    await page.click(h.tid('confirm-accept')); await page.waitForNetworkIdle(); await h.sleep(600);
    console.log('deleted', q, '→', page.url().replace(h.BASE, ''));
};
