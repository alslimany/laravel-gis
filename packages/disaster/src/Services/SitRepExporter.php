<?php

namespace Lumina\Disaster\Services;

use App\Models\User;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\SitRep;

final class SitRepExporter
{
    /**
     * EDXL-SitRep-friendly JSON envelope (subset for later full mapping).
     *
     * @param  array{
     *     summary?: string|null,
     *     casualties?: array<string, mixed>|null,
     *     resources?: array<string, mixed>|null,
     *     lifelines_status?: array<string, mixed>|null,
     *     next_steps?: string|null
     * }  $payload
     * @return array<string, mixed>
     */
    public static function toEdxlShape(Incident $incident, array $payload, ?User $preparer = null, ?SitRep $sitrep = null): array
    {
        $preparedAt = $sitrep?->prepared_at?->toIso8601String() ?? now()->toIso8601String();

        return [
            'EDXLSitRep' => [
                'schemaVersion' => '1.0-lite',
                'incidentID' => (string) $incident->external_id,
                'incidentTitle' => $incident->title,
                'incidentStatus' => $incident->status,
                'organizationID' => (string) $incident->organization_id,
                'preparedAt' => $preparedAt,
                'preparedBy' => $preparer?->name ?? $sitrep?->preparer?->name,
                'preparedByUserId' => $preparer?->getKey() ?? $sitrep?->prepared_by,
                'report' => [
                    'summary' => $payload['summary'] ?? $sitrep?->summary,
                    'casualties' => $payload['casualties'] ?? $sitrep?->casualties ?? [],
                    'resources' => $payload['resources'] ?? $sitrep?->resources ?? [],
                    'lifelinesStatus' => $payload['lifelines_status'] ?? $sitrep?->lifelines_status ?? [],
                    'nextSteps' => $payload['next_steps'] ?? $sitrep?->next_steps,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSitRep(SitRep $sitrep): array
    {
        $sitrep->loadMissing(['incident', 'preparer']);

        if (is_array($sitrep->exported_json) && $sitrep->exported_json !== []) {
            return $sitrep->exported_json;
        }

        return self::toEdxlShape($sitrep->incident, [
            'summary' => $sitrep->summary,
            'casualties' => $sitrep->casualties,
            'resources' => $sitrep->resources,
            'lifelines_status' => $sitrep->lifelines_status,
            'next_steps' => $sitrep->next_steps,
        ], $sitrep->preparer, $sitrep);
    }
}
