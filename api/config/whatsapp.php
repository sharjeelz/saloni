<?php

return [
    'country_code' => '966',

    'default' => env('WHATSAPP_DRIVER', 'cloud'),

    // Meta WhatsApp Cloud API (graph.facebook.com).
    'cloud' => [
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com/v21.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'token' => env('WHATSAPP_TOKEN'),
    ],
];
