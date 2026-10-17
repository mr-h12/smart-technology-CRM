#!/usr/bin/env python3
"""Builds training/src/sales.{ar,en}.html from the captured shots + exact lang strings.

Every quoted message comes from Q(): the SPA locale JSON (crm/resources/js/locales) or the
PHP lang files exported to src/php-<lang>.json — never typed by hand.
usage: python3 training/build.py
"""
import html
import json
import os

ROOT = os.path.dirname(os.path.abspath(__file__))
CRM = os.path.join(ROOT, '..', 'crm')
UI = {l: json.load(open(os.path.join(CRM, 'resources/js/locales', l + '.json'), encoding='utf-8')) for l in ('ar', 'en')}
PHP = {l: json.load(open(os.path.join(ROOT, 'src', f'php-{l}.json'), encoding='utf-8')) for l in ('ar', 'en')}


def raw(lang, key):
    src, path = key.split(':', 1)
    node = (UI if src == 'ui' else PHP)[lang]
    for part in path.split('.'):
        node = node[part]
    assert isinstance(node, str), key
    return node


def Q(lang, key, cut=None):
    """An exact message, quoted. `cut` keeps the text before that separator (for messages with placeholders)."""
    s = raw(lang, key)
    if cut:
        s = s.split(cut)[0]
    return f'<span class="q">{html.escape(s)}</span>'


def U(lang, key):
    """A UI label (button/field name) exactly as the screen shows it."""
    return f'<span class="ui">{html.escape(raw(lang, key))}</span>'


def annotate(box, n):
    """Inline SVG over a 1280x800 shot: red pill around the box, arrow from the side with room, numbered badge."""
    if not box:
        return ''
    x, y, w, h = box['x'], box['y'], box['width'], box['height']
    pad = 9
    rx0, ry0, rw, rh = x - pad, y - pad, w + 2 * pad, h + 2 * pad
    r = min(rh / 2, 26)
    cx, cy = x + w / 2, y + h / 2
    left_room, right_room = rx0, 1280 - (rx0 + rw)
    up_room, down_room = ry0, 800 - (ry0 + rh)
    L = 125
    if w > 650 or max(left_room, right_room) < 170:
        # vertical arrow, from above or below
        sy = 1 if down_room > up_room else -1
        ex, ey = cx, (ry0 + rh + 6) if sy > 0 else (ry0 - 6)
        sx_, sy_ = ex + 60, ey + sy * L
    else:
        sx = 1 if right_room > left_room else -1
        ex = (rx0 + rw + 6) if sx > 0 else (rx0 - 6)
        ey = cy
        sx_ = ex + sx * L
        sy_ = ey + (-70 if cy > 140 else 70)
    sx_ = min(max(sx_, 28), 1252)
    sy_ = min(max(sy_, 28), 772)
    return f'''<svg viewBox="0 0 1280 800" preserveAspectRatio="none" aria-hidden="true">
  <defs><marker id="ah{n}" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="#e11d48"/></marker></defs>
  <rect x="{rx0:.1f}" y="{ry0:.1f}" width="{rw:.1f}" height="{rh:.1f}" rx="{r:.1f}" fill="none" stroke="#e11d48" stroke-width="4.5"/>
  <line x1="{sx_:.1f}" y1="{sy_:.1f}" x2="{ex:.1f}" y2="{ey:.1f}" stroke="#e11d48" stroke-width="5" stroke-linecap="round" marker-end="url(#ah{n})"/>
  <circle cx="{sx_:.1f}" cy="{sy_:.1f}" r="22" fill="#e11d48" stroke="#fff" stroke-width="3"/>
  <text x="{sx_:.1f}" y="{sy_ + 8:.1f}" text-anchor="middle" font-family="Inter" font-weight="700" font-size="23" fill="#fff">{n}</text>
</svg>'''


T = {
    'ar': {
        'title': 'دليل المبيعات', 'role': 'لـ «مبيعات داخلية» و«مبيعات خارجية»', 'pilot': 'نسخة التجربة (Pilot)',
        'date': 'التاريخ', 'system': 'سمارت تكنولوجي — نظام إدارة العملاء',
        'lesson': 'الدرس', 'step': 'خطوة', 'of': 'من', 'edge': 'لو حصل كده',
        'toc': 'المحتوى', 'story': 'الحكاية: أول يوم لمينا على النظام', 'qa': 'أسئلة بتتكرر',
        'storyIntro': 'مينا لسه منضمّ لتجربة النظام. فاهم شغل المبيعات كويس، بس أول مرة يفتح الـCRM. نور زميلة/زميل قديم في الفريق وبيشرح له يوم الشغل.',
        'see': 'ارجع للدرس', 'page': 'صفحة',
    },
    'en': {
        'title': 'Sales Guide', 'role': 'For Indoor Sales and Outdoor Sales', 'pilot': 'Pilot edition',
        'date': 'Date', 'system': 'Smart Technology — CRM',
        'lesson': 'Lesson', 'step': 'Step', 'of': 'of', 'edge': 'If this happens',
        'toc': 'Contents', 'story': "The story: Mina's first day on the CRM", 'qa': 'Common questions',
        'storyIntro': 'Mina has just joined the pilot. Mina knows sales well but has never opened the CRM. Nour, a senior colleague, walks through what a working day looks like.',
        'see': 'See lesson', 'page': 'Page',
    },
}

