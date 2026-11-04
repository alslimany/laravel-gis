<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Desktop GIS Connect
    |--------------------------------------------------------------------------
    |
    | Grants (DG-1.5) live in dc_grants / dc_grant_events. OGC API Features,
    | GeoPackage check-out, and GeoServer AuthKey land in later stories.
    | Keep this config empty of operational secrets; keys stay in Sanctum.
    |
    | Note: grants are keyed by layers.id until core adds layers.public_id
    | (DG-1.1 / ADR-0002 §7). Ability strings dc:layer:{publicId}:* follow later.
    |
    */

];
