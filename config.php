<?php
// إعدادات DR - Director
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'u486977009_dr',
        'user'    => 'u486977009_dr12',
        'pass'    => 't|CJPF&0U',
        'charset' => 'utf8mb4',
    ],

    // true = لو أي مصدر ذكر أن الدواء "ممنوع/Contraindicated/Category X" في الحمل،
    // لا يُعرض "آمن" أبداً حتى لو الأغلبية آمنة؛ تتحول النتيجة إلى "استشر الطبيب".
    // اجعلها false لو عايز النسبة فقط بدون أي استثناء.
    'strict_mode' => true,

    'cache_days'     => 0,   // 0 = السجل لا ينتهي أبداً، أو عدد الأيام قبل إعادة البحث تلقائياً
    'http_timeout'   => 10,  // ثواني
    'max_fda_labels' => 5,   // عدد نشرات FDA المستخدمة كأصوات
    'user_agent'     => 'Mozilla/5.0 (compatible; DR-Director/1.0)',

    // Lexicomp Online (online.lexi.com) كمصدر إضافي — يتطلب جلسة مسجّلة.
    // 'cookie' = قيمة الـ Cookie header كاملة من المتصفح (F12 → Network → أي طلب → Headers).
    // لو الجلسة انتهت حط enabled=false أو حدّث الـ cookie.
    'lexi' => [
        'enabled' => true,
        'base'    => 'https://online.lexi.com',
        'cookie'  => 'JSESSIONID=F2A7383BE0828A9AA5E0DA76EB8EBB27; remember-me-2=ZG1jZzI2OjE3OTQxMjc1MjU2Mjk6U0hBMjU2OmJlY2JkYTY1OGQ5YzY1MzcwN2YyOGMxYjA1NzkxZGJjY2ZkNDliZTg1NjViYTI1NjQ0MDkwZTkyMjdiOTdmYTI; appgw-affinity-257c4170eb96f1503acf999990b50132=52950c3b636ce8943468a3b0f2f61a2b; appgw-affinity-257c4170eb96f1503acf999990b50132CORS=52950c3b636ce8943468a3b0f2f61a2b; appgw-affinity-11333815ce7e07ac0afa46f40a894a4c=39948a6d5899e180fa2d911c3694d673; appgw-affinity-11333815ce7e07ac0afa46f40a894a4cCORS=39948a6d5899e180fa2d911c3694d673',
        'ua'      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.6.1 Safari/605.1.15',
    ],

    // RxList (rxlist.com) — المصدر شغال من IP عادي، لكن سيرفر Hostinger محظور (HTTP 403).
    // فعّله فقط لو غيّرت الاستضافة أو استخدمت proxy.
    'drugs' => [
        'enabled' => false,
    ],
];