STORY = {
    'ar': [
        ('mina', 'صباح الفل يا نور أنا أول يوم على السيستم. أبدأ منين؟'),
        ('nour', 'صباح النور! أول حاجة تسجّل دخول بإيميلك وكلمة السر اللي الإدارة إديتهالك. ومفيش تسجيل لوحدك، الحسابات الإدارة هي اللي بتعملها.'),
        ('mina', 'تمام. ولو نسيت الباسورد وجربت كذا مرة؟'),
        ('nour', 'خلي بالك: بعد ٥ محاولات غلط ورا بعض الحساب بيتقفل ولازم تكلم الإدارة. ولو سبت الجهاز ٨ ساعات من غير شغل هيطلّعك وتدخل تاني.'),
        ('mina', 'طيب جالي عميل جديد عايز ١٠ لاب توب. أعمل إيه؟'),
        ('nour', 'تفتح «العملاء» وتضيفه. لو فيه اسم شبهه هيطلعلك تنبيه، بس العميل بيتحفظ عادي، إنت بس تتأكد إنه مش نفس العميل.'),
        ('mina', 'وبعدين الطلب نفسه؟'),
        ('nour', 'من صفحة العميل دوس «طلب جديد». ده بيبقى صفقة برقم DL. وبعدها تسجّل عرض المورّد (SQ) بالأسعار اللي جاتلك.'),
        ('mina', 'وعرض السعر للعميل؟ الحسابات بتتعمل إزاي؟'),
        ('nour', 'السيستم هو اللي بيحسب. إنت تختار العملة وهامش الربح والخصم والضريبة، وتضيف عرض المورّد. وتحفظ مسوّدة.'),
        ('mina', 'وأبعته للعميل على طول؟'),
        ('nour', 'لا الأول «إرسال للاعتماد» والمدير يعتمده. بعدها تعمل ملف PDF بلغة العميل وتدوس «إرسال إلى العميل».'),
        ('mina', 'ولما العميل يرد؟'),
        ('nour', 'تسجّل رده: مقبول برقم أمر الشراء وتاريخه، أو مقبول جزئيًا، أو عرض مقابل، أو مرفوض. وكل واحد ليه شروطه، هتشوفها في الدروس.'),
        ('mina', 'وفي آخر اليوم؟'),
        ('nour', 'تقريرك اليومي: متابعاتك وملاحظاتك وتختار مين يستلمه وتبعته. والأسبوعي السيستم بيعمله لوحده يوم الحد.'),
        ('mina', 'كده الصورة وضحت. يلا نبدأ خطوة خطوة!'),
    ],
    'en': [
        ('mina', "Morning, Nour! It's my first day on the system. Where do I start?"),
        ('nour', 'Sign in with the email and password administration gave you. There is no self sign-up; administration creates every account.'),
        ('mina', 'What if I forget my password and keep trying?'),
        ('nour', 'Careful: five wrong attempts in a row lock the account, and you will need administration. And if you leave it idle for eight hours, it signs you out.'),
        ('mina', 'A new customer wants ten laptops. What do I do?'),
        ('nour', 'Open Customers and add them. If a similar name exists you get a warning, but the record still saves. Just check it is not the same customer.'),
        ('mina', 'And the request itself?'),
        ('nour', 'From the customer page, click New request. That is a deal with a DL number. Then record the supplier offer (SQ) with the prices you received.'),
        ('mina', 'And the quotation for the customer? How are the numbers worked out?'),
        ('nour', 'The system calculates them. You choose the currency, margin, discount and tax, add the supplier offer, and save a draft.'),
        ('mina', 'Then I send it straight to the customer?'),
        ('nour', 'Not yet. First Submit for approval, and a Manager approves it. Then generate the PDF in the customer\'s language and click Send to the customer.'),
        ('mina', 'And when the customer replies?'),
        ('nour', 'Record the reply: accepted with their PO reference and date, partially accepted, a counter-offer, or rejected. Each has its own rules; the lessons show them.'),
        ('mina', 'And at the end of the day?'),
        ('nour', 'Your daily report: follow-ups, notes, who receives it, then submit. The weekly one the system creates on its own every Sunday.'),
        ('mina', "That makes sense. Let's go step by step."),
    ],
}


