<?php

/*
 * The one place that defines the subscription plans. The pricing page, the
 * Langganan screen, the limit checks and the artisan commands all read this.
 * A limit of null means no limit. Prices are Rupiah, flat per team.
 */
return [
    'trial_days' => 14,
    // While on trial an agency gets the limits of this plan.
    'trial_plan' => 'tim',
    // Days an overdue agency keeps working (past_due) before it turns read-only.
    'grace_days' => 3,
    // A trial that ends goes to read-only after this many days (0 = at once, until a plan is chosen).
    'trial_grace_days' => (int) env('TRIAL_GRACE_DAYS', 0),
    // The reminder email goes out this many days before a trial or period ends.
    'reminder_days' => 3,

    'plans' => [
        'mandiri' => [
            'name' => 'Agen Mandiri',
            'tagline' => 'Untuk agen yang bekerja sendiri',
            'popular' => false,
            'price' => ['monthly' => 79000, 'yearly' => 790000],
            'limits' => ['users' => 1, 'leads' => 300, 'wa' => 1, 'ai' => 300, 'followups' => 2, 'landing_pages' => 1],
            'features' => ['pixels' => false, 'reports' => false],
        ],
        'tim' => [
            'name' => 'Tim Kecil',
            'tagline' => 'Untuk tim penjualan kecil',
            'popular' => true,
            'price' => ['monthly' => 199000, 'yearly' => 1990000],
            'limits' => ['users' => 5, 'leads' => 2000, 'wa' => 2, 'ai' => 1500, 'followups' => null, 'landing_pages' => 5],
            'features' => ['pixels' => true, 'reports' => true],
        ],
        'agensi' => [
            'name' => 'Agensi',
            'tagline' => 'Untuk agensi dengan banyak agen',
            'popular' => false,
            'price' => ['monthly' => 449000, 'yearly' => 4490000],
            'limits' => ['users' => 15, 'leads' => 10000, 'wa' => 4, 'ai' => 5000, 'followups' => null, 'landing_pages' => null],
            'features' => ['pixels' => true, 'reports' => true],
        ],
    ],

    // Add-ons, bought per month. "unit" is how much one purchased quantity adds to the limit.
    'addons' => [
        'ai' => ['label' => 'Balasan AI tambahan', 'unit' => 1000, 'unit_label' => '+1.000 balasan AI', 'price' => 30000, 'limit' => 'ai'],
        'user' => ['label' => 'Pengguna tambahan', 'unit' => 1, 'unit_label' => '+1 pengguna', 'price' => 25000, 'limit' => 'users'],
        'wa' => ['label' => 'Nomor WhatsApp tambahan', 'unit' => 1, 'unit_label' => '+1 nomor WhatsApp', 'price' => 30000, 'limit' => 'wa'],
    ],

    'limit_labels' => [
        'users' => 'Pengguna',
        'leads' => 'Lead aktif',
        'wa' => 'Nomor WhatsApp',
        'ai' => 'Balasan AI periode ini',
        'followups' => 'Aturan follow-up',
        'landing_pages' => 'Halaman landing',
    ],

    'feature_labels' => [
        'pixels' => 'Pixel iklan (Meta, TikTok, Google)',
        'reports' => 'Laporan lengkap',
    ],
];
