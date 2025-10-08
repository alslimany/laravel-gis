<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data Import Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration for the spatial data import system.
    |
    */

    'ogr2ogr_path' => env('OGR2OGR_PATH', '/usr/bin/ogr2ogr'),

    'ogrinfo_path' => env('OGRINFO_PATH', '/usr/bin/ogrinfo'),

    'upload_disk' => env('DATA_IMPORT_DISK', 'local'),

    'upload_path' => 'imports',

    'max_file_size' => env('DATA_IMPORT_MAX_FILE_SIZE', 104857600), // 100MB in bytes

    'allowed_extensions' => [
        'shapefile' => ['shp', 'shx', 'dbf', 'prj', 'cpg'],
        'geojson' => ['geojson', 'json'],
        'kml' => ['kml', 'kmz'],
        'csv' => ['csv'],
    ],

    'table_prefix' => env('DATA_IMPORT_TABLE_PREFIX', 'import_'),

    'default_srid' => env('DATA_IMPORT_DEFAULT_SRID', 4326),

    'timeout' => env('DATA_IMPORT_TIMEOUT', 300), // 5 minutes

    'chunk_size' => env('DATA_IMPORT_CHUNK_SIZE', 1000), // For large files

];
