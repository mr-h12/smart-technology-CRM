module.exports = async (page, h) => {
    await page.click(h.tid('confirm-accept'));
    await page.waitForNetworkIdle(); await h.sleep(800);
    console.log('url', page.url());
};