def lessons(l):
    a = l == 'ar'
    S = lambda ar, en: ar if a else en  # noqa: E731
    return [
        {'t': S('تسجيل الدخول', 'Signing in'), 'steps': [
            ('01-login', S(f'افتح رابط النظام. اكتب إيميلك في خانة {U(l,"ui:auth.login.email")} وكلمة السر، وبعدين دوس على {U(l,"ui:auth.login.submit")}. لو عايز تغيّر اللغة دوس AR أو EN فوق.',
                           f'Open the system link. Type your email in {U(l,"ui:auth.login.email")} and your password, then click {U(l,"ui:auth.login.submit")}. Switch language with AR / EN at the top of the card.'),
             [S('مفيش «إنشاء حساب»: الحساب بتعمله الإدارة.', 'There is no sign-up: administration creates accounts.')]),
            ('02-login-error', S('لو الإيميل أو كلمة السر غلط هيظهرلك المربع الأحمر ده. راجع الكتابة وجرّب تاني بهدوء.',
                                 'If the email or password is wrong, this red box appears. Check what you typed and try again calmly.'),
             [S(f'إيميل أو باسورد غلط: {Q(l,"php:identity.refusal.invalid_credentials")}', f'Wrong email or password: {Q(l,"php:identity.refusal.invalid_credentials")}'),
              S(f'بعد ٥ محاولات غلط ورا بعض: {Q(l,"ui:auth.error.accountLocked")} — كلّم الإدارة، وماتفضلش تجرّب.', f'After five wrong attempts in a row: {Q(l,"ui:auth.error.accountLocked")} Contact administration; do not keep trying.'),
              S(f'لو سبت السيستم ٨ ساعات من غير شغل هيطلّعك، وممكن تشوف: {Q(l,"ui:auth.error.sessionInvalid")} — ادخل تاني عادي.', f'After eight idle hours you are signed out and may see: {Q(l,"ui:auth.error.sessionInvalid")} Just sign in again.')]),
        ]},
        {'t': S('جولة في القائمة الجانبية', 'A tour of the sidebar'), 'steps': [
            ('03-sidebar', S(f'القائمة دي هي طريقك لكل حاجة: {U(l,"ui:nav.item.customers")}، {U(l,"ui:nav.item.supplierQuotations")}، {U(l,"ui:nav.item.deals")}، {U(l,"ui:nav.item.quotations")}، {U(l,"ui:nav.item.purchaseOrders")}، {U(l,"ui:nav.item.reports")}. بعد الدخول هتلاقي نفسك في {U(l,"ui:nav.item.customers")}. فوق هتلاقي اسمك، وزرار اللغة، و{U(l,"ui:user.signOut")}.',
                             f'The sidebar takes you everywhere: {U(l,"ui:nav.item.customers")}, {U(l,"ui:nav.item.supplierQuotations")}, {U(l,"ui:nav.item.deals")}, {U(l,"ui:nav.item.quotations")}, {U(l,"ui:nav.item.purchaseOrders")}, {U(l,"ui:nav.item.reports")}. After signing in you land on {U(l,"ui:nav.item.customers")}. The top bar shows your name, the language switch and {U(l,"ui:user.signOut")}.'),
             [S('لو مش لاقي شاشة في القائمة، يبقى دورك مالوش صلاحية عليها (شوف الدرس ١٤).', 'If a screen is missing from the menu, your role has no access to it (see lesson 14).')]),
        ]},
        {'t': S('عميل جديد', 'Adding a customer'), 'steps': [
            ('04-customer-form', S(f'من {U(l,"ui:nav.item.customers")} دوس على {U(l,"ui:customers.form.createTitle")}. اكتب {U(l,"ui:customers.form.name")} (ده الحقل الوحيد المطلوب) وكمّل الباقي لو معاك، وبعدين دوس {U(l,"ui:action.save")}.',
                                   f'In {U(l,"ui:nav.item.customers")} click {U(l,"ui:customers.form.createTitle")}. Type the {U(l,"ui:customers.form.name")} (the only required field), fill in what else you have, then click {U(l,"ui:action.save")}.'),
             [S('حالة العميل مش بتتكتب بإيدك: السيستم بيحسبها من صفقاته.', "A customer's status is not typed in: the system derives it from their deals.")]),
            ('05-customer-similar', S('لو فيه عميل اسمه قريب، هيظهرلك التنبيه ده فوق الفورم. العميل اتحفظ خلاص، بس دوس على الاسم اللي في التنبيه واتأكد إنه مش نفس العميل.',
                                      'If a similar name already exists, this warning appears at the top of the form. The customer is already saved; click the name in the warning to check it is not the same customer.'),
             [S(f'التنبيه: {Q(l,"ui:customers.form.similarTitle")} و{Q(l,"ui:customers.form.similarHint")}', f'The warning: {Q(l,"ui:customers.form.similarTitle")} and {Q(l,"ui:customers.form.similarHint")}'),
              S('لو طلع فعلاً نفس العميل، بلّغ مديرك. ماتعملش عميل تالت.', 'If it really is the same customer, tell your Manager. Do not add a third one.')]),
        ]},
        {'t': S('طلب جديد (صفقة DL)', 'A new request (deal, DL)'), 'steps': [
            ('06-deal-new', S(f'افتح صفحة العميل وانزل لـ {U(l,"ui:nav.item.deals")}، ودوس {U(l,"ui:customers.detail.newDeal")}. (وممكن كمان من شاشة {U(l,"ui:nav.item.deals")} بزرار {U(l,"ui:deals.form.createTitle")}.)',
                              f'Open the customer and scroll to {U(l,"ui:nav.item.deals")}, then click {U(l,"ui:customers.detail.newDeal")}. (Or use {U(l,"ui:deals.form.createTitle")} on the {U(l,"ui:nav.item.deals")} screen.)'),
             [S('العميل ممكن يبقى عنده كذا صفقة مفتوحة في نفس الوقت، عادي.', 'A customer may have several open deals at once; that is normal.')]),
            ('06b-deal-form', S(f'العميل بيبقى متحدد خلاص. اكتب {U(l,"ui:deals.column.title")} و{U(l,"ui:deals.column.details")}، واختار النوع والمصدر لو تعرفهم، ودوس {U(l,"ui:action.save")}. هتفتحلك صفحة الصفقة برقم DL.',
                                f'The customer is already filled in. Type the {U(l,"ui:deals.column.title")} and {U(l,"ui:deals.column.details")}, pick the type and source if you know them, and click {U(l,"ui:action.save")}. The deal page opens with its DL number.'),
             [S('الطلب اللي بتعمله بيظهر «بانتظار الاعتماد» لحد ما المدير يعتمده.', 'A request you create shows as pending approval until a Manager approves it.'),
              S('حالة الصفقة بتتحرك لوحدها مع عرض السعر ورد العميل. بإيدك بس بعد ما تكسبها (الدرس ١٠).', 'The deal status moves on its own with the quotation and the customer reply. By hand only after it is won (lesson 10).')]),
        ]},
        {'t': S('عرض المورّد (SQ)', 'The supplier offer (SQ)'), 'steps': [
            ('07-sq-new', S(f'من {U(l,"ui:nav.item.supplierQuotations")} دوس على {U(l,"ui:supplierQuotations.form.createTitle")}.', f'In {U(l,"ui:nav.item.supplierQuotations")} click {U(l,"ui:supplierQuotations.form.createTitle")}.'), []),
            ('07b-sq-header', S(f'اختار {U(l,"ui:supplierQuotations.column.supplier")}، واربطه بالصفقة (اكتب رقم DL)، واختار {U(l,"ui:supplierQuotations.form.currency")}. لو أسعار المورّد شاملة الضريبة علّم على {U(l,"ui:supplierQuotations.form.pricesIncludeTax")}.',
                                f'Pick the {U(l,"ui:supplierQuotations.column.supplier")}, link the deal (type its DL number) and choose the {U(l,"ui:supplierQuotations.form.currency")}. If the supplier\'s prices include tax, tick {U(l,"ui:supplierQuotations.form.pricesIncludeTax")}.'),
             [S('العرض ممكن يتسجّل من غير صفقة، بس ربطه بالصفقة بيسهّل عرض السعر بعدين.', 'An offer can be saved without a deal, but linking it makes the quotation easier later.')]),
            ('07c-sq-line', S(f'دوس {U(l,"ui:supplierQuotations.form.lineAdd")}، واكتب اسم المنتج أو اختاره من الكتالوج، و{U(l,"ui:supplierQuotations.form.linePrice")} و{U(l,"ui:supplierQuotations.form.lineQuantity")}. كرّر لكل بند.',
                              f'Click {U(l,"ui:supplierQuotations.form.lineAdd")}, type the product name or pick it from the catalog, then the {U(l,"ui:supplierQuotations.form.linePrice")} and {U(l,"ui:supplierQuotations.form.lineQuantity")}. Repeat for every line.'), []),
            ('07e-sq-tax-error', S('لو علّمت «السعر شامل الضريبة» لازم تكتب نسبة الضريبة. لو نسيتها ودوست حفظ، هتلاقي الخانة دي حمرا.',
                                   'If prices include tax, you must type the tax rate. Forget it and click Save, and this field turns red.'),
             [S(f'الرسالة: {Q(l,"php:supplier_quotations.errors.no_tax_rate")}', f'The message: {Q(l,"php:supplier_quotations.errors.no_tax_rate")}'),
              S('ممكن تضيف أكتر من نسبة بزرار «+».', 'Add more than one rate with the "+" button.')]),
            ('07d-sq-save-first', S(f'المرفق (صورة أو PDF العرض) مش بيتضاف قبل الحفظ. دوس {U(l,"ui:action.save")} الأول.',
                                    f'The attachment (scan or PDF of the offer) cannot be added before saving. Click {U(l,"ui:action.save")} first.'),
             [S(f'هتشوف: {Q(l,"ui:supplierQuotations.form.attachmentsSaveFirst")}', f'You will see: {Q(l,"ui:supplierQuotations.form.attachmentsSaveFirst")}')]),
            ('07f-sq-list', S('العرض اتحفظ وخد رقم SQ. عشان ترفق الملف دوس على «تعديل» في صفه.', 'The offer is saved with an SQ number. To attach the file, click Edit on its row.'), []),
            ('07g-sq-attach', S(f'دلوقتي ظهر زرار {U(l,"ui:files.choose")}. اختار الملف وهيترفع لوحده.', f'Now the {U(l,"ui:files.choose")} button is there. Pick the file and it uploads by itself.'),
             [S(f'الملفات المسموحة: {Q(l,"ui:files.hint")}', f'Allowed files: {Q(l,"ui:files.hint")}')]),
        ]},
        {'t': S('عرض السعر (QT)', 'The quotation (QT)'), 'steps': [
            ('08-qt-new', S(f'من صفحة الصفقة دوس على {U(l,"ui:deals.detail.newQuotation")}.', f'On the deal page click {U(l,"ui:deals.detail.newQuotation")}.'), []),
            ('08b-qt-header', S(f'اختار {U(l,"ui:quotations.builder.currency")}، واكتب {U(l,"ui:quotations.builder.default_margin")} و{U(l,"ui:quotations.builder.discount_percent")}، والضريبة لو فيه. الخصم بيتخصم قبل الضريبة.',
                                f'Choose the {U(l,"ui:quotations.builder.currency")}, type the {U(l,"ui:quotations.builder.default_margin")} and {U(l,"ui:quotations.builder.discount_percent")}, and tax if any. The discount comes off before tax.'),
             [S('سعر الصرف بيتثبّت لحظة إنشاء العرض، وأي تغيير بعد كده مايأثرش عليه.', 'The exchange rate is fixed when the quotation is created; later rate changes do not touch it.'),
              S('سيب خانة الضريبة فاضية = من غير ضريبة (ومفيش سطر ضريبة في الـPDF).', 'Leave tax empty = no tax (and no tax line in the PDF).')]),
            ('08c-qt-supplier', S(f'دوس {U(l,"ui:quotations.builder.addSupplier")} واختار عرض المورّد (SQ) من القائمة. هتظهر بنوده تحت.',
                                  f'Click {U(l,"ui:quotations.builder.addSupplier")} and pick the supplier offer (SQ) from the list. Its lines appear below.'),
             [S(f'مفيش عرض؟ دوس {U(l,"ui:quotations.builder.newSupplierQuotation")} وسجّله من هنا.', f'No offer yet? Click {U(l,"ui:quotations.builder.newSupplierQuotation")} and record it here.'),
              S(f'لو بند المورّد مالوش سعر صالح، الحفظ بيترفض: {Q(l,"php:quotations.errors.supplier_price_missing")}', f'If a supplier line has no usable price, saving is refused: {Q(l,"php:quotations.errors.supplier_price_missing")}')]),
            ('08f-qt-line-margin', S(f'لكل بند اكتب {U(l,"ui:quotations.detail.quantity")}. ولو عايز هامش زيادة على بند معيّن اكتبه في الخانة دي؛ بيتجمع على هامش العرض.',
                                     f'Type the {U(l,"ui:quotations.detail.quantity")} for each line. For extra margin on one line, type it in this field; it is added to the quotation margin.'), []),
            ('08d-qt-qty-warning', S(f'دوس {U(l,"ui:quotations.builder.save")}. لو الكمية أكبر من المتاح عند المورّد هيظهرلك تنبيه أحمر تحت البند. ده تنبيه بس: العرض اتحفظ.',
                                     f'Click {U(l,"ui:quotations.builder.save")}. If the quantity is more than the supplier has left, a red warning appears under the line. It is only a warning: the quotation is saved.'),
             [S(f'التنبيه: {Q(l,"php:quotations.warnings.quantity_exceeds_recorded")}', f'The warning: {Q(l,"php:quotations.warnings.quantity_exceeds_recorded")}'),
              S(f'لو سعر المورّد اتغيّر بعد ما عملت العرض: {Q(l,"php:quotations.warnings.supplier_price_changed")}', f'If the supplier price changed after you built it: {Q(l,"php:quotations.warnings.supplier_price_changed")}')]),
            ('08e-qt-fx', S('لو اخترت عملة مالهاش سعر صرف مسجّل من عملة المورّد، الحفظ بيترفض والرسالة دي بتظهر فوق.',
                            "If you choose a currency with no recorded rate from the supplier's currency, saving is refused and this message appears at the top."),
             [S(f'الرسالة: {Q(l,"php:quotations.errors.fx_rate_missing")} — كلّم المدير يسجّل السعر، أو اختار عملة تانية.', f'The message: {Q(l,"php:quotations.errors.fx_rate_missing")} Ask a Manager to record the rate, or choose another currency.')]),
            ('08g-qt-409', S(f'لو حد تاني عدّل نفس العرض وإنت فاتحه، حفظك هيترفض وهيظهرلك المربع ده. دوس {U(l,"ui:quotations.detail.reload")} وعيد تعديلك على النسخة الجديدة.',
                             f'If someone else edited the same quotation while you had it open, your save is refused and this box appears. Click {U(l,"ui:quotations.detail.reload")} and redo your edit on their version.'),
             [S(f'أول الرسالة: {Q(l,"ui:quotations.builder.conflict", " — ")} — تعديلاتك اللي ماتحفظتش بتضيع، فاكتبها تاني.', f'It starts: {Q(l,"ui:quotations.builder.conflict", " — ")} Your unsaved changes are discarded, so type them again.')]),
            ('08h-qt-detail', S('بعد الحفظ افتح العرض: الإجماليات كلها محسوبة من السيستم (المجموع، الخصم، وعاء الضريبة، الضريبة، الإجمالي).',
                                'After saving, open the quotation: every total is calculated by the system (subtotal, discount, tax base, tax, final total).'),
             [S('البنود الإضافية (توصيل، تركيب) مابتتحسبش عليها ضريبة.', 'Additional items (delivery, installation) are never taxed.')]),
        ]},
        {'t': S('الاعتماد والإرسال للعميل', 'Approval and sending'), 'steps': [
            ('09-qt-submit', S(f'العرض لسه {U(l,"ui:quotations.status.draft")}. دوس {U(l,"ui:quotations.detail.submit")}.', f'The quotation is still a {U(l,"ui:quotations.status.draft")}. Click {U(l,"ui:quotations.detail.submit")}.'), []),
            ('09c-qt-submitted', S(f'الحالة بقت {U(l,"ui:quotations.status.pending")}. المدير بيعتمده من شاشة الاعتمادات، أو يرجّعهولك بملاحظة تعدّل وتبعت تاني.',
                                   f'The status is now {U(l,"ui:quotations.status.pending")}. A Manager approves it from the approvals screen, or returns it with a note for you to fix and resubmit.'), []),
            ('10-qt-send', S(f'بعد الاعتماد هيظهر زرار {U(l,"ui:quotations.detail.send")}. اعمل ملف الـPDF الأول (الدرس ٨) وبعدين دوس عليه.',
                             f'Once approved, {U(l,"ui:quotations.detail.send")} appears. Generate the PDF first (lesson 8), then click it.'),
             [S(f'لو الصفقة لسه ماوصلتش لمرحلة عرض المورّد: {Q(l,"php:quotations.errors.deal_not_ready_to_send")}', f'If the deal has not reached the supplier offer stage: {Q(l,"php:quotations.errors.deal_not_ready_to_send")}')]),
            ('10c-qt-sent', S(f'الحالة بقت {U(l,"ui:quotations.status.sent")}، والصفقة اتنقلت لوحدها. دلوقتي مستني رد العميل.', f'The status is {U(l,"ui:quotations.status.sent")} and the deal moved on by itself. Now wait for the customer.'), []),
        ]},
        {'t': S('ملف الـPDF', 'The PDF'), 'steps': [
            ('11-pdf-language', S(f'في {U(l,"ui:pdf.panel.title")} اختار {U(l,"ui:pdf.panel.language")}: العربي أو English، على حسب العميل.', f'In {U(l,"ui:pdf.panel.title")} choose the {U(l,"ui:pdf.panel.language")}: Arabic or English, to suit the customer.'), []),
            ('11b-pdf-generate', S(f'دوس {U(l,"ui:pdf.panel.generate")}. الملف بيتعمل في الخلفية، استنى ثواني.', f'Click {U(l,"ui:pdf.panel.generate")}. It is built in the background; wait a few seconds.'),
             [S('الـPDF عمره ما بيظهر فيه اسم المورّد ولا سعره ولا التكلفة ولا الهامش.', 'The PDF never shows the supplier name, supplier prices, cost or margin.')]),
            ('11c-pdf-download', S(f'لما تظهر {Q(l,"ui:pdf.panel.ready")} دوس {U(l,"ui:pdf.panel.download")} أو {U(l,"ui:files.view")}.', f'When {Q(l,"ui:pdf.panel.ready")} shows, click {U(l,"ui:pdf.panel.download")} or {U(l,"ui:files.view")}.'), []),
        ]},
        {'t': S('تسجيل رد العميل', "Recording the customer's reply"), 'steps': [
            ('12-reply-open', S(f'على العرض المُرسَل دوس {U(l,"ui:quotations.detail.respond")}.', f'On the sent quotation click {U(l,"ui:quotations.detail.respond")}.'),
             [S(f'لو العرض {U(l,"ui:quotations.status.expired")}، الاختيار الوحيد اللي هيظهرلك هو «{raw(l,"ui:quotations.status.rejected")}».', f'If the quotation is {U(l,"ui:quotations.status.expired")}, the only choice offered is "{raw(l,"ui:quotations.status.rejected")}".')]),
            ('12-reply-accepted-empty', S(f'العميل وافق؟ اختار {U(l,"ui:quotations.status.accepted")}. لازم تكتب {U(l,"ui:quotations.detail.poReference")} و{U(l,"ui:quotations.detail.poDate")}.',
                                          f'Customer agreed? Choose {U(l,"ui:quotations.status.accepted")}. You must enter the {U(l,"ui:quotations.detail.poReference")} and {U(l,"ui:quotations.detail.poDate")}.'),
             [S(f'من غيرهم الزرار مقفول: {Q(l,"ui:quotations.detail.recordNeedsPo")}', f'Without them the button stays disabled: {Q(l,"ui:quotations.detail.recordNeedsPo")}')]),
            ('12-reply-accepted', S(f'دوس {U(l,"ui:quotations.detail.respondRecord")}. السيستم بيعمل أمر شراء (PO) لوحده والصفقة بتبقى ناجحة.',
                                    f'Click {U(l,"ui:quotations.detail.respondRecord")}. The system creates the purchase order (PO) and the deal becomes Won.'), []),
            ('12-reply-partial', S(f'وافق على جزء بس؟ اختار {U(l,"ui:quotations.status.partial")} ودوس {U(l,"ui:quotations.detail.respondRecord")}.', f'Agreed to part of it? Choose {U(l,"ui:quotations.status.partial")} and click {U(l,"ui:quotations.detail.respondRecord")}.'), []),
            ('12-reply-partial-result', S('السيستم بيعمل نسخة جديدة كاملة من العرض وبيفتحهالك تعدّلها. النسخة القديمة بتفضل محفوظة زي ما هي. عدّل واحفظ وابعت للاعتماد تاني.',
                                          'The system makes a full new version and opens it for editing. The old version stays exactly as it was. Edit, save and submit for approval again.'), []),
            ('12-reply-counter', S(f'عرض مقابل؟ اختار {U(l,"ui:quotations.status.counter")} واكتب السبب. برضه بيعمل نسخة جديدة.', f'A counter-offer? Choose {U(l,"ui:quotations.status.counter")} and type the reason. It also makes a new version.'),
             [S(f'من غير سبب الزرار مقفول: {Q(l,"ui:quotations.detail.recordNeedsReason")}', f'Without a reason the button stays disabled: {Q(l,"ui:quotations.detail.recordNeedsReason")}')]),
            ('12-reply-rejected', S(f'رفض؟ اختار {U(l,"ui:quotations.status.rejected")} واكتب السبب.', f'Rejected? Choose {U(l,"ui:quotations.status.rejected")} and type the reason.'),
             [S(f'السبب إجباري: {Q(l,"ui:quotations.detail.recordNeedsReason")}', f'The reason is required: {Q(l,"ui:quotations.detail.recordNeedsReason")}'),
              S(f'لو مفيش عروض تانية مفتوحة على الصفقة هتبقى خاسرة: {Q(l,"ui:quotations.detail.dealLost")}', f'If the deal has no other open quotation it becomes Lost: {Q(l,"ui:quotations.detail.dealLost")}')]),
        ]},
        {'t': S('تحريك الصفقة بعد الكسب', 'Moving a won deal on'), 'steps': [
            ('06c-deal-status', S(f'بعد ما الصفقة تبقى {U(l,"ui:deals.status.won")}، بإيدك بس تنقلها: شراء ← توريد ← اكتمل التوريد. على صفحة الصفقة دوس على الزرار اللي بيقول الخطوة الجاية.',
                                  f'Once a deal is {U(l,"ui:deals.status.won")}, only these moves are by hand: Purchasing → Delivery → Delivery complete. On the deal page click the button naming the next step.'),
             [S('«اكتمل التوريد» للمبيعات الداخلية بس.', '"Delivery complete" is for Indoor Sales only.')]),
            ('06d-deal-status-confirm', S(f'هيسألك تأكيد. دوس {U(l,"ui:deals.status.changeAction")}. التغيير ده مالوش رجوع.', f'You are asked to confirm. Click {U(l,"ui:deals.status.changeAction")}. There is no way back.'), []),
            ('06e-deal-status-refused', S('لو حد تاني حرّك الصفقة وإنت فاتح الصفحة، التغيير بيترفض. اقفل الرسالة وحدّث الصفحة.',
                                          'If someone moved the deal while your page was open, the change is refused. Close the box and reload the page.'),
             [S(f'الرسالة: {Q(l,"ui:deals.status.notAllowed")}', f'The message: {Q(l,"ui:deals.status.notAllowed")}')]),
        ]},
        {'t': S('أوامر الشراء', 'Purchase orders'), 'steps': [
            ('13-po-search', S(f'من {U(l,"ui:nav.item.purchaseOrders")} اكتب في البحث رقم PO بتاعنا أو رقم أمر الشراء عند العميل، ودوس Enter.',
                               f'In {U(l,"ui:nav.item.purchaseOrders")} type our PO number or the customer\'s own PO reference in the search box and press Enter.'),
             [S('أوامر الشراء بتتعمل لوحدها لما تسجّل «مقبول» — مفيش زرار إضافة هنا.', 'POs are created when you record "Accepted"; there is no add button here.')]),
        ]},
        {'t': S('التقرير اليومي', 'The daily report'), 'steps': [
            ('14-report-new', S(f'من {U(l,"ui:nav.item.reports")} دوس {U(l,"ui:reports.new_daily")}. النشاط بتاع اليوم (صفقات، عروض، ردود، أوامر شراء) بيتملي لوحده.',
                                f'In {U(l,"ui:nav.item.reports")} click {U(l,"ui:reports.new_daily")}. The day\'s activity (deals, quotations, replies, POs) fills in by itself.'), []),
            ('14b-report-follow-up', S(f'دوس {U(l,"ui:reports.follow_up_add")}، اختار العميل واكتب المتابعة. بتتحفظ لوحدها وإنت بتكتب.', f'Click {U(l,"ui:reports.follow_up_add")}, pick the customer and write the follow-up. It saves as you type.'),
             [S(f'متابعة من غير نص: {Q(l,"ui:reports.text_required")}', f'A follow-up with no text: {Q(l,"ui:reports.text_required")}')]),
            ('14c-report-note', S(f'عايز تكتب ملاحظة عامة؟ دوس {U(l,"ui:reports.note_add")}.', f'For a general note click {U(l,"ui:reports.note_add")}.'), []),
            ('14d-report-recipients', S(f'في {U(l,"ui:reports.recipients_title")} علّم على اللي هيستلم التقرير (عادة مديرك).', f'In {U(l,"ui:reports.recipients_title")} tick who receives the report (usually your Manager).'),
             [S('من غير مستلم زرار الإرسال بيفضل مقفول.', 'With no recipient the submit button stays disabled.')]),
            ('14e-report-submit', S(f'دوس {U(l,"ui:reports.submit")}.', f'Click {U(l,"ui:reports.submit")}.'),
             [S(f'لو التقرير اتغيّر من مكان تاني: {Q(l,"ui:reports.conflict")}', f'If the report changed elsewhere: {Q(l,"ui:reports.conflict")}')]),
            ('14f-report-sent', S(f'الحالة بقت {U(l,"ui:reports.status.submitted")}.', f'The status is now {U(l,"ui:reports.status.submitted")}.'),
             [S(f'لو اليوم عدّى من غير تقرير هيظهر {Q(l,"ui:reports.status.missed")}، ولو بعته بعد الميعاد هيتعلّم {Q(l,"ui:reports.late")}.', f'A day with no report shows {Q(l,"ui:reports.status.missed")}; one sent after the deadline is marked {Q(l,"ui:reports.late")}.'),
              S('لو المستلم رجّعلك التقرير بسبب، عدّله وابعته تاني.', 'If the recipient returns it with a reason, fix it and submit again.')]),
        ]},
        {'t': S('التقرير الأسبوعي', 'The weekly report'), 'steps': [
            ('15-weekly-list', S('مش محتاج تعمله: السيستم بيعمل التقرير الأسبوعي لوحده كل يوم حد الساعة 1:00 بتوقيت UTC، وبيظهر في قايمة تقاريرك.',
                                 'You do not create it: the system creates the weekly report every Sunday at 01:00 UTC, and it appears in your list.'), []),
            ('15b-weekly-detail', S('افتحه وراجعه: فيه تقاريرك اليومية للأسبوع، وأوامر الشراء، والعملاء الجداد. ابعته للمستلم.',
                                    "Open and review it: it holds the week's daily reports, POs received and new customers. Then submit it."),
             [S(f'لو ماراجعتهوش خلال 24 ساعة، السيستم بيبعته لوحده ويتعلّم {Q(l,"ui:reports.not_reviewed")}.', f'If you do not review it within 24 hours, the system submits it and marks it {Q(l,"ui:reports.not_reviewed")}.')]),
        ]},
        {'t': S('مفيش صلاحية، وقوائم فاضية', 'No access, and empty lists'), 'steps': [
            ('16-forbidden', S(f'لو فتحت شاشة مش من صلاحيتك هتظهرلك الصفحة دي. دوس {U(l,"ui:state.denied.home")} وارجع لشغلك.',
                               f'Open a screen your role cannot use and you get this page. Click {U(l,"ui:state.denied.home")} to go back.'),
             [S(f'الرسالة: {Q(l,"ui:state.denied.message")}', f'The message: {Q(l,"ui:state.denied.message")}')]),
            ('14-empty', S(f'لو البحث أو الفلتر مارجّعش حاجة هتشوف {Q(l,"ui:customers.empty.filtered.title")}. امسح خانة البحث أو وسّع الفلاتر.',
                           f'If a search or filter finds nothing you see {Q(l,"ui:customers.empty.filtered.title")}. Clear the search box or widen the filters.'), []),
        ]},
    ]


