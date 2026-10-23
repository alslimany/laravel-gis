<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

class SpatialHelper
{
    /**
     * Mean Earth radius in meters (IUGG).
     */
    private const EARTH_RADIUS = 6371008.8;

    /**
     * Convert WKT to GeoJSON.
     */
    public static function wktToGeoJson(string $wkt): ?array
    {
        if (! self::postgis()) {
            $parsed = self::parseWkt($wkt);

            if ($parsed === null) {
                return null;
            }

            return ['type' => $parsed['type'], 'coordinates' => $parsed['coordinates']];
        }

        $result = DB::selectOne(
            'SELECT ST_AsGeoJSON(ST_GeomFromText(?, 4326)) as geojson',
            [$wkt]
        );

        return $result ? json_decode($result->geojson, true) : null;
    }

    /**
     * Convert GeoJSON to WKT.
     *
     * @param  array|string  $geojson
     */
    public static function geoJsonToWkt($geojson): ?string
    {
        if (is_array($geojson)) {
            $geojson = json_encode($geojson);
        }

        if (! self::postgis()) {
            $decoded = json_decode($geojson, true);

            if (! is_array($decoded) || ! isset($decoded['type'], $decoded['coordinates']) || ! is_array($decoded['coordinates'])) {
                return null;
            }

            return self::coordinatesToWkt((string) $decoded['type'], $decoded['coordinates']);
        }

        $result = DB::selectOne(
            'SELECT ST_AsText(ST_GeomFromGeoJSON(?)) as wkt',
            [$geojson]
        );

        return $result->wkt ?? null;
    }

    /**
     * Create a point from latitude and longitude.
     *
     * @return string WKT point
     */
    public static function makePoint(float $latitude, float $longitude): string
    {
        return "POINT({$longitude} {$latitude})";
    }

    /**
     * Create a polygon from coordinates array.
     *
     * @param  array  $coordinates  Array of [longitude, latitude] pairs
     * @return string WKT polygon
     */
    public static function makePolygon(array $coordinates): string
    {
        $points = array_map(function ($coord) {
            return "{$coord[0]} {$coord[1]}";
        }, $coordinates);

        // Ensure the polygon is closed
        if ($coordinates[0] !== end($coordinates)) {
            $points[] = "{$coordinates[0][0]} {$coordinates[0][1]}";
        }

        return 'POLYGON(('.implode(', ', $points).'))';
    }

    /**
     * Calculate distance between two points in meters.
     *
     * @return float Distance in meters
     */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        if (! self::postgis()) {
            return self::haversine($lat1, $lon1, $lat2, $lon2);
        }

