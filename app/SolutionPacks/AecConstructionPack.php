<?php

namespace App\SolutionPacks;

class AecConstructionPack extends SolutionPack
{
    public function slug(): string
    {
        return 'aec-construction';
    }

    public function name(): string
    {
        return 'AEC / Construction';
    }

    public function description(): string
    {
        return 'Parcels, buildings, utilities, and inspections with sample features for construction project tracking.';
    }

    public function definition(): array
    {
        return [
            'layers' => [
                [
                    'name' => 'Parcels',
                    'description' => 'Land parcels and lots inside the project boundary.',
                    'geometry_type' => 'Polygon',
                    'style' => [
                        'renderer' => 'simple',
                        'symbol' => ['fillColor' => '#f59e0b', 'fillOpacity' => 0.2, 'strokeColor' => '#b45309', 'strokeWidth' => 2],
                    ],
                    'fields' => [
                        ['name' => 'name', 'alias' => 'Parcel name', 'type' => 'string', 'required' => true],
                        ['name' => 'address', 'alias' => 'Address', 'type' => 'string'],
                        ['name' => 'zoning', 'alias' => 'Zoning', 'type' => 'string', 'domain' => ['Residential', 'Commercial', 'Industrial', 'Mixed use', 'Agricultural']],
                        ['name' => 'land_area_m2', 'alias' => 'Land area (m²)', 'type' => 'float'],
                        ['name' => 'land_area_ha', 'alias' => 'Land area (ha)', 'type' => 'float', 'calculated' => 'land_area_m2 / 10000'],
                        ['name' => 'status', 'alias' => 'Status', 'type' => 'string', 'domain' => ['Active', 'Under review', 'Vacant']],
                    ],
                ],
                [
                    'name' => 'Buildings',
                    'description' => 'Buildings and structures, linked to their parcel.',
                    'geometry_type' => 'Polygon',
                    'style' => [
                        'renderer' => 'simple',
                        'symbol' => ['fillColor' => '#3b82f6', 'fillOpacity' => 0.45, 'strokeColor' => '#1d4ed8', 'strokeWidth' => 2],
                    ],
                    'fields' => [
                        ['name' => 'name', 'alias' => 'Building name', 'type' => 'string', 'required' => true],
                        ['name' => 'use_type', 'alias' => 'Use type', 'type' => 'string', 'domain' => ['Residential', 'Commercial', 'Industrial', 'Institutional', 'Healthcare', 'Educational']],
                        ['name' => 'floors', 'alias' => 'Floors', 'type' => 'integer'],
                        ['name' => 'height_m', 'alias' => 'Height (m)', 'type' => 'float'],
                        ['name' => 'status', 'alias' => 'Status', 'type' => 'string', 'domain' => ['Proposed', 'Under construction', 'Completed', 'On hold', 'Demolished']],
                        ['name' => 'year_built', 'alias' => 'Year built', 'type' => 'integer'],
                    ],
                ],
                [
                    'name' => 'Utilities',
                    'description' => 'Utility corridors and service lines across the site.',
                    'geometry_type' => 'LineString',
                    'style' => [
                        'renderer' => 'simple',
                        'symbol' => ['fillColor' => '#06b6d4', 'fillOpacity' => 1, 'strokeColor' => '#0e7490', 'strokeWidth' => 3],
                    ],
                    'fields' => [
                        ['name' => 'utility_type', 'alias' => 'Utility type', 'type' => 'string', 'domain' => ['Water', 'Sewer', 'Stormwater', 'Electric', 'Gas', 'Telecom', 'Fiber']],
                        ['name' => 'material', 'alias' => 'Material', 'type' => 'string', 'domain' => ['PVC', 'Steel', 'Concrete', 'Copper', 'HDPE', 'Unknown']],
                        ['name' => 'diameter_mm', 'alias' => 'Diameter (mm)', 'type' => 'integer'],
                        ['name' => 'status', 'alias' => 'Status', 'type' => 'string', 'domain' => ['Operational', 'Under repair', 'Planned', 'Decommissioned']],
                    ],
                ],
                [
                    'name' => 'Inspections',
                    'description' => 'Site inspection records linked to buildings.',
                    'geometry_type' => 'Point',
                    'style' => [
                        'renderer' => 'simple',
                        'symbol' => ['fillColor' => '#22c55e', 'fillOpacity' => 0.9, 'strokeColor' => '#15803d', 'strokeWidth' => 2, 'pointRadius' => 7, 'icon' => 'circle'],
                    ],
                    'fields' => [
                        ['name' => 'inspection_type', 'alias' => 'Inspection type', 'type' => 'string', 'domain' => ['Safety', 'Quality', 'Progress', 'Environmental', 'Fire']],
                        ['name' => 'result', 'alias' => 'Result', 'type' => 'string', 'domain' => ['Pass', 'Conditional', 'Fail', 'Re-inspection']],
                        ['name' => 'inspector', 'alias' => 'Inspector', 'type' => 'string'],
                        ['name' => 'inspection_date', 'alias' => 'Inspection date', 'type' => 'date'],
                        ['name' => 'notes', 'alias' => 'Notes', 'type' => 'string'],
                    ],
                ],
            ],
            'relationships' => [
                ['name' => 'Parcel buildings', 'parent' => 'Parcels', 'child' => 'Buildings', 'foreign_key' => 'parcel_id'],
                ['name' => 'Building inspections', 'parent' => 'Buildings', 'child' => 'Inspections', 'foreign_key' => 'building_id'],
            ],
            'features' => [
                [
                    'layer' => 'Parcels',
                    'attributes' => [
                        'name' => 'Riverside lot A',
                        'address' => '12 Riverside Way',
                        'zoning' => 'Mixed use',
                        'land_area_m2' => 10100.0,
                        'status' => 'Active',
                    ],
                    'wkt' => 'POLYGON((-122.4205 37.7740, -122.4195 37.7740, -122.4195 37.7750, -122.4205 37.7750, -122.4205 37.7740))',
                ],
                [
                    'layer' => 'Parcels',
                    'attributes' => [
                        'name' => 'Market square lot',
                        'address' => '40 Market Street',
                        'zoning' => 'Commercial',
                        'land_area_m2' => 8400.0,
                        'status' => 'Under review',
                    ],
                    'wkt' => 'POLYGON((-122.4193 37.7740, -122.4183 37.7740, -122.4183 37.7750, -122.4193 37.7750, -122.4193 37.7740))',
                ],
                [
                    'layer' => 'Buildings',
                    'attributes' => [
                        'name' => 'Riverside tower',
                        'use_type' => 'Residential',
                        'floors' => 12,
                        'height_m' => 42.5,
                        'status' => 'Under construction',
                        'year_built' => null,
                    ],
                    'refs' => ['parcel_id' => ['layer' => 'Parcels', 'name' => 'Riverside lot A']],
                    'wkt' => 'POLYGON((-122.4203 37.7742, -122.4199 37.7742, -122.4199 37.7746, -122.4203 37.7746, -122.4203 37.7742))',
                ],
                [
                    'layer' => 'Buildings',
                    'attributes' => [
                        'name' => 'Market hall',
                        'use_type' => 'Commercial',
                        'floors' => 2,
                        'height_m' => 9.0,
                        'status' => 'Completed',
                        'year_built' => 2019,
                    ],
                    'refs' => ['parcel_id' => ['layer' => 'Parcels', 'name' => 'Market square lot']],
                    'wkt' => 'POLYGON((-122.4191 37.7743, -122.4187 37.7743, -122.4187 37.7747, -122.4191 37.7747, -122.4191 37.7743))',
                ],
                [
                    'layer' => 'Utilities',
                    'attributes' => [
                        'utility_type' => 'Water',
                        'material' => 'PVC',
                        'diameter_mm' => 300,
                        'status' => 'Operational',
                    ],
                    'wkt' => 'LINESTRING(-122.4207 37.7741, -122.4181 37.7741)',
                ],
                [
                    'layer' => 'Utilities',
                    'attributes' => [
                        'utility_type' => 'Fiber',
                        'material' => 'HDPE',
                        'diameter_mm' => 25,
                        'status' => 'Planned',
                    ],
                    'wkt' => 'LINESTRING(-122.4206 37.7748, -122.4182 37.7748)',
                ],
                [
                    'layer' => 'Inspections',
                    'attributes' => [
                        'inspection_type' => 'Safety',
                        'result' => 'Pass',
                        'inspector' => 'J. Rivera',
                        'inspection_date' => '2026-09-15',
                        'notes' => 'Scaffolding and edge protection compliant.',
                    ],
                    'refs' => ['building_id' => ['layer' => 'Buildings', 'name' => 'Riverside tower']],
                    'wkt' => 'POINT(-122.4200 37.7743)',
                ],
                [
                    'layer' => 'Inspections',
                    'attributes' => [
                        'inspection_type' => 'Quality',
                        'result' => 'Conditional',
                        'inspector' => 'M. Chen',
                        'inspection_date' => '2026-09-22',
                        'notes' => 'Facade joints require resealing before handover.',
                    ],
                    'refs' => ['building_id' => ['layer' => 'Buildings', 'name' => 'Market hall']],
                    'wkt' => 'POINT(-122.4189 37.7745)',
                ],
            ],
            'maps' => [
                [
                    'name' => 'AEC Site Overview',
                    'description' => 'Starter map with the AEC pack layers around the sample site.',
                    'basemap' => 'osm',
                    'viewport' => ['center' => [-122.4195, 37.7745], 'zoom' => 16, 'rotation' => 0],
                    'layers' => ['Parcels', 'Buildings', 'Utilities', 'Inspections'],
                ],
            ],
            'forms' => [
                [
                    'name' => 'Site inspection',
                    'description' => 'Field crew inspection form. Share the public link to collect submissions on site.',
                    'layer' => 'Inspections',
                    'is_public' => true,
                    'schema' => [
                        ['name' => 'inspection_type', 'label' => 'Inspection type', 'type' => 'select', 'required' => true, 'options' => ['Safety', 'Quality', 'Progress', 'Environmental', 'Fire']],
                        ['name' => 'result', 'label' => 'Result', 'type' => 'select', 'required' => true, 'options' => ['Pass', 'Conditional', 'Fail', 'Re-inspection']],
                        ['name' => 'inspector', 'label' => 'Inspector', 'type' => 'text', 'required' => true],
                        ['name' => 'inspection_date', 'label' => 'Inspection date', 'type' => 'date', 'required' => false],
                        ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false],
                    ],
                ],
            ],
            'dashboards' => [
                [
                    'name' => 'AEC progress',
                    'description' => 'Building counts and inspection results from the AEC pack layers.',
                    'widgets' => [
                        ['type' => 'indicator', 'title' => 'Buildings', 'layer' => 'Buildings', 'aggregation' => 'count'],
                        ['type' => 'indicator', 'title' => 'Inspections', 'layer' => 'Inspections', 'aggregation' => 'count'],
                        ['type' => 'serial', 'title' => 'Buildings by status', 'layer' => 'Buildings', 'group_by' => 'status', 'chart_style' => 'bar'],
                        ['type' => 'pie', 'title' => 'Inspection results', 'layer' => 'Inspections', 'group_by' => 'result'],
                    ],
                ],
            ],
            'workflow' => [
                [
                    'title' => 'Collect in the field',
                    'description' => 'Open the Site inspection form (public link) and record observations on site.',
                    'kind' => 'form',
                    'target' => 'Site inspection',
                ],
                [
                    'title' => 'Notify the office',
                    'description' => 'A webhook fired on form submission alerts the office — configure under Organization → Webhooks (event: form.submitted).',
                    'kind' => 'text',
                ],
                [
                    'title' => 'Monitor progress',
                    'description' => 'Watch building counts and inspection results on the AEC progress dashboard.',
                    'kind' => 'dashboard',
                    'target' => 'AEC progress',
                ],
                [
                    'title' => 'Review spatially',
                    'description' => 'Open the site map to see parcels, buildings, utilities, and inspection points together.',
                    'kind' => 'map',
                    'target' => 'AEC Site Overview',
                ],
                [
                    'title' => 'Report and export',
                    'description' => 'Export submissions or layer tables from the form or layer pages for the monthly report.',
                    'kind' => 'text',
                ],
            ],
        ];
    }
}