def qa(l):
    a = l == 'ar'
    S = lambda ar, en: ar if a else en  # noqa: E731
    return [
        (S('عرض السعر مش راضي يتحفظ', 'My quotation will not save'), S('شوف الرسالة اللي فوق: سعر صرف ناقص، أو بند مورّد من غير سعر، أو حد عدّله قبلك.', 'Read the message at the top: a missing exchange rate, a supplier line with no price, or someone edited it first.'), 6),
        (S('طلعني من السيستم', 'I was signed out'), S('بعد ٨ ساعات من غير شغل بيطلّعك. ادخل تاني.', 'Eight idle hours sign you out. Sign in again.'), 1),
        (S('الحساب اتقفل', 'My account is locked'), S('٥ محاولات غلط. كلّم الإدارة، ماتجرّبش تاني.', 'Five wrong attempts. Contact administration; do not keep trying.'), 1),
        (S('تقريري مكتوب عليه «فائت»', 'My report says "Missed"'), S('اليوم عدّى من غير تقرير. بلّغ مديرك.', 'The day passed with no report. Tell your Manager.'), 12),
        (S('مش لاقي زرار «إرسال إلى العميل»', 'I cannot find "Send to the customer"'), S('العرض لسه مستني الاعتماد أو اترجعلك.', 'The quotation is still waiting for approval, or it was returned.'), 7),
        (S('زرار «تسجيل الرد» مقفول', '"Record the response" is disabled'), S('ناقص رقم أمر الشراء وتاريخه، أو السبب.', 'The PO reference and date, or the reason, are missing.'), 9),
        (S('مش عارف أرفق ملف للعرض', 'I cannot attach a file to an offer'), S('احفظ عرض المورّد الأول، وبعدين «تعديل».', 'Save the supplier offer first, then Edit.'), 5),
        (S('ظهرلي تنبيه الكمية', 'I got a quantity warning'), S('تنبيه بس، العرض اتحفظ. راجع الكمية مع المورّد.', 'Only a warning; the quotation is saved. Check the quantity with the supplier.'), 6),
        (S('تنبيه «عميل مشابه»', 'A "similar customer" warning'), S('العميل اتحفظ. اتأكد إنه مش مكرّر.', 'The customer is saved. Make sure it is not a duplicate.'), 3),
        (S('الصفقة رفضت تتحرك', 'The deal will not move'), S('حد حرّكها قبلك. حدّث الصفحة.', 'Someone moved it first. Reload the page.'), 10),
        (S('العرض منتهي والعميل رد', 'The quotation expired and the customer replied'), S('مش هيظهر غير «مرفوض». اعمل نسخة جديدة لو هتكمّل.', 'Only "Rejected" is offered. Make a new version to carry on.'), 9),
        (S('صفحة «لا تملك صلاحية الوصول»', 'A "You do not have access" page'), S('الشاشة مش لدورك. ارجع للرئيسية.', 'The screen is not for your role. Go back home.'), 14),
    ]


