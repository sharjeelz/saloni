<?php

return [
    /*
     | Active gateway. "manual" simulates a successful charge (dev/testing).
     | "moyasar" charges real mada/cards once credentials are set.
     */
    'gateway' => env('PAYMENT_GATEWAY', 'manual'),

    /*
     | Self-serve billing. When false, salons cannot subscribe or cancel from
     | the console — we collect payment offline and activate plans with the
     | `billing:activate` command. Keep this OFF until a real gateway is live,
     | otherwise the manual gateway would hand out free subscriptions.
     */
    'self_serve' => (bool) env('BILLING_SELF_SERVE', false),

    // Support contact shown on the billing screen so salons know how to subscribe.
    'support' => [
        'whatsapp' => env('SUPPORT_WHATSAPP'), // E.164 digits, e.g. 9665XXXXXXXX
        'phone' => env('SUPPORT_PHONE'),
    ],

    'moyasar' => [
        'secret_key' => env('MOYASAR_SECRET_KEY'),
        'base_url' => env('MOYASAR_BASE_URL', 'https://api.moyasar.com/v1'),
    ],

    // Platform seller identity for ZATCA tax invoices (we invoice the salon).
    'seller' => [
        'name' => env('SELLER_NAME', 'Salooni'),
        'vat_number' => env('SELLER_VAT_NUMBER', '300000000000003'),
    ],
];
