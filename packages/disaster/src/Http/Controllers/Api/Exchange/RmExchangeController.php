<?php

namespace Lumina\Disaster\Http\Controllers\Api\Exchange;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Lumina\Disaster\Models\ExchangeLog;
use Lumina\Disaster\Models\ExchangeToken;
use Lumina\Disaster\Models\ResourceRequest;
use Lumina\Disaster\Support\Exchange\RmExporter;
use Lumina\Disaster\Support\Exchange\RmImporter;
use Lumina\Disaster\Support\Exchange\XmlSchemaValidator;

class RmExchangeController extends Controller
{
    public function export(Request $request): Response|JsonResponse
    {
        $organizationId = (int) $request->attributes->get('exchange_organization_id');
        $requestId = (int) $request->query('request_id', 0);

        $resourceRequest = ResourceRequest::query()
            ->forOrganization($organizationId)
            ->with(['incident', 'resourceType', 'assignments.warehouse', 'assignments.avlUnit', 'requester'])
            ->find($requestId);

        if ($resourceRequest === null) {
            return response()->json(['message' => 'Resource request not found.'], 404);
        }

        $targets = $this->targetOrgIds($request);
        $xml = $targets === []
            ? RmExporter::toXml($resourceRequest)
            : RmExporter::toDistributionXml($resourceRequest, $targets);

        $validation = XmlSchemaValidator::validate($xml);
        if (! $validation['ok']) {
            ExchangeLog::record($organizationId, 'out', 'RM', $xml, 'invalid', [
                'errors' => $validation['errors'],
                'request_id' => $resourceRequest->id,
            ]);

            return response()->json(['message' => 'Export failed validation.', 'errors' => $validation['errors']], 422);
        }

        ExchangeLog::record($organizationId, 'out', 'RM', $xml, 'ok', [
            'request_id' => $resourceRequest->id,
            'message_content_type' => RmExporter::messageContentType($resourceRequest),
            'wrapped' => $targets !== [],
        ]);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('exchange_organization_id');
        /** @var ExchangeToken $token */
        $token = $request->attributes->get('exchange_token');
        $xml = (string) $request->getContent();

        $validation = XmlSchemaValidator::validate($xml);
        if (! $validation['ok']) {
            ExchangeLog::record($organizationId, 'in', 'RM', $xml, 'invalid', [
                'errors' => $validation['errors'],
                'token_id' => $token->id,
            ]);

            return response()->json(['message' => 'Import failed validation.', 'errors' => $validation['errors']], 422);
        }

        $actor = $this->resolveActor($request, $organizationId);

        try {
            $imported = RmImporter::import($xml, $organizationId, $actor);
        } catch (\InvalidArgumentException $e) {
            ExchangeLog::record($organizationId, 'in', 'RM', $xml, 'error', [
                'message' => $e->getMessage(),
                'token_id' => $token->id,
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        ExchangeLog::record($organizationId, 'in', 'RM', $xml, 'ok', [
            'request_id' => $imported->id,
            'token_id' => $token->id,
        ]);

        return response()->json([
            'data' => [
                'id' => $imported->id,
                'incident_id' => $imported->incident_id,
                'resource_type_id' => $imported->resource_type_id,
                'quantity' => $imported->quantity,
                'priority' => $imported->priority?->value ?? $imported->priority,
                'status' => $imported->status?->value ?? $imported->status,
                'notes' => $imported->notes,
            ],
        ]);
    }

    protected function resolveActor(Request $request, int $organizationId): ?User
    {
        $userId = (int) $request->header('X-Exchange-Actor-User-Id', 0);
        if ($userId <= 0) {
            return null;
        }

        return User::query()
            ->whereKey($userId)
            ->where('organization_id', $organizationId)
            ->first();
    }

    /**
     * @return list<string>
     */
    protected function targetOrgIds(Request $request): array
    {
        $raw = $request->query('target_organization_ids', []);
        if (is_string($raw)) {
            $raw = array_filter(explode(',', $raw));
        }

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_map('strval', $raw));
    }
}
