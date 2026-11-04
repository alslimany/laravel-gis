<?php

namespace Lumina\Disaster\Publishing;

use App\Contracts\LayerPublisher;
use App\Models\Layer;
use App\Models\Organization;
use App\Services\SldGenerator;
use Lumina\Disaster\Models\Closure;
use Lumina\Disaster\Models\Incident;

/**
 * Publishes org-scoped incident points and road closure lines as SQL-view layers, the same
 * way AssetLayerPublisher does for assets, so the COP / Assets maps draw them as MVT tiles.
 */
class OpsLayerPublisher
{
    public const INCIDENTS_GEOSERVER_LAYER = 'dm_org_incidents';

    public const CLOSURES_GEOSERVER_LAYER = 'dm_org_closures';

    /** Incident priority (1 = highest) → colour. */
    public const PRIORITY_COLORS = [
        '1' => '#ef4444',
        '2' => '#f97316',
        '3' => '#eab308',
        '4' => '#3b82f6',
        '5' => '#64748b',
    ];

    /** Closure severity → colour. */
    public const SEVERITY_COLORS = [
        'major' => '#991b1b',
        'moderate' => '#c2410c',
        'minor' => '#a16207',
    ];

    /** Closures: 4px dark line over a 7px white casing, readable on any basemap. */
    public const CLOSURE_STROKE_WIDTH = 4;

    public const CLOSURE_CASING = ['casingColor' => '#ffffff', 'casingWidth' => 7];

    /**
     * Zoomed out (≈ zoom < 13 in Web Mercator, > 20 m/px) the line and casing get 2 px wider, and a
     * closure shorter than 14 px on screen gets a "no entry" marker so it never reads as a dot.
     */
    public const CLOSURE_LOW_ZOOM = ['minLengthPx' => 14, 'lowZoomResolution' => 20, 'lowZoomExtraWidth' => 2];

    public const CLOSURES_STYLE_NAME = 'dm_org_closures_style';

    public function __construct(public LayerPublisher $layers) {}

    public static function incidentsTable(int $orgId): string
    {
        return 'dm_org_incidents_'.$orgId;
    }

    public static function closuresTable(int $orgId): string
    {
        return 'dm_org_closures_'.$orgId;
    }

    /**
     * @return array{incidents: array, closures: array}
     */
    public function publish(Organization $organization, bool $syncGeoServer = true, ?int $userId = null): array
    {
        return [
            'incidents' => $this->publishIncidents($organization, $syncGeoServer, $userId),
            'closures' => $this->publishClosures($organization, $syncGeoServer, $userId),
        ];
    }

    public function publishIncidents(Organization $organization, bool $syncGeoServer = true, ?int $userId = null): array
    {
        $id = (int) $organization->getKey();
        $sql = <<<SQL
SELECT id, external_id, title, incident_type, CAST(priority AS TEXT) AS priority, status, ST_PointOnSurface(geom)::geometry(Point,4326) AS geom
FROM dm_incidents
WHERE organization_id = {$id} AND geom IS NOT NULL
SQL;

        return $this->layers->upsertSqlViewLayer(
            $organization,
            [
                'table_name' => self::incidentsTable($id),
                'name' => 'Incidents',
                'description' => 'Org-scoped incident points. Decision support only.',
                'geometry_type' => 'Point',
                'feature_count' => Incident::query()->where('organization_id', $id)->whereNotNull('geom')->count(),
                'style_config' => self::uniqueStyle('priority', self::PRIORITY_COLORS, 'disaster-incidents'),
                'metadata' => ['plugin' => 'disaster', 'kind' => 'disaster-incidents', 'name_ar' => 'البلاغات', 'description_key' => 'layers.desc.incidents', 'sql_view' => true],
                'user_id' => $userId,
            ],
            $sql,
            sqlView: [
                'layerName' => self::INCIDENTS_GEOSERVER_LAYER,
                'title' => "{$organization->name} — Incidents",
                'abstract' => 'Org-scoped incident points. Decision support only.',
                'geometryName' => 'geom',
                'geometryType' => 'Point',
                'srid' => 4326,
                'keyColumn' => 'id',
            ],
            syncGeoServer: $syncGeoServer,
        );
    }

    public function publishClosures(Organization $organization, bool $syncGeoServer = true, ?int $userId = null): array
    {
        $id = (int) $organization->getKey();
        // LineString, not MultiLineString: dm_closures.geom is a geometry(LineString,4326) column
        // (phase-0 migration), so every row is a single line. The cast pins the view's type so
        // GeoServer and the MVT path see LineString,4326 like the declared layer type.
        $sql = <<<SQL
SELECT id, external_id, closure_type, severity, valid_from, valid_to, geom::geometry(LineString,4326) AS geom
FROM dm_closures
WHERE organization_id = {$id} AND geom IS NOT NULL
SQL;

        return $this->layers->upsertSqlViewLayer(
            $organization,
            [
                'table_name' => self::closuresTable($id),
                'name' => 'Road closures',
                'description' => 'Org-scoped road and bridge closures. Decision support only.',
                'geometry_type' => 'LineString',
                'feature_count' => Closure::query()->where('organization_id', $id)->whereNotNull('geom')->count(),
                'style_config' => self::closuresStyle(),
                'metadata' => ['plugin' => 'disaster', 'kind' => 'disaster-closures', 'name_ar' => 'إغلاقات الطرق', 'description_key' => 'layers.desc.closures', 'sql_view' => true],
                'user_id' => $userId,
            ],
            $sql,
            sqlView: [
                'layerName' => self::CLOSURES_GEOSERVER_LAYER,
                'title' => "{$organization->name} — Closures",
                'abstract' => 'Org-scoped road and bridge closures. Decision support only.',
                'geometryName' => 'geom',
                'geometryType' => 'LineString',
                'srid' => 4326,
                'keyColumn' => 'id',
                'styleName' => self::CLOSURES_STYLE_NAME.'_'.$id,
                'styleSld' => self::closuresSld(self::CLOSURES_GEOSERVER_LAYER),
            ],
            syncGeoServer: $syncGeoServer,
        );
    }

    /** @return array<string, mixed> */
    public static function closuresStyle(): array
    {
        $style = self::uniqueStyle('severity', self::SEVERITY_COLORS, 'disaster-closures', self::CLOSURE_STROKE_WIDTH);
        $style['symbol'] = [...$style['symbol'], ...self::CLOSURE_CASING, ...self::CLOSURE_LOW_ZOOM, 'strokeOpacity' => 1];

        return $style;
    }

    /** Same look in GeoServer (WMS/print) as in the MVT map. */
    public static function closuresSld(string $layerName): string
    {
        $layer = new Layer;
        $layer->forceFill(['geometry_type' => 'LineString', 'geoserver_layer_name' => $layerName, 'table_name' => $layerName]);

        return app(SldGenerator::class)->generate($layer, self::closuresStyle());
    }

    /** @param array<string, string> $colors */
    public static function uniqueStyle(string $field, array $colors, string $kind, int $strokeWidth = 1): array
    {
        return [
            'kind' => $kind,
            'renderer' => 'unique',
            'field' => $field,
            'uniqueValues' => array_map(
                fn ($value, $color) => ['value' => (string) $value, 'color' => $color],
                array_keys($colors),
                array_values($colors),
            ),
            'symbol' => ['fillOpacity' => 0.8, 'strokeColor' => '#1f2937', 'strokeWidth' => $strokeWidth, 'pointRadius' => 6],
        ];
    }
}
