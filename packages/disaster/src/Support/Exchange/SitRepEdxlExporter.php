<?php

namespace Lumina\Disaster\Support\Exchange;

use Lumina\Disaster\Models\SitRep;
use Lumina\Disaster\Services\SitRepExporter;

/**
 * EDXL-SitRep lite XML from SitRepExporter JSON shape.
 *
 * Supported fields:
 * - incidentID / incidentTitle / incidentStatus / organizationID
 * - preparedAt / preparedBy / preparedByUserId
 * - report.summary / casualties / resources / lifelinesStatus / nextSteps
 */
final class SitRepEdxlExporter
{
    public static function toXml(SitRep $sitrep): string
    {
        $shape = SitRepExporter::forSitRep($sitrep);
        $envelope = $shape['EDXLSitRep'] ?? [];
        $report = $envelope['report'] ?? [];

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<EDXLSitRep xmlns="urn:oasis:names:tc:emergency:EDXL:SitRep:1.0" schemaVersion="1.0-lite">'
            .'<incidentID>'.XmlSupport::text((string) ($envelope['incidentID'] ?? '')).'</incidentID>'
            .'<incidentTitle>'.XmlSupport::text((string) ($envelope['incidentTitle'] ?? '')).'</incidentTitle>'
            .'<incidentStatus>'.XmlSupport::text((string) ($envelope['incidentStatus'] ?? '')).'</incidentStatus>'
            .'<organizationID>'.XmlSupport::text((string) ($envelope['organizationID'] ?? $sitrep->organization_id)).'</organizationID>'
            .'<preparedAt>'.XmlSupport::text((string) ($envelope['preparedAt'] ?? '')).'</preparedAt>'
            .'<preparedBy>'.XmlSupport::text((string) ($envelope['preparedBy'] ?? '')).'</preparedBy>'
            .'<preparedByUserId>'.XmlSupport::text((string) ($envelope['preparedByUserId'] ?? '')).'</preparedByUserId>'
            .'<Report>'
            .'<Summary>'.XmlSupport::text((string) ($report['summary'] ?? '')).'</Summary>'
            .'<Casualties>'.self::jsonElement($report['casualties'] ?? []).'</Casualties>'
            .'<Resources>'.self::jsonElement($report['resources'] ?? []).'</Resources>'
            .'<LifelinesStatus>'.self::jsonElement($report['lifelinesStatus'] ?? []).'</LifelinesStatus>'
            .'<NextSteps>'.XmlSupport::text((string) ($report['nextSteps'] ?? '')).'</NextSteps>'
            .'</Report>'
            .'</EDXLSitRep>';
    }

    /**
     * @param  list<int|string>  $targetOrganizationIds
     */
    public static function toDistributionXml(SitRep $sitrep, array $targetOrganizationIds = []): string
    {
        return DistributionElement::wrap(
            self::toXml($sitrep),
            senderId: 'org:'.(string) $sitrep->organization_id,
            targetOrganizationIds: $targetOrganizationIds,
            contentDescription: 'EDXL-SitRep',
            distributionType: 'Report',
        );
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     */
    protected static function jsonElement(array $value): string
    {
        return XmlSupport::text(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }
}
