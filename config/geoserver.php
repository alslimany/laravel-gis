<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GeoServer Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL for your GeoServer instance. This should include the
    | protocol and domain, but not the /rest path.
    |
    */

    'url' => env('GEOSERVER_URL', 'http://geoserver:8080/geoserver'),

    /*
    |--------------------------------------------------------------------------
    | Browser-facing GeoServer URL
    |--------------------------------------------------------------------------
    |
    | Maps in the browser cannot use the Docker hostname. Imagery layers
    | request WMS from this address.
    |
    */

    'public_url' => rtrim(env('GEOSERVER_PUBLIC_URL', 'http://127.0.0.1:8081/geoserver'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Point icon URL GeoServer can fetch
    |--------------------------------------------------------------------------
    |
    | Published SLD files reference this base. Inside Docker, GeoServer reaches
    | nginx by its service name. The browser uses the relative /map-icons path.
    |
    */

    'icon_base_url' => rtrim(env('MAP_ICON_BASE_URL', 'http://nginx/map-icons'), '/'),

    /*
    |--------------------------------------------------------------------------
    | GeoServer Admin Credentials
    |--------------------------------------------------------------------------
    |
    | The admin username and password for authenticating with GeoServer
    | REST API endpoints.
    |
    */

    'admin_user' => env('GEOSERVER_ADMIN_USER', 'admin'),
    'admin_password' => env('GEOSERVER_ADMIN_PASSWORD', 'geoserver'),

    /*
    |--------------------------------------------------------------------------
    | Default Workspace
    |--------------------------------------------------------------------------
    |
    | The default workspace to use when publishing layers. Each organization
    | can have its own workspace, but this serves as the default.
    |
    */

    'workspace' => env('GEOSERVER_WORKSPACE', 'webgis'),

    /*
    |--------------------------------------------------------------------------
    | Default DataStore
    |--------------------------------------------------------------------------
    |
    | The default PostGIS datastore name to use when publishing layers.
    |
    */

    'datastore' => env('GEOSERVER_DATASTORE', 'postgis_store'),

    /*
    |--------------------------------------------------------------------------
    | PostGIS Connection Parameters
    |--------------------------------------------------------------------------
    |
    | Connection parameters for the PostGIS database that GeoServer will
    | use to access spatial data.
    |
    */

    'postgis' => [
        'host' => env('DB_HOST', 'postgis'),
        'port' => env('DB_PORT', '5432'),
        'database' => env('DB_DATABASE', 'laravel_gis'),
        'schema' => env('DB_SCHEMA', 'public'),
        'user' => env('DB_USERNAME', 'postgres'),
        'password' => env('DB_PASSWORD', 'secret'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for HTTP requests made to GeoServer.
    |
    */

    'timeout' => env('GEOSERVER_TIMEOUT', 30),
    'retry_times' => env('GEOSERVER_RETRY_TIMES', 3),
    'retry_delay' => env('GEOSERVER_RETRY_DELAY', 1000), // milliseconds

];
