<?php

return [
    /*
     | Active gateway. "manual" simulates a successful charge (dev/testing).
     | "moyasar" charges real mada/cards once credentials are set.
     */
    'gateway' => env('PAYMENT_GATEWAY', 'manual'),

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
