<?php

return [
    'demo_seed' => env('BAKERY_DEMO_SEED', false),
    'defaults' => [
        'name' => 'Tiệm Bánh Nhà Mình',
        'logo' => null,
        'phone' => '',
        'email' => '',
        'address' => '',
        'hours' => '08:00 – 19:00',
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'slots' => ['09:00', '11:00', '14:00', '16:00', '18:00'],
        'closed_dates' => [],
        'regions' => [],
        'free_from' => null,
        'bank_name' => '',
        'bank_account' => '',
        'bank_holder' => '',
        'bank_demo' => false,
    ],
];
