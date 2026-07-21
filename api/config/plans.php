<?php

return [
    'currency' => 'SAR',
    'vat_rate' => 0.15, // KSA standard VAT

    // Prices are ex-VAT, per month.
    'plans' => [
        'basic' => [
            'name' => 'Basic',
            'price' => 99,
            'interval' => 'month',
            'features' => ['1 branch', 'Online booking', 'SMS reminders'],
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => 199,
            'interval' => 'month',
            'features' => ['Unlimited branches', 'Staff scheduling', 'Owner dashboard', 'Priority support'],
        ],
    ],
];
