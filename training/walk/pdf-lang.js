module.exports = async (page, h, l, q) => {
    await h.go('/quotations/' + q); await h.lang(l);
    await page.click(h.tid('pdf-language-' + l));
    await h.shot('11-pdf-language', l, await h.label(h.tid('pdf-language-' + l)));
};