        $result = DB::selectOne(
            'SELECT ST_Distance(
                ST_MakePoint(?, ?)::geography,
                ST_MakePoint(?, ?)::geography
            ) as distance',
            [$lon1, $lat1, $lon2, $lat2]
        );

        return (float) $result->distance;
    }

    /**
     * Check if a point is within a polygon.
     */
    public static function pointInPolygon(float $latitude, float $longitude, string $polygonWkt): bool
    {
        if (! self::postgis()) {
            $parsed = self::parseWkt($polygonWkt);

            if ($parsed === null) {
                return false;
            }

            $point = [$longitude, $latitude];

            return match ($parsed['type']) {
                'Polygon' => self::pointInRing($point, $parsed['coordinates'][0]),
                'LineString' => self::pointOnLine($point, $parsed['coordinates']),
                default => false,
            };
        }

        $result = DB::selectOne(
            'SELECT ST_Within(
                ST_MakePoint(?, ?)::geography,
                ST_GeomFromText(?, 4326)
            ) as within',
            [$longitude, $latitude, $polygonWkt]
        );

        return (bool) $result->within;
    }

    /**
     * Get the center point of a geometry.
     *
     * @return array [latitude, longitude]
     */
    public static function centroid(string $wkt): array
    {
        if (! self::postgis()) {
            $parsed = self::mustParse($wkt);

            return match ($parsed['type']) {
                'Point' => [(float) $parsed['coordinates'][1], (float) $parsed['coordinates'][0]],
                'LineString' => self::averagePoint($parsed['coordinates']),
                'Polygon' => self::ringCentroid($parsed['coordinates'][0]),
            };
        }

        $result = DB::selectOne(
            'SELECT ST_Y(ST_Centroid(ST_GeomFromText(?, 4326))) as lat,
                    ST_X(ST_Centroid(ST_GeomFromText(?, 4326))) as lon',
            [$wkt, $wkt]
        );

        return [(float) $result->lat, (float) $result->lon];
    }

    /**
     * Get bounding box of a geometry.
     *
     * @return array [min_lon, min_lat, max_lon, max_lat]
     */
    public static function boundingBox(string $wkt): array
    {
        if (! self::postgis()) {
            $points = self::allPoints(self::mustParse($wkt));
            $minLon = $minLat = INF;
            $maxLon = $maxLat = -INF;

            foreach ($points as [$lon, $lat]) {
                $minLon = min($minLon, $lon);
                $minLat = min($minLat, $lat);
                $maxLon = max($maxLon, $lon);
                $maxLat = max($maxLat, $lat);
            }

            return [(float) $minLon, (float) $minLat, (float) $maxLon, (float) $maxLat];
        }

        $result = DB::selectOne(
            'SELECT ST_XMin(ST_GeomFromText(?, 4326)) as min_lon,
                    ST_YMin(ST_GeomFromText(?, 4326)) as min_lat,
                    ST_XMax(ST_GeomFromText(?, 4326)) as max_lon,
                    ST_YMax(ST_GeomFromText(?, 4326)) as max_lat',
            [$wkt, $wkt, $wkt, $wkt]
        );

        return [
            (float) $result->min_lon,
            (float) $result->min_lat,
            (float) $result->max_lon,
            (float) $result->max_lat,
        ];
    }

    /**
     * Create a buffer around a geometry.
     *
     * @param  string  $wkt  Geometry in WKT format
     * @param  float  $distance  Buffer distance in meters
     * @return string Buffered geometry in WKT format
     */
    public static function buffer(string $wkt, float $distance): string
    {
        if (! self::postgis()) {
            $parsed = self::mustParse($wkt);

            if ($distance <= 0) {
                return $wkt;
            }

            return $parsed['type'] === 'Point'
                ? self::bufferPoint($parsed['coordinates'], $distance)
                : self::bufferBounds(self::allPoints($parsed), $distance);
        }

        $result = DB::selectOne(
            'SELECT ST_AsText(ST_Buffer(ST_GeomFromText(?, 4326)::geography, ?)::geometry) as wkt',
            [$wkt, $distance]
        );

        return $result->wkt;
    }

    /**
     * Check if two geometries intersect.
     *
     * @param  string  $wkt1  First geometry in WKT format
     * @param  string  $wkt2  Second geometry in WKT format
     */
    public static function intersects(string $wkt1, string $wkt2): bool
    {
        if (! self::postgis()) {
            return self::predicateIntersects(self::mustParse($wkt1), self::mustParse($wkt2));
        }

        $result = DB::selectOne(
            'SELECT ST_Intersects(
                ST_GeomFromText(?, 4326),
                ST_GeomFromText(?, 4326)
            ) as intersects',
            [$wkt1, $wkt2]
        );

        return (bool) $result->intersects;
    }

    /**
     * Check if first geometry contains the second.
     *
     * @param  string  $wkt1  Container geometry in WKT format
     * @param  string  $wkt2  Contained geometry in WKT format
     */
    public static function contains(string $wkt1, string $wkt2): bool
    {
        if (! self::postgis()) {
            return self::predicateContains(self::mustParse($wkt1), self::mustParse($wkt2));
        }

        $result = DB::selectOne(
            'SELECT ST_Contains(
                ST_GeomFromText(?, 4326),
                ST_GeomFromText(?, 4326)
            ) as contains',
            [$wkt1, $wkt2]
        );

        return (bool) $result->contains;
    }

    /**
     * Check if first geometry is within the second.
     *
     * @param  string  $wkt1  Inner geometry in WKT format
     * @param  string  $wkt2  Outer geometry in WKT format
     */
    public static function within(string $wkt1, string $wkt2): bool
    {
        if (! self::postgis()) {
            return self::predicateContains(self::mustParse($wkt2), self::mustParse($wkt1));
        }

        $result = DB::selectOne(
            'SELECT ST_Within(
                ST_GeomFromText(?, 4326),
                ST_GeomFromText(?, 4326)
            ) as within',
            [$wkt1, $wkt2]
        );

        return (bool) $result->within;
    }

    /**
     * Calculate the area of a geometry in square meters.
     *
     * @param  string  $wkt  Geometry in WKT format
     * @return float Area in square meters
     */
    public static function area(string $wkt): float
    {
        if (! self::postgis()) {
            $parsed = self::mustParse($wkt);

            if ($parsed['type'] !== 'Polygon') {
                return 0.0;
            }

            $area = self::ringArea($parsed['coordinates'][0]);
            foreach (array_slice($parsed['coordinates'], 1) as $hole) {
                $area -= self::ringArea($hole);
            }

            return max($area, 0.0);
        }

        $result = DB::selectOne(
            'SELECT ST_Area(ST_GeomFromText(?, 4326)::geography) as area',
            [$wkt]
        );

        return (float) $result->area;
    }

    /**
     * Calculate the length/perimeter of a geometry in meters.
     *
     * @param  string  $wkt  Geometry in WKT format
     * @return float Length in meters
     */
    public static function length(string $wkt): float
    {
        if (! self::postgis()) {
            $parsed = self::mustParse($wkt);

            if ($parsed['type'] === 'Point') {
                return 0.0;
            }

            if ($parsed['type'] === 'LineString') {
                return self::pathLength($parsed['coordinates']);
            }

            $total = 0.0;
            foreach ($parsed['coordinates'] as $ring) {
                $total += self::pathLength($ring);
            }

            return $total;
        }

        $result = DB::selectOne(
            'SELECT ST_Length(ST_GeomFromText(?, 4326)::geography) as length',
            [$wkt]
        );

        return (float) $result->length;
    }

    /**
     * Create a LineString from coordinates array.
     *
     * @param  array  $coordinates  Array of [longitude, latitude] pairs
     * @return string WKT linestring
     */
    public static function makeLineString(array $coordinates): string
    {
        $points = array_map(function ($coord) {
            return "{$coord[0]} {$coord[1]}";
        }, $coordinates);

        return 'LINESTRING('.implode(', ', $points).')';
    }

    /**
     * Whether the active connection can evaluate PostGIS functions.
     */
    private static function postgis(): bool
    {
        try {
            return DB::connection()->getDriverName() === 'pgsql';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Parse a WKT geometry into ['type' => ..., 'coordinates' => ...].
     *
     * @return array{type: string, coordinates: array}|null
     */
    private static function parseWkt(string $wkt): ?array
    {
        if (! preg_match('/^(POINT|LINESTRING|POLYGON)\s*\((.*)\)\s*$/is', trim($wkt), $match)) {
            return null;
        }

        $type = strtoupper($match[1]);
        $body = trim($match[2]);

        if ($type === 'POINT') {
            $points = self::parsePairs($body);

            return count($points) === 1
                ? ['type' => 'Point', 'coordinates' => $points[0]]
                : null;
        }

        if ($type === 'LINESTRING') {
            $points = self::parsePairs($body);

            return count($points) >= 2
                ? ['type' => 'LineString', 'coordinates' => $points]
                : null;
        }

        if (str_contains($body, '(')) {
            if (! preg_match_all('/\(([^()]*)\)/', $body, $rings)) {
                return null;
            }

            $parsed = [];
            foreach ($rings[1] as $ring) {
                $points = self::parsePairs(trim($ring));
                if (count($points) < 4) {
                    return null;
                }
                $parsed[] = $points;
            }

            return $parsed === [] ? null : ['type' => 'Polygon', 'coordinates' => $parsed];
        }

        $ring = self::parsePairs($body);

        return count($ring) >= 4
            ? ['type' => 'Polygon', 'coordinates' => [$ring]]
            : null;
    }

    /**
     * Parse "x y, x y, ..." into [lon, lat] pairs.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    private static function parsePairs(string $text): array
    {
        $pairs = [];

        foreach (explode(',', $text) as $chunk) {
            $parts = preg_split('/\s+/', trim($chunk));
            if ($parts === false || count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
                return [];
            }
            $pairs[] = [(float) $parts[0], (float) $parts[1]];
        }

        return $pairs;
    }

    /**
     * @return array{type: string, coordinates: array}
     */
    private static function mustParse(string $wkt): array
    {
        $parsed = self::parseWkt($wkt);

        if ($parsed === null) {
            throw new \RuntimeException('Invalid WKT geometry');
        }

        return $parsed;
    }

    /**
     * All [lon, lat] vertices of a parsed geometry.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    private static function allPoints(array $parsed): array
    {
        return match ($parsed['type']) {
            'Point' => [$parsed['coordinates']],
            'LineString' => $parsed['coordinates'],
            'Polygon' => array_merge(...$parsed['coordinates']),
        };
    }

    /**
     * Line segments of a parsed geometry as [start, end] vertex pairs.
     *
     * @return array<int, array{0: array, 1: array}>
     */
    private static function segmentsOf(array $parsed): array
    {
        $lines = match ($parsed['type']) {
            'Point' => [],
            'LineString' => [$parsed['coordinates']],
            'Polygon' => $parsed['coordinates'],
        };

        $segments = [];
        foreach ($lines as $line) {
            foreach (self::closedRing($line) as $i => $point) {
                if ($i === 0) {
                    continue;
                }
                $segments[] = [$line[$i - 1], $point];
            }
        }

        return $segments;
    }

    /**
     * Ensure a ring/path ends at its start so edge loops cover the full outline.
     *
     * @return array<int, array>
     */
    private static function closedRing(array $points): array
    {
        $first = $points[0] ?? null;
        $last = $points[count($points) - 1] ?? null;

        if ($first !== null && $last !== null && ($first[0] !== $last[0] || $first[1] !== $last[1])) {
            $points[] = $first;
        }

        return $points;
    }

    private static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lon2 - $lon1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return 2 * self::EARTH_RADIUS * asin(min(1.0, sqrt($a)));
    }

    /**
     * @return float Length in meters
     */
    private static function pathLength(array $points): float
    {
        $total = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $total += self::haversine($points[$i - 1][1], $points[$i - 1][0], $points[$i][1], $points[$i][0]);
        }

        return $total;
    }

    /**
     * Spherical polygon area (Chamberlain-Duquette), in square meters.
     */
    private static function ringArea(array $ring): float
    {
        $points = self::closedRing($ring);
        $sum = 0.0;

        for ($i = 0, $n = count($points); $i < $n - 1; $i++) {
            $sum += deg2rad($points[$i + 1][0] - $points[$i][0])
                * (2 + sin(deg2rad($points[$i][1])) + sin(deg2rad($points[$i + 1][1])));
        }

        return abs($sum) * self::EARTH_RADIUS ** 2 / 2;
    }

    /**
     * Area-weighted centroid of a ring, returned as [lat, lon].
     *
     * @return array{0: float, 1: float}
     */
    private static function ringCentroid(array $ring): array
    {
        $points = self::closedRing($ring);
        $crossSum = 0.0;
        $x = 0.0;
        $y = 0.0;

        for ($i = 0, $n = count($points); $i < $n - 1; $i++) {
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[$i + 1];
            $cross = $x1 * $y2 - $x2 * $y1;
            $crossSum += $cross;
            $x += ($x1 + $x2) * $cross;
            $y += ($y1 + $y2) * $cross;
        }

        if (abs($crossSum) < 1e-12) {
            return self::averagePoint($points);
        }

        return [$y / (6 * $crossSum / 2), $x / (6 * $crossSum / 2)];
    }

    /**
     * Vertex average, returned as [lat, lon].
     *
     * @return array{0: float, 1: float}
     */
    private static function averagePoint(array $points): array
    {
        $sumX = 0.0;
        $sumY = 0.0;
        $n = count($points);

        foreach ($points as [$px, $py]) {
            $sumX += $px;
            $sumY += $py;
        }

        return [$sumY / $n, $sumX / $n];
    }

    /**
     * Ray-casting point-in-ring test with [lon, lat] vertices.
     */
    private static function pointInRing(array $point, array $ring): bool
    {
        [$x, $y] = $point;
        $inside = false;
        $n = count($ring);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i, $i++) {
            $xi = $ring[$i][0];
            $yi = $ring[$i][1];
            $xj = $ring[$j][0];
            $yj = $ring[$j][1];

            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Whether [lon, lat] lies on any segment of the line.
     */
    private static function pointOnLine(array $point, array $line): bool
    {
        for ($i = 1, $n = count($line); $i < $n; $i++) {
            if (self::sign(self::orientation($line[$i - 1], $line[$i], $point)) === 0
                && self::onSegment($line[$i - 1], $point, $line[$i])) {
                return true;
            }
        }

        return false;
    }

    private static function sign(float $value): int
    {
        if ($value > 1e-12) {
            return 1;
        }

        if ($value < -1e-12) {
            return -1;
        }

        return 0;
    }

    private static function orientation(array $p, array $q, array $r): float
    {
        return ($q[0] - $p[0]) * ($r[1] - $p[1]) - ($q[1] - $p[1]) * ($r[0] - $p[0]);
    }

    private static function onSegment(array $p, array $q, array $r): bool
    {
        return $q[0] <= max($p[0], $r[0]) + 1e-12
            && $q[0] >= min($p[0], $r[0]) - 1e-12
            && $q[1] <= max($p[1], $r[1]) + 1e-12
            && $q[1] >= min($p[1], $r[1]) - 1e-12;
    }

    /**
     * Segments touch or cross (shared endpoints count).
     */
    private static function segmentsIntersect(array $p1, array $p2, array $p3, array $p4): bool
    {
        $s1 = self::sign(self::orientation($p1, $p2, $p3));
        $s2 = self::sign(self::orientation($p1, $p2, $p4));
        $s3 = self::sign(self::orientation($p3, $p4, $p1));
        $s4 = self::sign(self::orientation($p3, $p4, $p2));

        if ($s1 !== $s2 && $s3 !== $s4) {
            return true;
        }
        if ($s1 === 0 && self::onSegment($p1, $p3, $p2)) {
            return true;
        }
        if ($s2 === 0 && self::onSegment($p1, $p4, $p2)) {
            return true;
        }
        if ($s3 === 0 && self::onSegment($p3, $p1, $p4)) {
            return true;
        }
        if ($s4 === 0 && self::onSegment($p3, $p2, $p4)) {
            return true;
        }

        return false;
    }

    /**
     * Segment interiors cross (touching does not count).
     */
    private static function segmentsCross(array $p1, array $p2, array $p3, array $p4): bool
    {
        $s1 = self::sign(self::orientation($p1, $p2, $p3));
        $s2 = self::sign(self::orientation($p1, $p2, $p4));
        $s3 = self::sign(self::orientation($p3, $p4, $p1));
        $s4 = self::sign(self::orientation($p3, $p4, $p2));

        return $s1 !== 0 && $s2 !== 0 && $s3 !== 0 && $s4 !== 0 && $s1 !== $s2 && $s3 !== $s4;
    }

    private static function pointIntersectsGeometry(array $point, array $geometry): bool
    {
        return match ($geometry['type']) {
            'Polygon' => self::pointInRing($point, $geometry['coordinates'][0]),
            'LineString' => self::pointOnLine($point, $geometry['coordinates']),
            default => false,
        };
    }

    private static function allVerticesInPolygon(array $geometry, array $polygon): bool
    {
        foreach (self::allPoints($geometry) as $point) {
            if (! self::pointInRing($point, $polygon['coordinates'][0])) {
                return false;
            }
        }

        return self::allPoints($geometry) !== [];
    }

    private static function predicateIntersects(array $a, array $b): bool
    {
        if ($a['type'] === 'Point' && $b['type'] === 'Point') {
            return $a['coordinates'] == $b['coordinates'];
        }
        if ($a['type'] === 'Point') {
            return self::pointIntersectsGeometry($a['coordinates'], $b);
        }
        if ($b['type'] === 'Point') {
            return self::pointIntersectsGeometry($b['coordinates'], $a);
        }

        foreach (self::segmentsOf($a) as $segmentA) {
            foreach (self::segmentsOf($b) as $segmentB) {
                if (self::segmentsIntersect($segmentA[0], $segmentA[1], $segmentB[0], $segmentB[1])) {
                    return true;
                }
            }
        }

        if ($a['type'] === 'Polygon' && self::allVerticesInPolygon($b, $a)) {
            return true;
        }

        return $b['type'] === 'Polygon' && self::allVerticesInPolygon($a, $b);
    }

    private static function predicateContains(array $a, array $b): bool
    {
        if ($a['type'] === 'Point') {
            return $b['type'] === 'Point' && $a['coordinates'] == $b['coordinates'];
        }
        if ($b['type'] === 'Point') {
            return self::pointIntersectsGeometry($b['coordinates'], $a);
        }

        if ($a['type'] === 'Polygon') {
            if (! self::allVerticesInPolygon($b, $a)) {
                return false;
            }

            foreach (self::segmentsOf($b) as $segmentB) {
                foreach (self::segmentsOf($a) as $segmentA) {
                    if (self::segmentsCross($segmentA[0], $segmentA[1], $segmentB[0], $segmentB[1])) {
                        return false;
                    }
                }
            }

            return true;
        }

        if ($a['type'] === 'LineString' && $b['type'] === 'LineString') {
            foreach ($b['coordinates'] as $point) {
                if (! self::pointOnLine($point, $a['coordinates'])) {
                    return false;
                }
            }

            return $b['coordinates'] !== [];
        }

        return false;
    }

    /**
     * Approximate geodesic buffer of a point as a circle polygon.
     */
    private static function bufferPoint(array $lonLat, float $distance): string
    {
        [$lon, $lat] = $lonLat;
        $deltaLat = rad2deg($distance / self::EARTH_RADIUS);
        $cosLat = cos(deg2rad($lat));
        $deltaLon = abs($cosLat) > 1e-12 ? $deltaLat / $cosLat : $deltaLat;

        $segments = 48;
        $ring = [];
        for ($i = 0; $i < $segments; $i++) {
            $angle = 2 * M_PI * $i / $segments;
            $ring[] = [$lon + $deltaLon * cos($angle), $lat + $deltaLat * sin($angle)];
        }
        $ring[] = $ring[0];

        return self::polygonWkt([$ring]);
    }

    /**
     * Approximate buffer for lines/polygons as the expanded bounding box.
     *
     * @param  array<int, array{0: float, 1: float}>  $points
     */
    private static function bufferBounds(array $points, float $distance): string
    {
        $minLon = $minLat = INF;
        $maxLon = $maxLat = -INF;

        foreach ($points as [$lon, $lat]) {
            $minLon = min($minLon, $lon);
            $minLat = min($minLat, $lat);
            $maxLon = max($maxLon, $lon);
            $maxLat = max($maxLat, $lat);
        }

        $deltaLat = rad2deg($distance / self::EARTH_RADIUS);
        $cosLat = cos(deg2rad(max(abs($minLat), abs($maxLat))));
        $deltaLon = abs($cosLat) > 1e-12 ? $deltaLat / $cosLat : $deltaLat;

        return self::polygonWkt([[
            [$minLon - $deltaLon, $minLat - $deltaLat],
            [$maxLon + $deltaLon, $minLat - $deltaLat],
            [$maxLon + $deltaLon, $maxLat + $deltaLat],
            [$minLon - $deltaLon, $maxLat + $deltaLat],
            [$minLon - $deltaLon, $minLat - $deltaLat],
        ]]);
    }

    /**
     * @param  array<int, array<int, array{0: float, 1: float}>>  $rings
     */
    private static function polygonWkt(array $rings): string
    {
        $formatted = array_map(
            static fn (array $ring) => '('.implode(', ', array_map(
                static fn (array $point) => self::format($point[0]).' '.self::format($point[1]),
                $ring
            )).')',
            $rings
        );

        return 'POLYGON('.implode(', ', $formatted).')';
    }

    /**
     * Build a WKT string from GeoJSON coordinates.
     */
    private static function coordinatesToWkt(string $type, array $coordinates): ?string
    {
        $point = static fn (array $p): string => self::format((float) $p[0]).' '.self::format((float) $p[1]);
        $ring = static fn (array $points): string => '('.implode(', ', array_map($point, $points)).')';

        return match ($type) {
            'Point' => sprintf('POINT(%s)', $point($coordinates)),
            'LineString' => sprintf('LINESTRING(%s)', implode(', ', array_map($point, $coordinates))),
            'Polygon' => sprintf('POLYGON(%s)', implode(', ', array_map($ring, $coordinates))),
            'MultiPoint' => sprintf('MULTIPOINT(%s)', implode(', ', array_map($point, $coordinates))),
            'MultiLineString' => sprintf('MULTILINESTRING(%s)', implode(', ', array_map($ring, $coordinates))),
            'MultiPolygon' => sprintf('MULTIPOLYGON(%s)', implode(', ', array_map(
                static fn (array $polygon): string => '('.implode(', ', array_map($ring, $polygon)).')',
                $coordinates
            ))),
            default => null,
        };
    }

    private static function format(float $value): string
    {
        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }
}
