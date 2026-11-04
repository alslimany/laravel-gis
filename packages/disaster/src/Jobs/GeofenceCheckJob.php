<?php

namespace Lumina\Disaster\Jobs;

use App\Helpers\SpatialHelper;
use App\Plugins\Contracts\OrganizationScopedJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Models\DisseminationLog;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Support\GeometryReader;

class GeofenceCheckJob implements OrganizationScopedJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $organizationId) {}

    public function handle(): int
    {
        $radiusM = (float) config('disaster.geofence.incident_radius_m', 500);
        $written = 0;

        $incidents = Incident::query()
            ->forOrganization($this->organizationId)
            ->where('status', 'open')
            ->whereNotNull('geom')
            ->get(['id', 'external_id', 'title', 'geom']);

        if ($incidents->isEmpty()) {
            return 0;
        }

        $units = AvlUnit::query()
            ->forOrganization($this->organizationId)
            ->whereNotNull('geom')
            ->get(['id', 'unit_id', 'geom']);

        foreach ($units as $unit) {
            $unitCoords = GeometryReader::pointCoordinates($unit->geom);
            if ($unitCoords === null) {
                continue;
            }

            foreach ($incidents as $incident) {
                $incidentCoords = GeometryReader::pointCoordinates($incident->geom);
                if ($incidentCoords === null) {
                    continue;
                }

                $distance = SpatialHelper::distance(
                    $incidentCoords['latitude'],
                    $incidentCoords['longitude'],
                    $unitCoords['latitude'],
                    $unitCoords['longitude'],
                );

                if ($distance > $radiusM) {
                    continue;
                }

                DisseminationLog::query()->create([
                    'organization_id' => $this->organizationId,
                    'channel' => 'geofence',
                    'payload' => [
                        'avl_unit_id' => $unit->id,
                        'unit_id' => $unit->unit_id,
                        'incident_id' => $incident->id,
                        'incident_external_id' => $incident->external_id,
                        'distance_m' => round($distance, 2),
                        'radius_m' => $radiusM,
                    ],
                    'created_at' => now(),
                ]);
                $written++;
            }
        }

        return $written;
    }
}
