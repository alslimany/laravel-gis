<?php

return [
    'retention_avl_days' => (int) env('DISASTER_AVL_RETENTION_DAYS', 14),
    'max_skew_seconds' => (int) env('DISASTER_AVL_MAX_SKEW_SECONDS', 300),

    'advisory' => [
        'message' => env(
            'DISASTER_ADVISORY_MESSAGE',
            'Operational decision support for emergency managers — not an official warning.',
        ),
        'authority' => [
            'name' => env('DISASTER_ADVISORY_AUTHORITY_NAME', ''),
            'url' => env('DISASTER_ADVISORY_AUTHORITY_URL', ''),
        ],
    ],

    /**
     * @deprecated Prefer disaster.exposure.default_hazard_radius_m. Kept for BC.
     */
    'proximity' => [
        'default_hazard_radius_m' => (int) env('DISASTER_PROXIMITY_RADIUS_M', 5000),
    ],

    'exposure' => [
        'default_hazard_radius_m' => (int) env('DISASTER_PROXIMITY_RADIUS_M', 5000),
        /** Attribute on population/census polygons used for SUM when intersecting hazard. */
        'population_attribute' => env('DISASTER_POPULATION_ATTRIBUTE', 'population'),
        /**
         * Org plugin settings keys → Core layer IDs (no FK).
         * Hazard keys map via ProximityComputer/ExposureComputer hazard layer lookup.
         */
        'layer_setting_keys' => [
            'buildings' => 'buildings_layer_id',
            'roads' => 'roads_layer_id',
            'population' => 'demographic_layer_id',
        ],
        /** Asset types rolled into zone summaries. */
        'zone_asset_type' => 'zone',
    ],

    /**
     * risk_index = hazard_level × exposure_score × vulnerability_score.
     * Org may override via organization_plugins.settings.risk_weights.
     */
    'risk' => [
        'default_hazard_level' => (float) env('DISASTER_DEFAULT_HAZARD_LEVEL', 0.7),
        'weights' => [
            'exposure' => [
                'population' => 0.40,
                'buildings' => 0.25,
                'roads' => 0.15,
                'assets' => 0.20,
            ],
            'normalization' => [
                'population' => 10000,
                'buildings' => 500,
                'roads_m' => 50000,
                'assets' => 20,
            ],
            'vulnerability' => [
                'default' => 0.5,
                'condition_grades' => [
                    'good' => 0.2,
                    'fair' => 0.4,
                    'poor' => 0.7,
                    'critical' => 1.0,
                ],
            ],
        ],
    ],

    'geofence' => [
        'incident_radius_m' => (int) env('DISASTER_GEOFENCE_INCIDENT_RADIUS_M', 500),
    ],

    'warning_ack' => [
        'default_channel' => env('DISASTER_WARNING_ACK_CHANNEL', 'email'),
        'log_channel' => env('DISASTER_WARNING_ACK_LOG_CHANNEL', 'stack'),
        'telegram' => [
            'bot_token' => env('DISASTER_TELEGRAM_BOT_TOKEN', env('EW_TELEGRAM_BOT_TOKEN', '')),
            'chat_id' => env('DISASTER_TELEGRAM_CHAT_ID', ''),
        ],
    ],

    /** Timezone used for dates printed in the COP / SitRep PDFs. */
    'report_timezone' => env('DISASTER_REPORT_TIMEZONE', config('app.display_timezone', 'Africa/Tripoli')),

    'cop' => [
        'poll_interval_ms' => (int) env('DISASTER_COP_POLL_INTERVAL_MS', 15_000),
        'stale_after_seconds' => (int) env('DISASTER_COP_STALE_AFTER_SECONDS', 30),
    ],

    /**
     * Evacuation routing (Wave 3). Default engine: pgRouting via RoutingEngine contract.
     * Performance: city-scale seeded network (~10k edges, ≤50 zones) p95 < target_seconds.
     */
    'routing' => [
        'engine' => env('DISASTER_ROUTING_ENGINE', 'auto'),
        'city_scale_batch_target_seconds' => (int) env('DISASTER_ROUTING_TARGET_SECONDS', 30),
        'vulnerable_site_types' => ['hospital_field', 'school', 'elderly_care'],
        'vulnerable_priority_boost' => 1000,
        'vulnerable_proximity_m' => 500,
    ],

    'cap' => [
        'sender' => env('DISASTER_CAP_SENDER', 'lumina-disaster'),
        'public_cache_seconds' => (int) env('DISASTER_CAP_PUBLIC_CACHE_SECONDS', 60),
    ],

    'sms' => [
        /** log | null — real providers bind via DisasterServiceProvider when configured. */
        'driver' => env('DISASTER_SMS_DRIVER', 'log'),
        'log_channel' => env('DISASTER_SMS_LOG_CHANNEL', 'stack'),
        'rate_max_attempts' => (int) env('DISASTER_SMS_RATE_MAX', 30),
        'rate_decay_seconds' => (int) env('DISASTER_SMS_RATE_DECAY', 60),
    ],
];
