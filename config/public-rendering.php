<?php

return [
    // PHP public HTML remains available when the optional loopback renderer is off.
    'ssr_enabled' => env('BAKERY_SSR_ENABLED', false),
    'ssr_url' => env('BAKERY_SSR_URL', 'http://127.0.0.1:13714'),
    'ssr_timeout' => 0.5,
];
