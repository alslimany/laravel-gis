<?php

/**
 * Sanctum personal-access-token ability catalog (DG-0.4 / ADR-0002 §4).
 *
 * Token abilities are concrete strings checked with Sanctum's CheckAbilities /
 * CheckForAnyAbility middleware (`tokenCan`). There is no wildcard matching at
 * request time yet — a token must list the exact ability string required by the
 * route (or the legacy `*` ability, which Sanctum treats as full access).
 *
 * Desktop-connect layer grants will later issue concrete abilities such as
 * `dc:layer:01HXYZ…:read` / `dc:layer:01HXYZ…:edit` derived from `dc_grants`.
 * The `{publicId}` segment is a layer's `layers.public_id` (ULID). The catalog
 * entries below document that shape; only concrete substitutions are valid on
 * tokens today (see `LuminaAbilities::isAllowed`).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Fixed abilities (selectable in the API token UI)
    |--------------------------------------------------------------------------
    */
    'fixed' => [
        'layers:read' => [
            'description' => 'List and view layer metadata under /api/v1/layers',
        ],
        'features:read' => [
            'description' => 'Read features and layer GeoJSON under /api/v1',
        ],
        'features:write' => [
            'description' => 'Create, update, and delete features under /api/v1',
        ],
        'exports:read' => [
            'description' => 'Download layer exports (GeoJSON, CSV, Excel) under /api/v1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pattern abilities (not selectable; validated when issued as concrete strings)
    |--------------------------------------------------------------------------
    |
    | Templates use `{publicId}` as a placeholder. Matching tokens must replace
    | it with a real public layer id, e.g. `dc:layer:01HXYZABCDEFG:read`.
    |
    */
    'patterns' => [
        'dc:layer:{publicId}:read' => [
            'regex' => '/^dc:layer:[0-9A-HJKMNP-TV-Z]{26}:read$/',
            'description' => 'Desktop-connect read grant for one layer (concrete public_id)',
        ],
        'dc:layer:{publicId}:edit' => [
            'regex' => '/^dc:layer:[0-9A-HJKMNP-TV-Z]{26}:edit$/',
            'description' => 'Desktop-connect edit grant for one layer (concrete public_id)',
        ],
    ],

];
