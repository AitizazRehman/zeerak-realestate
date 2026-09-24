<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP Security Headers
    |--------------------------------------------------------------------------
    |
    | HSTS is only emitted for HTTPS requests. includeSubDomains is disabled
    | by default because enabling it affects every subdomain of the domain.
    | Turn it on only after confirming all subdomains are permanently HTTPS.
    |
    */

    'hsts' => [
        'enabled' => env('SECURITY_HSTS_ENABLED', true),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false),
        'preload' => env('SECURITY_HSTS_PRELOAD', false),
    ],
];
