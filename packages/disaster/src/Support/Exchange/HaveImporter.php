<?php

namespace Lumina\Disaster\Support\Exchange;

use Lumina\Disaster\Models\MedicalFacility;

/**
 * Imports EDXL-HAVE lite (or DE-wrapped HAVE) into dm_medical_facilities.
 * Upserts by organization_id + facility_id. Geometry is left unchanged on update.
 *
 * @see HaveExporter for supported field map
 */
final class HaveImporter
{
    /**
     * @return list<MedicalFacility>
     */
    public static function import(string $xml, int $organizationId): array
    {
        $doc = XmlSupport::document($xml);
        if ($doc->documentElement?->localName === 'EDXLDistribution') {
            $xml = DistributionElement::unwrap($xml)['inner_xml'];
            $doc = XmlSupport::document($xml);
        }

        if ($doc->documentElement?->localName !== 'HospitalStatus') {
            throw new \InvalidArgumentException('Expected HospitalStatus (EDXL-HAVE) root.');
        }

        $imported = [];
        foreach (XmlSupport::childrenNamed($doc->documentElement, 'Hospital') as $hospital) {
            $facilityId = XmlSupport::firstText($hospital, ['OrganizationInformation', 'OrganizationID']);
            $name = XmlSupport::firstText($hospital, ['OrganizationInformation', 'OrganizationName']);
            if ($facilityId === null || $name === null) {
                throw new \InvalidArgumentException('Hospital missing OrganizationID or OrganizationName.');
            }

            $bedCapacity = (int) (XmlSupport::firstText($hospital, ['HospitalBedCapacityStatus', 'BedCapacity', 'CapacityTotal']) ?? 0);
            $available = (int) (XmlSupport::firstText($hospital, ['HospitalBedCapacityStatus', 'BedCapacity', 'CapacityAvailable']) ?? 0);
            $trauma = XmlSupport::firstText($hospital, ['ServiceCapacity', 'TraumaCenterServiceTraumaLevel']);
            $generator = XmlSupport::firstText($hospital, ['FacilityStatus', 'GeneratorStatus']) ?? 'ONLINE';
            $resourceStatus = XmlSupport::firstText($hospital, ['FacilityStatus', 'ResourceStatus']);

            $attributes = [
                'name' => $name,
                'bed_capacity' => max(0, $bedCapacity),
                'available_beds' => max(0, $available),
                'trauma_level' => $trauma !== null ? (int) $trauma : null,
                'generator_status' => $generator,
            ];
            if ($resourceStatus !== null && $resourceStatus !== '') {
                $attributes['resource_status'] = $resourceStatus;
            }

            $facility = MedicalFacility::query()->updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'facility_id' => $facilityId,
                ],
                $attributes,
            );

            $imported[] = $facility->fresh();
        }

        return $imported;
    }
}
