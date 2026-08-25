<?php

/*
 * سِجِلُّ الأصولِ الثابتة.
 *
 * حالاتُ سَيْرِ عملِ الصيانةِ هنا، يقرؤها `AssetMaintenance::statuses()`. أمّا
 * الأولوياتُ فتقرأُ من نطاقِ `essentials` عن قصد — انظر النسخةَ الإنجليزية.
 *
 * المفرداتُ هنا بسيطةٌ عن قصد: قارئُ هذه الشاشاتِ أمينُ مخزنٍ أو فنِّيٌّ، لا
 * محاسب. فنقولُ «سُلِّم إلى» لا «تاريخُ التخصيص»، و«ما العطل؟» لا «وصفُ الخلل».
 * وحيثُ يحملُ اللفظُ معنًى محاسبيًّا يخالفُ معناهُ المتداول — فـ`current_value`
 * قيمةٌ دفتريةٌ بعدَ الإهلاكِ بالقسطِ الثابت، و`acquisition_cost` هو ما دُفِع
 * فعلًا — يوضِّحُ التلميحُ المصاحبُ ذلك، بدلًا من أن يتصنَّعَهُ العنوانُ نفسه.
 */

return [

    /* --- الشاشات --- */
    'assets' => 'الأصول',
    'assets_subtitle' => 'ما تملكُه من عُدَدٍ وأجهزة، وقيمتُه، ومَن يحتفظُ به',
    'asset' => 'الأصل',
    'add_asset' => 'إضافةُ أصل',
    'edit_asset' => 'تعديلُ الأصل',
    'back_to_asset' => 'رجوعٌ إلى الأصل',
    'maintenance' => 'الصيانة',
    'maintenance_subtitle' => 'أعمالُ الإصلاحِ والصيانةِ المفتوحةُ على الأصول',
    'all_maintenance_jobs' => 'كلُّ أعمالِ هذا الأصل',
    'open_asset' => 'فتحُ الأصل',
    'raise_job' => 'فتحُ عملِ صيانة',
    'edit_job' => 'تعديلُ عملِ الصيانة',

    /* --- نموذجُ الأصل --- */
    'asset_details' => 'بياناتُ الأصل',
    'asset_details_hint' => 'ما هو، وأينَ يُوجَد، وبكَمْ كان',
    'asset_name_placeholder' => 'مثال: سيارةُ توصيل، طابعةُ الكاونتر',
    'asset_code' => 'كودُ الأصل',
    'asset_code_hint' => 'اتركْهُ فارغًا ليُولَّدَ تلقائيًّا',
    'asset_location_hint' => 'الفرعُ الذي يحتفظُ به، لا الشخصُ الذي يستخدمُه',
    'model' => 'الطِّراز',
    'serial_no' => 'الرقمُ التسلسلي',
    'quantity_hint' => 'عددُ الوحداتِ المتماثلةِ التي يشملُها هذا السجل',
    'quantity_floor_hint' => 'لا يمكنُ أن تقلَّ عن :allocated — هذا القدرُ مُسلَّمٌ الآن',
    'unit_price' => 'سعرُ الوحدة',
    'unit_price_hint' => 'ما كلَّفتْهُ الوحدةُ الواحدةُ عندَ الشراء',
    'is_allocatable' => 'قابلٌ للتسليم',
    'is_allocatable_hint' => 'أوقفْهُ لِما لا يخرجُ من الفرعِ أبدًا',
    'is_allocatable_locked' => 'لا يمكنُ إيقافُه ووحداتٌ ما تزالُ خارجًا',

    'acquisition' => 'الاقتناء',
    'acquisition_hint' => 'كيفَ اشتُرِي، وكيفَ يفقدُ قيمتَه',
    'purchase_date' => 'تاريخُ الشراء',
    'purchase_date_hint' => 'يُحسَبُ الإهلاكُ من هذا التاريخ',
    'purchase_type' => 'نوعُ الشراء',
    'purchase_type_new' => 'جديد',
    'purchase_type_used' => 'مُستعمَل',
    'purchase_type_refurbished' => 'مُجدَّد',
    'purchase_type_leased' => 'مُستأجَر',
    'depreciation_rate' => 'الإهلاكُ (% سنويًّا)',
    'depreciation_rate_hint' => 'اتركْهُ صفرًا لِما يحفظُ قيمتَه',
    'depreciation_note' => 'بالقسطِ الثابت: يُحمَّلُ المقدارُ نفسُه كلَّ سنة، ولا تهبطُ القيمةُ عن الصفرِ أبدًا.',

    /* --- السِّجل --- */
    'total_assets' => 'الأصول',
    'acquisition_cost' => 'كُلفةُ الاقتناء',
    'before_depreciation' => 'قبلَ الإهلاك',
    'allocated_out' => 'مُسلَّمٌ خارجًا',
    'across_n_assets' => 'على :count أصلًا',
    'open_maintenance' => 'أعمالٌ مفتوحة',
    'search_assets_placeholder' => 'اسمٌ أو كودٌ أو طِرازٌ أو رقمٌ تسلسلي…',
    'allocation_state' => 'الإتاحة',
    'state_allocated' => 'شيءٌ منهُ خارجًا',
    'state_available' => 'متاحٌ للتسليم',
    'current_value' => 'القيمةُ الحالية',
    'depreciating_at' => ':rate% سنويًّا',
    'n_out' => ':qty خارجًا',
    'not_allocatable' => 'لا يخرج',
    'fully_allocated' => 'كلُّه خارجًا',
    'partly_allocated' => 'بعضُه خارجًا',
    'available' => 'متاح',
    'in_warranty' => 'تحتَ الضمان',
    'no_assets_yet' => 'لا أصولَ مُسجَّلةً بعد',
    'no_assets_yet_desc' => 'أضِفْ ما تملكُه من عُدَدٍ وأجهزةٍ لتتابعَ قيمتَه ومَن يحتفظُ به.',

    /* --- شاشةُ الأصل --- */
    'owned_quantity' => 'المملوك',
    'available_quantity' => 'المتاح',
    'acquisition_was' => 'كُلفتُه :amount',

    /* --- التسليمُ والاستعادة --- */
    'allocate' => 'تسليم',
    'allocate_asset' => 'تسليمُ الأصل',
    'allocate_hint' => 'سجِّلْ مَن يأخذُه ومتى يُعادُ',
    'receiver' => 'سُلِّمَ إلى',
    'available_is' => ':qty متاح',
    'due_back' => 'موعدُ الإعادة',
    'due_back_hint' => 'اتركْهُ فارغًا إن لم يكنْ للإعادةِ موعد',
    'reason' => 'السبب',
    'reason_placeholder' => 'مثال: زيارةُ موقع، بديلٌ حتى يُصلَحَ الآخر',
    'handed_over_on' => 'تاريخُ التسليم',
    'defaults_to_today' => 'الافتراضيُّ هو الآن',
    'nothing_available' => 'لا شيءَ متاحٌ للتسليم',
    'nothing_available_desc' => 'كلُّ الوحداتِ خارجًا. استعِدْ واحدةً، أو ارفعِ الكميةَ على الأصل.',
    'allocation_history' => 'مَن يحتفظُ به',
    'outstanding' => 'ما زالَ خارجًا',
    'return_asset' => 'استعادة',
    'quantity_to_return' => 'الكميةُ المُعادة',
    'all' => 'الكل',
    'due' => 'يُعادُ في',
    'returned' => 'أُعيد',
    'overdue' => 'متأخِّرٌ عن موعدِه',
    'partly_returned' => 'أُعيدَ بعضُه',
    'out' => 'خارجًا',
    'never_allocated' => 'لم يُسلَّمْ قَطّ',
    'never_allocated_desc' => 'لم يخرجْ منهُ شيءٌ من الفرعِ بعد.',
    'allocated_successfully' => 'تمَّ التسليم.',
    'returned_successfully' => 'تمَّتِ الاستعادة.',

    /* --- الضمان --- */
    'warranty' => 'الضمان',
    'warranty_from' => 'التغطيةُ من',
    'warranty_to' => 'التغطيةُ إلى',
    'warranty_cost' => 'كُلفةٌ إضافية',
    'warranty_cost_hint' => 'ما كلَّفَهُ تمديدُ الضمان، إن كان',
    'add_warranty' => 'إضافةُ تغطية',
    'expired' => 'منتهية',
    'no_warranty' => 'لا تغطيةَ مُسجَّلة',
    'no_warranty_desc' => 'أضِفْ تواريخَ الضمانِ حتى لا يمضيَ انتهاؤهُ دونَ أن يُلاحَظ.',

    /* --- الصيانة --- */
    'job_details' => 'العمل',
    'job_details_hint' => 'ما العطل، وما مدى إلحاحِه، وأينَ وصَل',
    'asset_fixed_hint' => 'لا يمكنُ تغييرُ الأصل — أصلٌ آخرُ يعني عملًا آخر',
    'job_ref_hint' => 'اتركْهُ فارغًا ليُولَّدَ تلقائيًّا',
    'what_is_wrong' => 'ما العطل؟',
    'what_is_wrong_placeholder' => 'مثال: لا يُشغَّلُ بعدَ العاصفة',
    'work_note' => 'ملاحظةُ العمل',
    'work_note_hint' => 'ما أُنجِز، أو ما يبقى مطلوبًا',
    'work_note_placeholder' => 'مثال: استُبدِلَ مصدرُ الطاقة، في انتظارِ مروحة',
    'assignment' => 'الإسناد',
    'assignment_hint' => 'مَن يُنفِّذُه',
    'assigned_to' => 'مُسنَدٌ إلى',
    'assigned_to_hint' => 'اتركْهُ فارغًا حتى يتولَّاهُ أحد',
    'raised_by' => 'فتحَهُ :name',
    'total_jobs' => 'كلُّ الأعمال',
    'search_jobs_placeholder' => 'مرجعٌ أو أصلٌ أو عطل…',
    'no_jobs_yet' => 'لا أعمالَ صيانة',
    'no_jobs_yet_desc' => 'افتحْ عملًا عندما يحتاجُ شيءٌ إلى إصلاحٍ أو صيانة.',
    'no_maintenance' => 'لا أعمالَ على هذا الأصل',
    'no_maintenance_desc' => 'لم يُفتَحْ عليهِ شيءٌ بعد.',
    'open' => 'مفتوح',
    'closed' => 'مُغلَق',

    /* --- حالاتُ الصيانة، يقرؤها AssetMaintenance::statuses() --- */
    'scheduled' => 'مجدولة',
    'in_progress' => 'قيد التنفيذ',
    'completed' => 'مكتملة',
    'cancelled' => 'ملغاة',

    /* --- الرفض --- *
     |
     | كلُّ رسالةٍ منها تقولُ ما يمنعُ التعديلَ *و* ما يرفعُ المانع. والرسالةُ التي
     | تقولُ «لا» وتسكتُ تدفعُ القارئَ إلى البحثِ عن خلل.
     */
    'quantity_below_allocated' => 'لا يمكنُ أن تقلَّ عن :allocated — هذا القدرُ مُسلَّمٌ الآن. استعِدْ بعضَه أوَّلًا.',
    'cannot_disable_allocation' => 'وحداتٌ ما تزالُ خارجًا. استعِدْها قبلَ إيقافِ التسليم، وإلَّا لم يبقَ سبيلٌ لإغلاقِ التسليمِ القائم.',
    'cannot_delete_allocated' => 'وحداتٌ ما تزالُ خارجًا. استعِدْها قبلَ حذفِ الأصل.',
    'asset_not_allocatable' => 'هذا الأصلُ مُعلَّمٌ بأنَّهُ لا يخرج، فلا يمكنُ تسليمُه.',
    'quantity_exceeds_available' => 'المتاحُ للتسليمِ :available فقط.',
    'not_an_allocation' => 'هذا السجلُّ ليسَ تسليمًا، فليسَ هناكَ ما يُستعاد.',
    'already_returned' => 'كلُّ ما في هذا التسليمِ أُعيدَ فعلًا.',
    'quantity_exceeds_outstanding' => 'الباقي خارجًا على هذا التسليمِ :outstanding فقط.',
];
