<?php

// Draft Arabic translation (Phase 6): to be reviewed by a native speaker before production.

return [

    'errors' => [
        'invoice_not_found' => 'هذه الفاتورة غير موجودة أو لا يحق لك رؤيتها.',
        'plan_not_found' => 'هذه الخطة غير موجودة.',
        'not_payable' => 'لا يمكن تعليم فاتورة كمدفوعة إلا إذا كانت غير مدفوعة.',
        'not_creditable' => 'لا يمكن إصدار إشعار دائن لهذا المستند: فهو إشعار دائن أو تم إلغاؤه بالكامل.',
        'credit_too_large' => 'يجب أن يكون الإشعار الدائن بين 1 و:remaining (:currency، قبل الضريبة)، وهو المتبقي على هذه الفاتورة.',
        'nothing_to_pay' => 'لا توجد عمولة مستحقة الدفع بعملة :currency.',
        'role_not_allowed' => 'لا يطّلع على الفوترة إلا المالك وفريق الفوترة.',
        'top_level_only' => 'الفوترة تابعة لـ :organization. اسأل من يديرها.',
        'duplicate_price' => 'يوجد سعر بالفعل لهذه العملة وهذه المدة.',
    ],

    'messages' => [
        'plan_created' => 'تم إنشاء الخطة ":name".',
        'plan_updated' => 'تم حفظ الخطة ":name".',
        'plan_archived' => 'تمت أرشفة الخطة ":name". يحتفظ بها العملاء المشتركون فيها بالفعل.',
    ],

    // Frozen on invoices when issued.
    'lines' => [
        'wholesale_client' => ':client: خطة :plan، :month',
        'wholesale_seats' => ':client: خطة :plan، مستخدمو الفريق، :month',
        'subscription_monthly' => 'خطة :plan، شهريًا، من :from إلى :to',
        'subscription_yearly' => 'خطة :plan، سنويًا، من :from إلى :to',
        'credit' => 'إشعار دائن للفاتورة :number',
    ],

    // Billing run report (operators).
    'run' => [
        'no_wholesale_price' => 'لا يوجد سعر جملة لـ :client على الخطة :plan بعملة :currency',
        'no_client_price' => 'لا يوجد لـ :client سعر بعملة :currency لكل :period؛ حدّد سعرًا في خطته',
    ],
];
