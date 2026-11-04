<?php

namespace Lumina\Disaster\Support\Exchange;

use App\Models\User;
use Illuminate\Support\Carbon;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\ResourceRequest;
use Lumina\Disaster\Models\ResourceType;
use Lumina\Disaster\Services\ResourceRequestExporter;
use Lumina\Disaster\Support\Enums\ResourceRequestPriority;
use Lumina\Disaster\Support\Enums\ResourceRequestStatus;

/**
 * Imports EDXL-RM lite into dm_resource_requests (creates/updates request row + type).
 * Assignments are informational on import (not recreated) to avoid ledger side-effects.
 *
 * @see RmExporter for supported field map
 */
final class RmImporter
{
    public static function import(string $xml, int $organizationId, ?User $actor = null): ResourceRequest
    {
        $doc = XmlSupport::document($xml);
        if ($doc->documentElement?->localName === 'EDXLDistribution') {
            $xml = DistributionElement::unwrap($xml)['inner_xml'];
            $doc = XmlSupport::document($xml);
        }

        if ($doc->documentElement?->localName !== 'EDXLResourceMessage') {
            throw new \InvalidArgumentException('Expected EDXLResourceMessage root.');
        }

        $originating = XmlSupport::firstText($doc, ['originatingMessageID']);
        $incidentExternalId = XmlSupport::firstText($doc, ['incidentID']);
        $quantity = (int) (XmlSupport::firstText($doc, ['ResourceInformation', 'Quantity']) ?? 0);
        $priorityRaw = XmlSupport::firstText($doc, ['ResourceInformation', 'Priority']) ?? 'normal';
        $statusRaw = XmlSupport::firstText($doc, ['ResourceInformation', 'Status']) ?? 'requested';
        $neededBy = XmlSupport::firstText($doc, ['ResourceInformation', 'DateTimeNeeded']);
        $notes = XmlSupport::firstText($doc, ['ResourceInformation', 'Notes']);

        $typeName = XmlSupport::firstText($doc, ['ResourceInformation', 'ResourceType', 'Name']);
        if ($typeName === null || $typeName === '') {
            throw new \InvalidArgumentException('RM ResourceType/Name is required.');
        }

        if ($incidentExternalId === null || $incidentExternalId === '') {
            throw new \InvalidArgumentException('RM incidentID is required.');
        }

        $incident = Incident::query()
            ->forOrganization($organizationId)
            ->where('external_id', $incidentExternalId)
            ->first();

        if ($incident === null) {
            throw new \InvalidArgumentException("Unknown incidentID '{$incidentExternalId}' for organization.");
        }

        $resourceType = ResourceType::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'name' => $typeName,
            ],
            [
                'category' => XmlSupport::firstText($doc, ['ResourceInformation', 'ResourceType', 'Category']),
                'capability' => XmlSupport::firstText($doc, ['ResourceInformation', 'ResourceType', 'Capability']),
                'unit' => XmlSupport::firstText($doc, ['ResourceInformation', 'ResourceType', 'Unit']) ?? 'ea',
            ],
        );

        $priority = ResourceRequestPriority::tryFrom($priorityRaw) ?? ResourceRequestPriority::Normal;
        $status = ResourceRequestStatus::tryFrom($statusRaw) ?? ResourceRequestStatus::Requested;

        $requestId = null;
        if (is_string($originating) && preg_match('/^dm-req-(\d+)$/', $originating, $m) === 1) {
            $requestId = (int) $m[1];
        }

        $existing = null;
        if ($requestId !== null) {
            $existing = ResourceRequest::query()
                ->forOrganization($organizationId)
                ->whereKey($requestId)
                ->first();
        }

        $requesterId = $actor?->getKey()
            ?? (int) (XmlSupport::firstText($doc, ['Requester', 'UserID']) ?: 0)
            ?: null;

        if ($requesterId === null || $requesterId === 0) {
            throw new \InvalidArgumentException('RM import requires a requester user (token actor or Requester/UserID).');
        }

        $attributes = [
            'organization_id' => $organizationId,
            'requester_user_id' => $requesterId,
            'incident_id' => $incident->id,
            'resource_type_id' => $resourceType->id,
            'quantity' => max(1, $quantity),
            'priority' => $priority,
            'needed_by' => $neededBy ? Carbon::parse($neededBy) : null,
            'status' => $status,
            'notes' => $notes,
        ];

        if ($existing !== null) {
            $existing->fill($attributes);
            $existing->edxl_json = ResourceRequestExporter::toEdxlRmShape($existing);
            $existing->save();

            return $existing->fresh(['resourceType', 'incident', 'assignments']);
        }

        $request = ResourceRequest::query()->create($attributes);
        $request->edxl_json = ResourceRequestExporter::toEdxlRmShape($request);
        $request->save();

        return $request->fresh(['resourceType', 'incident', 'assignments']);
    }
}
