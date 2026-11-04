<?php

namespace Lumina\Disaster\Support\Exchange;

use App\Models\User;
use Illuminate\Support\Carbon;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\SitRep;
use Lumina\Disaster\Services\SitRepExporter;

/**
 * Imports EDXL-SitRep lite into dm_sitreps.
 *
 * @see SitRepEdxlExporter for supported field map
 */
final class SitRepEdxlImporter
{
    public static function import(string $xml, int $organizationId, ?User $actor = null): SitRep
    {
        $doc = XmlSupport::document($xml);
        if ($doc->documentElement?->localName === 'EDXLDistribution') {
            $xml = DistributionElement::unwrap($xml)['inner_xml'];
            $doc = XmlSupport::document($xml);
        }

        if ($doc->documentElement?->localName !== 'EDXLSitRep') {
            throw new \InvalidArgumentException('Expected EDXLSitRep root.');
        }

        $incidentExternalId = XmlSupport::firstText($doc, ['incidentID']);
        if ($incidentExternalId === null || $incidentExternalId === '') {
            throw new \InvalidArgumentException('SitRep incidentID is required.');
        }

        $incident = Incident::query()
            ->forOrganization($organizationId)
            ->where('external_id', $incidentExternalId)
            ->first();

        if ($incident === null) {
            throw new \InvalidArgumentException("Unknown incidentID '{$incidentExternalId}' for organization.");
        }

        $summary = XmlSupport::firstText($doc, ['Report', 'Summary']);
        $nextSteps = XmlSupport::firstText($doc, ['Report', 'NextSteps']);
        $preparedAt = XmlSupport::firstText($doc, ['preparedAt']);
        $preparedByUserId = XmlSupport::firstText($doc, ['preparedByUserId']);

        $payload = [
            'summary' => $summary,
            'casualties' => self::decodeJsonElement(XmlSupport::firstText($doc, ['Report', 'Casualties'])),
            'resources' => self::decodeJsonElement(XmlSupport::firstText($doc, ['Report', 'Resources'])),
            'lifelines_status' => self::decodeJsonElement(XmlSupport::firstText($doc, ['Report', 'LifelinesStatus'])),
            'next_steps' => $nextSteps,
        ];

        $preparerId = $actor?->getKey()
            ?? ($preparedByUserId !== null && $preparedByUserId !== '' ? (int) $preparedByUserId : null);

        $sitrep = SitRep::query()->create([
            'organization_id' => $organizationId,
            'incident_id' => $incident->id,
            'summary' => $payload['summary'],
            'casualties' => $payload['casualties'],
            'resources' => $payload['resources'],
            'lifelines_status' => $payload['lifelines_status'],
            'next_steps' => $payload['next_steps'],
            'prepared_by' => $preparerId,
            'prepared_at' => $preparedAt ? Carbon::parse($preparedAt) : now(),
        ]);

        $preparer = $preparerId ? User::query()->find($preparerId) : null;
        $sitrep->exported_json = SitRepExporter::toEdxlShape($incident, $payload, $preparer, $sitrep);
        $sitrep->save();

        return $sitrep->fresh(['incident', 'preparer']);
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    protected static function decodeJsonElement(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
