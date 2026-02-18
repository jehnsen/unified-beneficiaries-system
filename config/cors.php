<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // Restrict to the specific HTTP verbs the API actually uses.
    // Wildcard fallback removed — a missing env var should fail closed, not open.
    'allowed_methods' => explode(',', env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,DELETE,OPTIONS')),

    // No wildcard fallback: if CORS_ALLOWED_ORIGINS is unset, no origin is permitted.
    // Set this to the actual frontend domain(s) in production (e.g. "https://ubis.province.gov.ph").
    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', ''))),

    'allowed_origins_patterns' => [],

    // Restrict to the specific headers the API and Sanctum require.
    'allowed_headers' => explode(',', env('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization,X-Requested-With')),

    'exposed_headers' => array_filter(explode(',', env('CORS_EXPOSED_HEADERS', ''))),

    'max_age' => (int) env('CORS_MAX_AGE', 3600),

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', true),

];
