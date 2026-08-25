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
        'invalid_verification_code' => 'رمز التحقق غير صالح. اطلب رمزاً جديداً وحاول مرة أخرى.',
        'expired_verification_code' => 'انتهت صلاحية رمز التحقق. اطلب رمزاً جديداً وحاول مرة أخرى.',
    ],

    'administration' => [
        'role_not_assignable' => 'لا يمكنك إسناد هذا الدور.',
        'user_not_found' => 'لا يوجد مستخدم بهذا المعرّف.',
        'email_already_taken' => 'هذا البريد الإلكتروني مرتبط بحساب قائم بالفعل.',
        'password_policy_not_met' => 'يجب ألا تقل كلمة المرور عن 8 أحرف وأن تحتوي على حروف وأرقام.',
        'role_not_found' => 'هذا الدور غير موجود.',
        'no_fields_submitted' => 'أدخل حقلاً واحداً على الأقل للتعديل.',
    ],

    'impersonation' => [
        'impersonation_forbidden' => 'لا يجوز الدخول باسم مستخدم آخر إلا لمسؤول النظام.',
        'target_not_found' => 'لا يوجد مستخدم بهذا المعرّف.',
        'target_suspended' => 'هذا الحساب معطَّل ولا يمكن استخدامه.',
        'target_is_self' => 'أنت مسجَّل الدخول بهذا الحساب بالفعل.',
        'already_impersonating' => 'أنهِ الجلسة الحالية قبل بدء جلسة أخرى.',
        'not_impersonating' => 'هذه الجلسة ليست جلسة دخول باسم مستخدم آخر.',
    ],

    'role_administration' => [
        // §3.11 and §3.12 rules 3 and 5. None of these names a role: a refusal
        // that explained itself would describe the permission matrix, which is
        // the thing the caller was just told they may not read.
        'role_not_found' => 'هذا الدور غير موجود.',
        'role_is_immutable' => 'صلاحيات هذا الدور ثابتة في النظام ولا يمكن تغييرها.',
        'permission_not_found' => 'إحدى الصلاحيات المُرسَلة غير موجودة.',
        'grant_forbidden' => 'لا يمكن منح هذه الصلاحية لأي دور.',
        'permission_ids_required' => 'أرسل permission_ids، واستخدم قائمة فارغة لسحب كل الصلاحيات.',
    ],

    'session' => [
        // SEC-05. Neither message names another account: `session_not_found`
        // answers the same way for an id that never existed, one that belongs
        // to somebody else, and a Login As row §3.1 hides.
        'session_not_found' => 'هذا الجهاز غير مسجَّل الدخول.',
        'session_is_current' => 'هذا هو الجهاز الذي تستخدمه الآن. سجّل الخروج لإنهاء هذه الجلسة.',
    ],

    'list_query' => [
        'above_maximum' => 'لا يمكن أن تتجاوز قيمة per_page مئة.',
        'not_a_positive_integer' => 'يجب أن تكون هذه القيمة عدداً صحيحاً أكبر من صفر.',
        'unknown_sort_field' => 'لا يدعم هذا المورد الترتيب بهذا الحقل.',
        'too_many_sort_fields' => 'يقبل هذا المورد حقل ترتيب واحداً فقط.',
        'unknown_filter' => 'لا يدعم هذا المورد هذا المرشِّح.',
        'not_a_boolean' => 'يقبل هذا المرشِّح القيمتين true أو false فقط.',
        'not_a_role_slug' => 'هذا ليس معرّف دور صالحاً.',
    ],

    'errors' => [
        'validation_failed' => 'يرجى تصحيح الحقول المحددة.',
        'rate_limited' => 'عدد كبير جدا من المحاولات. يرجى المحاولة بعد قليل.',
        'invalid_request' => 'تعذر فهم الطلب.',
        'resource_not_found' => 'المورد المطلوب غير موجود.',
    ],

    'challenge_mail' => [
        'subject' => 'رمز التحقق لتغيير كلمة المرور',
        'greeting' => 'مرحباً :name،',
        'intro' => 'استخدم هذا الرمز لتأكيد تغيير كلمة المرور:',
        'expires' => 'ينتهي عمل الرمز في :until.',
        'ignore' => 'إن لم تطلب تغيير كلمة المرور، تجاهل هذه الرسالة وأبلغ الإدارة.',
    ],

    'lockout_mail' => [
        'subject' => 'تم قفل أحد حسابات النظام',
        'intro' => 'تم قفل حساب :name (:email) بعد عدد كبير من محاولات الدخول الفاشلة.',
        'until' => 'ينتهي القفل في :until.',
        'origin' => 'جاءت آخر محاولة من :ip.',
        'unknown_ip' => 'عنوان غير معروف',
    ],

];
