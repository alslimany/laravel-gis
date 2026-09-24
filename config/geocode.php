<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nominatim / Geocoding Base URL
    |--------------------------------------------------------------------------
    |
    | Base URL for the geocoding provider. Defaults to OpenStreetMap Nominatim.
    | Override via GEOCODE_URL in your environment.
    |
    */

    'url' => env('GEOCODE_URL', 'https://nominatim.openstreetmap.org'),

    /*
    |--------------------------------------------------------------------------
    | User-Agent
    |--------------------------------------------------------------------------
    |
    | Nominatim requires a valid identifying User-Agent. Keep this descriptive
    | so rate limiting is applied fairly to your application.
    |
    */

    'user_agent' => env('GEOCODE_USER_AGENT', 'GIS-App/1.0 (contact@example.com)'),

    /*
    |--------------------------------------------------------------------------
    | Request defaults
    |--------------------------------------------------------------------------
    */

    'format' => 'json',
    'limit' => (int) env('GEOCODE_LIMIT', 10),
    'timeout' => (int) env('GEOCODE_TIMEOUT', 10),

];
