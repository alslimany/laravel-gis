<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Advisory wording (cross-cutting)
    |--------------------------------------------------------------------------
    |
    | Every UI surface and alert channel must frame this as forecast-based
    | decision support, not an official warning. Optionally point operators
    | at a civil defense / meteorological authority (set via env).
    |
    */

    'advisory' => [
        'message' => env(
            'EW_ADVISORY_MESSAGE',
            'Forecast-based decision support only — not an official warning.',
        ),
        'citizen_reports' => env(
            'EW_ADVISORY_CITIZEN_REPORTS',
            'Citizen reports are a verification layer — not official warnings.',
        ),
        'report_received' => env(
            'EW_ADVISORY_REPORT_RECEIVED',
            'Report received for verification — not an official warning.',
        ),
        'short_tag' => 'decision support, not an official warning',
        'authority' => [
            'name' => env('EW_ADVISORY_AUTHORITY_NAME'),
            'url' => env('EW_ADVISORY_AUTHORITY_URL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Open-Meteo forecast ingestion (#60)
    |--------------------------------------------------------------------------
    |
    | Hourly precipitation (and optional temperature) for each basin outlet.
    | Free non-commercial API; no key. outbound_hosts advisory: api.open-meteo.com
    |
    */

    'open_meteo' => [
        'base_url' => env('EW_OPEN_METEO_URL', 'https://api.open-meteo.com/v1/forecast'),
        'forecast_days' => (int) env('EW_OPEN_METEO_FORECAST_DAYS', 3),
        'timezone' => env('EW_OPEN_METEO_TIMEZONE', 'UTC'),
        'hourly' => ['precipitation', 'precipitation_probability', 'temperature_2m'],
        'timeout_seconds' => (int) env('EW_OPEN_METEO_TIMEOUT', 30),
        /** Max locations per Open-Meteo multi-point request. */
        'batch_size' => (int) env('EW_OPEN_METEO_BATCH', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Satellite / reanalysis observations (Phase 2 — IMERG + soil moisture)
    |--------------------------------------------------------------------------
    |
    | NASA POWER hourly point API bridges IMERG-class precip (PRECTOTCORR) and
    | SMAP-class root-zone wetness (GWETROOT) without Earthdata login. Full GES
    | DISC IMERG/SMAP granule ingest can replace this source later.
    |
    */

    'observations' => [
        'enabled' => (bool) env('EW_OBSERVATIONS_ENABLED', true),
        'source' => env('EW_OBSERVATIONS_SOURCE', 'nasa-power'),
        'lookback_days' => (int) env('EW_OBSERVATIONS_LOOKBACK_DAYS', 3),
        /** POWER NRT often lags a few hours — end window this far behind "now". */
        'latency_hours' => (int) env('EW_OBSERVATIONS_LATENCY_HOURS', 6),
        /**
         * Earthdata bearer token (URS) for authenticated GES DISC IMERG/SMAP
         * granule downloads. Create at https://urs.earthdata.nasa.gov/ — never
         * commit the value; set EW_EARTHDATA_TOKEN in .env only.
         */
        'earthdata_token' => env('EW_EARTHDATA_TOKEN'),
        'nasa_power' => [
            'base_url' => env('EW_NASA_POWER_URL', 'https://power.larc.nasa.gov/api/temporal/hourly/point'),
            'community' => 'AG',
            'parameters' => ['PRECTOTCORR', 'GWETROOT'],
            'timeout_seconds' => (int) env('EW_NASA_POWER_TIMEOUT', 60),
            'fill_value' => -999,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk calculator (#61)
    |--------------------------------------------------------------------------
    |
    | Combines forecast rain windows, soil wetness (SMAP-class observations when
    | present, else antecedent proxy), and basin slope/area into green→red levels.
    |
    */

    'risk' => [
        'horizon_hours' => (int) env('EW_RISK_HORIZON_HOURS', 72),
        /** Antecedent 72h rain (mm) treated as fully saturated soil proxy. */
        'antecedent_sat_mm' => (float) env('EW_RISK_ANTECEDENT_SAT_MM', 80),
        'weights' => [
            'rain' => 0.70,
            'soil' => 0.20,
            'terrain' => 0.10,
        ],
        'rain_mm' => [
            '1h' => ['yellow' => 5, 'orange' => 15, 'red' => 30],
            '6h' => ['yellow' => 15, 'orange' => 35, 'red' => 60],
            '24h' => ['yellow' => 25, 'orange' => 50, 'red' => 80],
        ],
        'score_bands' => [
            'yellow' => 25,
            'orange' => 50,
            'red' => 75,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exposure (Phase 3 — OSM buildings & roads)
    |--------------------------------------------------------------------------
    |
    | Overpass summaries per basin outlet bbox. Completeness follows OSM
    | coverage; advisory decision support only.
    |
    */

    'exposure' => [
        'enabled' => (bool) env('EW_EXPOSURE_ENABLED', true),
        'overpass' => [
            'base_url' => env('EW_OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
            'timeout_seconds' => (int) env('EW_OVERPASS_TIMEOUT', 90),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert rules (# Phase 2 — configurable thresholds)
    |--------------------------------------------------------------------------
    |
    | Defaults are Tripoli-oriented starting points informed by recent coastal
    | flood episodes and capped well below Storm Daniel / Derna extremes to
    | limit false alarms. Orgs edit AlertRule rows; re-seed with
    | early-warning:seed-alert-rules. Suggest from local forecast history with
    | early-warning:suggest-thresholds (CHIRPS bulk import is a later step).
    |
    */

    'alerts' => [
        'defaults' => [
            [
                'name' => 'Heavy 6h rain (yellow)',
                'basin_code' => null,
                'metric' => 'precip_6h_mm',
                'operator' => '>=',
                'threshold' => 20,
                'risk_level_min' => 'yellow',
                'channels' => ['log'],
                'enabled' => true,
            ],
            [
                'name' => 'Intense 6h rain (orange)',
                'basin_code' => null,
                'metric' => 'precip_6h_mm',
                'operator' => '>=',
                'threshold' => 40,
                'risk_level_min' => 'orange',
                'channels' => ['log'],
                'enabled' => true,
            ],
            [
                'name' => 'Extreme 6h rain (red)',
                'basin_code' => null,
                'metric' => 'precip_6h_mm',
                'operator' => '>=',
                'threshold' => 70,
                'risk_level_min' => 'red',
                'channels' => ['log'],
                'enabled' => true,
            ],
            [
                'name' => 'Elevated risk score',
                'basin_code' => null,
                'metric' => 'risk_score',
                'operator' => '>=',
                'threshold' => 50,
                'risk_level_min' => 'orange',
                'channels' => ['log'],
                'enabled' => true,
            ],
        ],
        /** Hours to look ahead when evaluating rules. */
        'eval_horizon_hours' => (int) env('EW_ALERT_EVAL_HOURS', 24),
        /** Do not re-log the same rule+basin+forecast_at within this window. */
        'dedupe_hours' => (int) env('EW_ALERT_DEDUPE_HOURS', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert delivery channels
    |--------------------------------------------------------------------------
    |
    | Org destinations live on OrganizationPlugin.settings (notify_emails,
    | telegram_chat_ids). Bot token is global via env.
    |
    */

    'channels' => [
        'telegram' => [
            'bot_token' => env('EW_TELEGRAM_BOT_TOKEN'),
            'timeout_seconds' => (int) env('EW_TELEGRAM_TIMEOUT', 10),
        ],
        'sms' => [
            /** Optional HTTP gateway; when empty, SMS is logged only. */
            'webhook_url' => env('EW_SMS_WEBHOOK_URL'),
            'timeout_seconds' => (int) env('EW_SMS_TIMEOUT', 15),
        ],
    ],

    'schedule' => [
        'fetch_forecasts' => env('EW_SCHEDULE_FETCH', 'hourly'),
        'fetch_observations' => env('EW_SCHEDULE_OBSERVATIONS', 'everySixHours'),
        'compute_risk' => env('EW_SCHEDULE_RISK', 'hourly'),
        'evaluate_alerts' => env('EW_SCHEDULE_ALERTS', 'hourly'),
        'dispatch_alerts' => env('EW_SCHEDULE_DISPATCH', 'hourly'),
        'compute_exposure' => env('EW_SCHEDULE_EXPOSURE', 'daily'),
    ],

];
