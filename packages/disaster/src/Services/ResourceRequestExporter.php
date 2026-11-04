<?php

namespace Lumina\Disaster\Services;

use Lumina\Disaster\Models\ResourceRequest;

/**
 * EDXL-RM-friendly ResourceMessage subset for later full mapping.
 *
 * Field map (request → EDXL-RM):
 * - incident.external_id → IncidentID
 * - resourceType.{name,category,capability,unit} → ResourceInformation / ResourceType
 * - quantity → Quantity
 * - priority → Priority
 * - needed_by → DateTimeNeeded (requested delivery window)
 * - status → AssignmentInformation.Status
 * - assignments[] → AssignmentInformation (warehouse / unit source)
 * - notes → ContactInformation / Description
 */
final class ResourceRequestExporter
{
    /**
     * @return array<string, mixed>
     */
    public static function toEdxlRmShape(ResourceRequest $request): array
    {
        $request->loadMissing(['incident', 'resourceType', 'assignments.warehouse', 'assignments.avlUnit', 'requester']);

        $status = $request->status instanceof \BackedEnum
            ? $request->status->value
            : (string) $request->status;

        $priority = $request->priority instanceof \BackedEnum
            ? $request->priority->value
            : (string) $request->priority;

        return [
            'EDXLResourceMessage' => [
                'schemaVersion' => '1.0-lite',
                'messageContentType' => 'RequestResource',
                'originatingMessageID' => 'dm-req-'.$request->id,
                'incidentID' => (string) ($request->incident?->external_id ?? $request->incident_id),
                'organizationID' => (string) $request->organization_id,
                'requester' => [
                    'userId' => $request->requester_user_id,
                    'name' => $request->requester?->name,
                ],
                'resourceInformation' => [
                    'resourceType' => [
                        'name' => $request->resourceType?->name,
                        'category' => $request->resourceType?->category,
                        'capability' => $request->resourceType?->capability,
                        'unit' => $request->resourceType?->unit,
                    ],
                    'quantity' => (int) $request->quantity,
                    'priority' => $priority,
                    'dateTimeNeeded' => $request->needed_by?->toIso8601String(),
                    'status' => $status,
                    'notes' => $request->notes,
                ],
                'assignmentInformation' => $request->assignments->map(fn ($a) => [
                    'assignmentId' => $a->id,
                    'quantity' => (int) $a->quantity,
                    'eta' => $a->eta?->toIso8601String(),
                    'deliveredAt' => $a->delivered_at?->toIso8601String(),
                    'warehouseId' => $a->warehouse_id,
                    'warehouseName' => $a->warehouse?->name,
                    'avlUnitId' => $a->avl_unit_id,
                    'avlUnitCode' => $a->avlUnit?->unit_id,
                ])->values()->all(),
            ],
        ];
    }
}
