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

    'max_file_size' => env('DATA_IMPORT_MAX_FILE_SIZE', 1073741824), // 1 GB

    'allowed_extensions' => [
        'shp', 'shx', 'dbf', 'prj', 'cpg', 'sbn', 'sbx',
        'geojson', 'json',
        'kml', 'kmz',
        'csv',
        'xlsx', 'xls',
        'zip',
    ],

    'table_prefix' => env('DATA_IMPORT_TABLE_PREFIX', 'import_'),

    'default_srid' => env('DATA_IMPORT_DEFAULT_SRID', 4326),

    'timeout' => env('DATA_IMPORT_TIMEOUT', 1800),

    'chunk_size' => env('DATA_IMPORT_CHUNK_SIZE', 1000), // For large files

];
