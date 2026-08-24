<?php

declare(strict_types=1);

return [

    'refusal' => [
        'invalid_credentials' => 'بيانات الاعتماد هذه غير متطابقة مع البيانات المسجلة لدينا.',
        // §10.1 gives this message in English only; the master documentation
        // has no Arabic counterpart (D-58 retired the twin requirement). This
        // wording was supplied by the owner with Point 2.2.
        'account_suspended' => 'الحساب معطل، يرجى مراجعة الإدارة.',
        'account_locked' => 'تم قفل هذا الحساب بعد عدد كبير من محاولات الدخول الفاشلة. يرجى مراجعة الإدارة.',
        'session_invalid' => 'انتهت صلاحية جلستك. يرجى تسجيل الدخول من جديد.',
        'permission_denied' => 'ليس لديك صلاحية للقيام بهذا الإجراء.',
        'unauthorized_action' => 'الإجراء :ability غير مسموح به لدورك.',
    ],

    'password' => [
        'current_password_incorrect' => 'كلمة المرور الحالية غير صحيحة.',
        'password_policy_not_met' => 'يجب أن تتكون كلمة المرور من 8 خانات على الأقل وأن تحتوي على حروف وأرقام.',
        'password_unchanged' => 'يجب أن تختلف كلمة المرور الجديدة عن الحالية.',
    ],

    'errors' => [
        'validation_failed' => 'يرجى تصحيح الحقول المحددة.',
        'rate_limited' => 'عدد كبير جدا من المحاولات. يرجى المحاولة بعد قليل.',
    ],

    'lockout_mail' => [
        'subject' => 'تم قفل أحد حسابات النظام',
        'intro' => 'تم قفل حساب :name (:email) بعد عدد كبير من محاولات الدخول الفاشلة.',
        'until' => 'ينتهي القفل في :until.',
        'origin' => 'جاءت آخر محاولة من :ip.',
        'unknown_ip' => 'عنوان غير معروف',
    ],

];
