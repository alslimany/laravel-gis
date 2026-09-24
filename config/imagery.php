<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Imagery package limit
    |--------------------------------------------------------------------------
    |
    | GeoTIFF and georeferenced JPEG/PNG packages. Bytes. Default 1 GB.
    |
    */

    'max_file_size' => (int) env('IMAGERY_MAX_FILE_SIZE', 1073741824),

    'timeout' => (int) env('IMAGERY_TIMEOUT', 1800),

    'gdalinfo_path' => env('GDALINFO_PATH', 'gdalinfo'),

    'gdalwarp_path' => env('GDALWARP_PATH', 'gdalwarp'),

    'gdaladdo_path' => env('GDALADDO_PATH', 'gdaladdo'),

    /*
    |--------------------------------------------------------------------------
    | Mosaic directory
    |--------------------------------------------------------------------------
    |
    | Laravel writes Cloud Optimized GeoTIFFs under storage/app/imagery.
    | GeoServer reads that same directory at geoserver_path.
    |
    */

    'storage_directory' => 'imagery',

    'geoserver_path' => rtrim(env('IMAGERY_GEOSERVER_PATH', '/opt/imagery'), '/'),

    'allowed_extensions' => [
        'tif', 'tiff', 'jpg', 'jpeg', 'png', 'zip',
        'jgw', 'jpgw', 'pgw', 'pngw', 'wld', 'tfw', 'tifw', 'prj',
    ],

];
