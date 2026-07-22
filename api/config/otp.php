<?php

return [

    /*
    | Whether the plaintext OTP is returned in API responses (as `debug_code`).
    |
    | MUST stay false in production — exposing it lets anyone request an
    | account's code and read it straight from the JSON, which is a full
    | account takeover. Enable ONLY for local development and automated tests.
    */
    'expose_debug_code' => (bool) env('OTP_EXPOSE_DEBUG_CODE', false),

];
