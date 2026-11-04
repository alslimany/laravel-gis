<?php

namespace Lumina\Disaster\Services;

use Illuminate\Support\Collection;
use Lumina\Disaster\Models\DamagePoint;
use Lumina\Disaster\Support\Enums\DamageLevel;
use Lumina\Disaster\Support\Enums\DamageReviewStatus;
use Lumina\Disaster\Support\GeometryReader;

final class DamageAggregationService
{
    /**
     * Official totals exclude non-verified assessments.
     *
     * @return array{
     *     official: array{by_zone: list<array<string, mixed>>, by_level: array<string, int>, total: int, people_affected: int},
     *     all: array{by_zone: list<array<string, mixed>>, by_level: array<string, int>, total: int, people_affected: int},
     *     table: list<array<string, mixed>>,
     *     geojson: array{type: string, features: list<array<string, mixed>>}
     * }
     */
    public function aggregate(int $organizationId, ?int $incidentId = null): array
    {
        $points = DamagePoint::query()
            ->forOrganization($organizationId)
            ->when($incidentId, fn ($q) => $q->where('incident_id', $incidentId))
            ->with('photos')
            ->orderByDesc('assessed_at')
            ->orderByDesc('id')
            ->get();

        $verified = $points->filter(fn (DamagePoint $p) => $p->isOfficial())->values();

        return [
            'official' => $this->summarize($verified),
            'all' => $this->summarize($points),
            'table' => $this->tableRows($verified),
            'geojson' => $this->geoJsonPayload($verified),
        ];
    }

    /**
     * @param  Collection<int, DamagePoint>  $points
     * @return array{by_zone: list<array<string, mixed>>, by_level: array<string, int>, total: int, people_affected: int}
     */
    protected function summarize(Collection $points): array
    {
        $byLevel = [];
        foreach (DamageLevel::cases() as $level) {
            $byLevel[$level->value] = 0;
        }

        $zoneBuckets = [];
        $people = 0;

        foreach ($points as $point) {
            $level = $point->damage_level instanceof DamageLevel
                ? $point->damage_level->value
                : (string) $point->damage_level;
            $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;
            $people += (int) ($point->people_affected ?? 0);

            $zone = $point->zoneKey();
            if (! isset($zoneBuckets[$zone])) {
                $zoneBuckets[$zone] = [
                    'zone' => $zone,
                    'total' => 0,
                    'people_affected' => 0,
                    'by_level' => array_fill_keys(array_map(fn (DamageLevel $l) => $l->value, DamageLevel::cases()), 0),
                ];
            }
            $zoneBuckets[$zone]['total']++;
            $zoneBuckets[$zone]['people_affected'] += (int) ($point->people_affected ?? 0);
            $zoneBuckets[$zone]['by_level'][$level] = ($zoneBuckets[$zone]['by_level'][$level] ?? 0) + 1;
        }

        ksort($zoneBuckets);

        return [
            'by_zone' => array_values($zoneBuckets),
            'by_level' => $byLevel,
            'total' => $points->count(),
            'people_affected' => $people,
        ];
    }

    /**
     * @param  Collection<int, DamagePoint>  $points
     * @return list<array<string, mixed>>
     */
    protected function tableRows(Collection $points): array
    {
        return $points->map(function (DamagePoint $point) {
            $level = $point->damage_level instanceof DamageLevel
                ? $point->damage_level->value
                : (string) $point->damage_level;

            return [
                'id' => $point->id,
                'zone' => $point->zoneKey(),
                'damage_level' => $level,
                'structure_type' => $point->structure_type,
                'habitability' => $point->habitability instanceof \BackedEnum
                    ? $point->habitability->value
                    : $point->habitability,
                'people_affected' => $point->people_affected,
                'review_status' => DamageReviewStatus::Verified->value,
                'incident_id' => $point->incident_id,
                'lifeline_component_id' => $point->lifeline_component_id,
                'photo_count' => $point->photos->count(),
                'assessed_at' => $point->assessed_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * GeoJSON-ish FeatureCollection for map layer consumption (verified only).
     *
     * @param  Collection<int, DamagePoint>  $points
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    protected function geoJsonPayload(Collection $points): array
    {
        $features = [];

        foreach ($points as $point) {
            $coords = GeometryReader::pointCoordinates($point->geom);
            $geometry = $coords === null
                ? null
                : [
                    'type' => 'Point',
                    'coordinates' => [$coords['longitude'], $coords['latitude']],
                ];

            $level = $point->damage_level instanceof DamageLevel
                ? $point->damage_level->value
                : (string) $point->damage_level;

            $features[] = [
                'type' => 'Feature',
                'id' => $point->id,
                'geometry' => $geometry,
                'properties' => [
                    'damage_level' => $level,
                    'zone' => $point->zoneKey(),
                    'structure_type' => $point->structure_type,
                    'people_affected' => $point->people_affected,
                    'review_status' => DamageReviewStatus::Verified->value,
                    'urgent_needs' => $point->urgent_needs,
                    'hazards_present' => $point->hazards_present,
                ],
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }
}
