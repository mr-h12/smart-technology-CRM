<?php

declare(strict_types=1);

return [
    // `OpenAPI §5.1` — one 404 for absent or out of reach (Point 3.5).
    'not_found' => 'لم يُعثر على عرض السعر هذا.',

    // `422 business_rule_blocked` — منع §5.6، وجاره الذي يفرضه `D-09`.
    // مفاتيحه هي `QuotationNotPriceable::$reason`؛ أكّد المالك
    // `fx_rate_missing` ككود مستقل في 2026-09-11.
    'errors' => [
        'supplier_price_missing' => 'لا يوجد سعر صالح لبند المورّد هذا، لذا لا يمكن حفظ عرض السعر.',
        'fx_rate_missing' => 'لا يوجد سعر صرف مسجَّل لتحويل بند المورّد هذا. سجِّل سعر الصرف أولًا.',
    ],

    // تحذير §5.6 — يُحمل في `meta.warnings` عند إنشاء ناجح.
    'warnings' => [
        'quantity_exceeds_recorded' => 'الكمية المطلوبة تتجاوز ما سجّله المورّد.',
    ],

    // `422 validation_failed` تصدره حالة الاستخدام لا قاعدة تحقق.
    'validation' => [
        'unknown_deal' => 'لم يُعثر على هذه الصفقة.',
        'customer_not_the_deals' => 'يجب أن يكون العميل هو عميل الصفقة.',
    ],
];
