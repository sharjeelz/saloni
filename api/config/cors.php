<?php

// Published so production can lock the API to the known frontend origin(s).
// Auth is Bearer-token (Sanctum personal access tokens), not stateful cookies,
// so credentialed requests aren't required — but we keep origins explicit.
//
// CORS_ALLOWED_ORIGINS is a comma-separated list; defaults to '*' for local dev.

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins ?: ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
