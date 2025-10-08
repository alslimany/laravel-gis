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
