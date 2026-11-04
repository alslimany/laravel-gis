<?php

namespace Lumina\Disaster\Support\Exchange;

use Illuminate\Support\Collection;
use Lumina\Disaster\Models\MedicalFacility;

/**
 * EDXL-HAVE lite exporter for dm_medical_facilities.
 *
 * Supported fields:
 * - facility_id → Hospital/OrganizationInformation/OrganizationID
 * - name → OrganizationName
 * - bed_capacity → BedCapacity/CapacityTotal
 * - available_beds → BedCapacity/CapacityAvailable
 * - trauma_level → ServiceCapacity/TraumaCenterServiceTraumaLevel
 * - generator_status → FacilityStatus/GeneratorStatus
 * - resource_status → FacilityStatus/ResourceStatus (passthrough)
 */
final class HaveExporter
{
    /**
     * @param  Collection<int, MedicalFacility>|iterable<MedicalFacility>  $facilities
     */
    public static function toXml(iterable $facilities, int $organizationId): string
    {
        $items = $facilities instanceof Collection ? $facilities : collect($facilities);

        $hospitals = $items->map(function (MedicalFacility $facility): string {
            $trauma = $facility->trauma_level !== null
                ? '<TraumaCenterServiceTraumaLevel>'.XmlSupport::text((string) $facility->trauma_level).'</TraumaCenterServiceTraumaLevel>'
                : '';

            return '<Hospital>'
                .'<OrganizationInformation>'
                .'<OrganizationID>'.XmlSupport::text((string) $facility->facility_id).'</OrganizationID>'
                .'<OrganizationName>'.XmlSupport::text((string) $facility->name).'</OrganizationName>'
                .'</OrganizationInformation>'
                .'<HospitalBedCapacityStatus>'
                .'<BedCapacity>'
                .'<CapacityType>TotalBeds</CapacityType>'
                .'<CapacityStatus>Available</CapacityStatus>'
                .'<CapacityTotal>'.XmlSupport::text((string) (int) $facility->bed_capacity).'</CapacityTotal>'
                .'<CapacityAvailable>'.XmlSupport::text((string) (int) $facility->available_beds).'</CapacityAvailable>'
                .'</BedCapacity>'
                .'</HospitalBedCapacityStatus>'
                .'<ServiceCapacity>'.$trauma.'</ServiceCapacity>'
                .'<FacilityStatus>'
                .'<GeneratorStatus>'.XmlSupport::text((string) ($facility->generator_status ?? 'ONLINE')).'</GeneratorStatus>'
                .'<ResourceStatus>'.XmlSupport::text((string) ($facility->resource_status ?? '')).'</ResourceStatus>'
                .'</FacilityStatus>'
                .'</Hospital>';
        })->implode('');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<HospitalStatus xmlns="urn:oasis:names:tc:emergency:EDXL:HAVE:1.0" schemaVersion="1.0-lite">'
            .'<Organization>'
            .'<OrganizationID>'.XmlSupport::text((string) $organizationId).'</OrganizationID>'
            .'</Organization>'
            .'<LastUpdateTime>'.XmlSupport::text(now()->toIso8601String()).'</LastUpdateTime>'
            .$hospitals
            .'</HospitalStatus>';
    }

    /**
     * @param  list<int|string>  $targetOrganizationIds
     */
    public static function toDistributionXml(
        iterable $facilities,
        int $organizationId,
        array $targetOrganizationIds = [],
    ): string {
        return DistributionElement::wrap(
            self::toXml($facilities, $organizationId),
            senderId: 'org:'.$organizationId,
            targetOrganizationIds: $targetOrganizationIds,
            contentDescription: 'EDXL-HAVE',
            distributionType: 'Report',
        );
    }
}
