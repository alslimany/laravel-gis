<?php

namespace Lumina\Disaster\Support\Exchange;

use Lumina\Disaster\Models\ResourceRequest;
use Lumina\Disaster\Services\ResourceRequestExporter;
use Lumina\Disaster\Support\Enums\ResourceRequestStatus;

/**
 * EDXL-RM lite XML from ResourceRequestExporter shape.
 *
 * MessageContentType mapping:
 * - requested → RequestResource
 * - approved → ResponseToRequestResource
 * - matched|dispatched → CommitResource
 * - delivered|cancelled → ReleaseResource
 *
 * Supported fields: originatingMessageID, incidentID, organizationID, requester,
 * ResourceInformation (type/qty/priority/needed/status/notes), AssignmentInformation.
 */
final class RmExporter
{
    public static function messageContentType(ResourceRequest $request): string
    {
        $status = $request->status instanceof ResourceRequestStatus
            ? $request->status
            : ResourceRequestStatus::tryFrom((string) $request->status);

        return match ($status) {
            ResourceRequestStatus::Approved => 'ResponseToRequestResource',
            ResourceRequestStatus::Matched, ResourceRequestStatus::Dispatched => 'CommitResource',
            ResourceRequestStatus::Delivered, ResourceRequestStatus::Cancelled => 'ReleaseResource',
            default => 'RequestResource',
        };
    }

    public static function toXml(ResourceRequest $request): string
    {
        $shape = ResourceRequestExporter::toEdxlRmShape($request);
        $envelope = $shape['EDXLResourceMessage'] ?? [];
        $messageType = self::messageContentType($request);
        $info = $envelope['resourceInformation'] ?? [];
        $type = $info['resourceType'] ?? [];
        $requester = $envelope['requester'] ?? [];

        $assignmentsXml = '';
        foreach ($envelope['assignmentInformation'] ?? [] as $assignment) {
            $assignmentsXml .= '<Assignment>'
                .'<AssignmentID>'.XmlSupport::text((string) ($assignment['assignmentId'] ?? '')).'</AssignmentID>'
                .'<Quantity>'.XmlSupport::text((string) (int) ($assignment['quantity'] ?? 0)).'</Quantity>'
                .'<ETA>'.XmlSupport::text((string) ($assignment['eta'] ?? '')).'</ETA>'
                .'<DeliveredAt>'.XmlSupport::text((string) ($assignment['deliveredAt'] ?? '')).'</DeliveredAt>'
                .'<WarehouseID>'.XmlSupport::text((string) ($assignment['warehouseId'] ?? '')).'</WarehouseID>'
                .'<WarehouseName>'.XmlSupport::text((string) ($assignment['warehouseName'] ?? '')).'</WarehouseName>'
                .'<AvlUnitID>'.XmlSupport::text((string) ($assignment['avlUnitId'] ?? '')).'</AvlUnitID>'
                .'<AvlUnitCode>'.XmlSupport::text((string) ($assignment['avlUnitCode'] ?? '')).'</AvlUnitCode>'
                .'</Assignment>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<EDXLResourceMessage xmlns="urn:oasis:names:tc:emergency:EDXL:RM:1.0" schemaVersion="1.0-lite">'
            .'<messageContentType>'.XmlSupport::text($messageType).'</messageContentType>'
            .'<originatingMessageID>'.XmlSupport::text((string) ($envelope['originatingMessageID'] ?? ('dm-req-'.$request->id))).'</originatingMessageID>'
            .'<incidentID>'.XmlSupport::text((string) ($envelope['incidentID'] ?? '')).'</incidentID>'
            .'<organizationID>'.XmlSupport::text((string) ($envelope['organizationID'] ?? $request->organization_id)).'</organizationID>'
            .'<Requester>'
            .'<UserID>'.XmlSupport::text((string) ($requester['userId'] ?? '')).'</UserID>'
            .'<Name>'.XmlSupport::text((string) ($requester['name'] ?? '')).'</Name>'
            .'</Requester>'
            .'<ResourceInformation>'
            .'<ResourceType>'
            .'<Name>'.XmlSupport::text((string) ($type['name'] ?? '')).'</Name>'
            .'<Category>'.XmlSupport::text((string) ($type['category'] ?? '')).'</Category>'
            .'<Capability>'.XmlSupport::text((string) ($type['capability'] ?? '')).'</Capability>'
            .'<Unit>'.XmlSupport::text((string) ($type['unit'] ?? 'ea')).'</Unit>'
            .'</ResourceType>'
            .'<Quantity>'.XmlSupport::text((string) (int) ($info['quantity'] ?? 0)).'</Quantity>'
            .'<Priority>'.XmlSupport::text((string) ($info['priority'] ?? 'normal')).'</Priority>'
            .'<DateTimeNeeded>'.XmlSupport::text((string) ($info['dateTimeNeeded'] ?? '')).'</DateTimeNeeded>'
            .'<Status>'.XmlSupport::text((string) ($info['status'] ?? '')).'</Status>'
            .'<Notes>'.XmlSupport::text((string) ($info['notes'] ?? '')).'</Notes>'
            .'</ResourceInformation>'
            .'<AssignmentInformation>'.$assignmentsXml.'</AssignmentInformation>'
            .'</EDXLResourceMessage>';
    }

    /**
     * @param  list<int|string>  $targetOrganizationIds
     */
    public static function toDistributionXml(ResourceRequest $request, array $targetOrganizationIds = []): string
    {
        return DistributionElement::wrap(
            self::toXml($request),
            senderId: 'org:'.(string) $request->organization_id,
            targetOrganizationIds: $targetOrganizationIds,
            contentDescription: 'EDXL-RM',
            distributionType: 'Request',
        );
    }
}
