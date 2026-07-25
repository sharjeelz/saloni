<?php

// نصوص الرسائل النصية / واتساب للعملاء وأصحاب الصالون. يحدّد إعداد لغة الصالون
// (locale) أي ملف يُستخدم، لذا حافظ على تطابق المفاتيح بين اللغتين.
return [
    // العميل
    'confirmation' => ':salon: تم تأكيد حجزك لخدمة :service مع :staff — :when. رقم الحجز :ref.',
    'reschedule'   => ':salon: تم تغيير موعدك لخدمة :service مع :staff إلى :when. رقم الحجز :ref.',
    'cancelled'    => ':salon: نعتذر، تم إلغاء موعدك لخدمة :service بتاريخ :when. يرجى التواصل معنا لإعادة الحجز.',
    'reminder'     => 'تذكير — :salon: لديك موعد :service مع :staff — :when.',

    // صاحب الصالون
    'owner_new'        => 'حجز جديد: :customer — :service مع :staff — :when.',
    'owner_cancelled'  => 'ألغى العميل الحجز: :customer — :service مع :staff — :when.',
    'owner_reschedule' => 'غيّر العميل موعده: :customer — :service مع :staff — الآن :when.',

    // الحساب
    'otp'          => 'رمز التحقق الخاص بك هو :code. ينتهي خلال :minutes دقائق.',
    'staff_invite' => 'تمت إضافتك إلى :salon. سجّل الدخول برقم جوالك للبدء.',
];
