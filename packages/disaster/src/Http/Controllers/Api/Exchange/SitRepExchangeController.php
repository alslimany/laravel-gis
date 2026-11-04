<?php

namespace Lumina\Disaster\Http\Controllers\Api\Exchange;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Lumina\Disaster\Models\ExchangeLog;
use Lumina\Disaster\Models\ExchangeToken;
use Lumina\Disaster\Models\SitRep;
use Lumina\Disaster\Support\Exchange\SitRepEdxlExporter;
use Lumina\Disaster\Support\Exchange\SitRepEdxlImporter;
use Lumina\Disaster\Support\Exchange\XmlSchemaValidator;

class SitRepExchangeController extends Controller
{
    public function export(Request $request): Response|JsonResponse
    {
        $organizationId = (int) $request->attributes->get('exchange_organization_id');
        $sitrepId = (int) $request->query('sitrep_id', 0);

        $sitrep = SitRep::query()
            ->forOrganization($organizationId)
            ->with(['incident', 'preparer'])
            ->find($sitrepId);

        if ($sitrep === null) {
            return response()->json(['message' => 'SitRep not found.'], 404);
        }

        $targets = $this->targetOrgIds($request);
        $xml = $targets === []
            ? SitRepEdxlExporter::toXml($sitrep)
            : SitRepEdxlExporter::toDistributionXml($sitrep, $targets);

        $validation = XmlSchemaValidator::validate($xml);
        if (! $validation['ok']) {
            ExchangeLog::record($organizationId, 'out', 'SitRep', $xml, 'invalid', [
                'errors' => $validation['errors'],
                'sitrep_id' => $sitrep->id,
            ]);

            return response()->json(['message' => 'Export failed validation.', 'errors' => $validation['errors']], 422);
        }

        ExchangeLog::record($organizationId, 'out', 'SitRep', $xml, 'ok', [
            'sitrep_id' => $sitrep->id,
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
            ExchangeLog::record($organizationId, 'in', 'SitRep', $xml, 'invalid', [
                'errors' => $validation['errors'],
                'token_id' => $token->id,
            ]);

            return response()->json(['message' => 'Import failed validation.', 'errors' => $validation['errors']], 422);
        }

        $actor = $this->resolveActor($request, $organizationId);

        try {
            $imported = SitRepEdxlImporter::import($xml, $organizationId, $actor);
        } catch (\InvalidArgumentException $e) {
            ExchangeLog::record($organizationId, 'in', 'SitRep', $xml, 'error', [
                'message' => $e->getMessage(),
                'token_id' => $token->id,
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        ExchangeLog::record($organizationId, 'in', 'SitRep', $xml, 'ok', [
            'sitrep_id' => $imported->id,
            'token_id' => $token->id,
        ]);

        return response()->json([
            'data' => [
                'id' => $imported->id,
                'incident_id' => $imported->incident_id,
                'summary' => $imported->summary,
                'casualties' => $imported->casualties,
                'resources' => $imported->resources,
                'lifelines_status' => $imported->lifelines_status,
                'next_steps' => $imported->next_steps,
            ],
        ], 201);
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
