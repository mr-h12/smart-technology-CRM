// Shot the current page as <name> in <lang>, target <selector>.
module.exports = async (page, h, name, l, sel) => { await h.shot(name, l, sel); console.log(page.url()); };