def page(inner, lang, cls='page', foot=True, n=None):
    f = f'<div class="foot"><span>{T[lang]["system"]} · {T[lang]["title"]}</span><span>{n or ""}</span></div>' if foot else ''
    return f'<section class="{cls}">{inner}{f}</section>\n'


def build(lang):
    t = T[lang]
    L = lessons(lang)
    pages = []
    # cover
    pages.append(page(f'''<span class="tag">{t["pilot"]}</span>
<h1>{t["title"]}<br><span style="font-size:20pt;color:#c2410c">{t["system"]}</span></h1>
<p class="role">{t["role"]}</p>
<p class="meta">{t["date"]}: <b><bdi dir="ltr">2026-10-17</bdi></b></p>''', lang, 'page cover', foot=False))
    # contents
    toc = ''.join(f'<li><span>{i}</span>{x["t"]}</li>' for i, x in enumerate(L, 1))
    toc += f'<li><span>?</span>{t["qa"]}</li>'
    pages.append(page(f'<div class="lesson-head"><h2>{t["toc"]}</h2></div><ol class="toc"><li><span>★</span>{t["story"]}</li>{toc}</ol>', lang, n=2))
    # story (two pages)
    names = {'mina': 'Mina' if lang == 'en' else 'مينا', 'nour': 'Nour' if lang == 'en' else 'نور'}
    msgs = STORY[lang]
    for part, chunk in enumerate((msgs[:9], msgs[9:])):
        bubbles = ''.join(f'<div class="msg {w}"><div class="who">{names[w][0]}</div><div class="bubble"><span class="name">{names[w]}</span>{html.escape(m)}</div></div>' for w, m in chunk)
        intro = f'<p class="intro">{t["storyIntro"]}</p>' if part == 0 else ''
        pages.append(page(f'<div class="lesson-head"><span class="num">★</span><h2>{t["story"]}</h2><span class="step">{part + 1}/2</span></div>{intro}<div class="chat">{bubbles}</div>', lang, n=3 + part))
    # lessons
    pn = 5
    for i, lesson in enumerate(L, 1):
        for j, (shot, text, edges) in enumerate(lesson['steps'], 1):
            meta = json.load(open(os.path.join(ROOT, 'shots', f'{shot}.{lang}.json')))
            callout = ''
            if edges:
                callout = f'<div class="callout"><h3>⚠ {t["edge"]}</h3><ul>{"".join(f"<li>{e}</li>" for e in edges)}</ul></div>'
            inner = f'''<div class="lesson-head"><span class="num">{i}</span><h2>{lesson["t"]}</h2><span class="step">{t["step"]} {j} {t["of"]} {len(lesson["steps"])}</span></div>
<div class="body"><div class="shot"><img src="../shots/{shot}.{lang}.png" alt="">{annotate(meta.get("box"), j)}</div>
<div class="text"><p class="do"><b>{j}.</b> {text}</p>{callout}</div></div>'''
            pages.append(page(inner, lang, n=pn))
            pn += 1
    # Q&A
    items = ''.join(f'<div><b>{q}</b>{a} <span class="ui">→ {t["see"]} {n}</span></div>' for q, a, n in qa(lang))
    pages.append(page(f'<div class="lesson-head"><span class="num">?</span><h2>{t["qa"]}</h2></div><div class="qa">{items}</div>', lang, n=pn))
    # outdoor note
    note = {
        'ar': '<h2>للمبيعات الخارجية</h2><p>الدليل ده هو نفسه بالظبط للمبيعات الخارجية: نفس الشاشات ونفس الخطوات.</p><p>الفرق الوحيد دلوقتي: <b>مفيش «اكتمل التوريد»</b> (الدرس ١٠) — ده للمبيعات الداخلية بس.</p><p>تسجيل <b>الزيارات</b> الميدانية هييجي في إصدار جاي، ومش موجود في التجربة دي.</p>',
        'en': '<h2>For Outdoor Sales</h2><p>This guide is the same for Outdoor Sales: the same screens and the same steps.</p><p>The only difference today: <b>no "Delivery complete"</b> (lesson 10) — that move belongs to Indoor Sales.</p><p>Recording field <b>visits</b> comes in a later release and is not part of this pilot.</p>',
    }[lang]
    pages.append(page(f'<div class="note-box">{note}</div>', lang, n=pn + 1))
    dirattr = 'rtl' if lang == 'ar' else 'ltr'
    doc = f'''<!doctype html>
<html lang="{lang}" dir="{dirattr}"><head><meta charset="utf-8"><title>{t["title"]}</title>
<link rel="stylesheet" href="guide.css"></head>
<body dir="{dirattr}">
{''.join(pages)}</body></html>'''
    open(os.path.join(ROOT, 'src', f'sales.{lang}.html'), 'w', encoding='utf-8').write(doc)
    print(lang, len(pages), 'pages')


if __name__ == '__main__':
    for lg in ('ar', 'en'):
        build(lg)
