<?php

namespace Lumina\Disaster\Http\Controllers\Api\Exchange;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Lumina\Disaster\Models\ExchangeLog;
use Lumina\Disaster\Models\ExchangeToken;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Support\Exchange\HaveExporter;
use Lumina\Disaster\Support\Exchange\HaveImporter;
use Lumina\Disaster\Support\Exchange\XmlSchemaValidator;

class HaveExchangeController extends Controller
{
    public function export(Request $request): Response|JsonResponse
    {
        $organizationId = (int) $request->attributes->get('exchange_organization_id');
        $targets = $this->targetOrgIds($request);

        $facilities = MedicalFacility::query()
            ->forOrganization($organizationId)
            ->orderBy('facility_id')
            ->get();

        $xml = $targets === []
            ? HaveExporter::toXml($facilities, $organizationId)
            : HaveExporter::toDistributionXml($facilities, $organizationId, $targets);

        $validation = XmlSchemaValidator::validate($xml);
        if (! $validation['ok']) {
            ExchangeLog::record($organizationId, 'out', 'HAVE', $xml, 'invalid', [
                'errors' => $validation['errors'],
            ]);

            return response()->json(['message' => 'Export failed validation.', 'errors' => $validation['errors']], 422);
        }

        ExchangeLog::record($organizationId, 'out', 'HAVE', $xml, 'ok', [
            'count' => $facilities->count(),
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
            ExchangeLog::record($organizationId, 'in', 'HAVE', $xml, 'invalid', [
                'errors' => $validation['errors'],
                'token_id' => $token->id,
            ]);

            return response()->json(['message' => 'Import failed validation.', 'errors' => $validation['errors']], 422);
        }

        try {
            $imported = HaveImporter::import($xml, $organizationId);
        } catch (\InvalidArgumentException $e) {
            ExchangeLog::record($organizationId, 'in', 'HAVE', $xml, 'error', [
                'message' => $e->getMessage(),
                'token_id' => $token->id,
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        ExchangeLog::record($organizationId, 'in', 'HAVE', $xml, 'ok', [
            'count' => count($imported),
            'token_id' => $token->id,
        ]);

        return response()->json([
            'data' => collect($imported)->map(fn ($f) => [
                'id' => $f->id,
                'facility_id' => $f->facility_id,
                'name' => $f->name,
                'bed_capacity' => $f->bed_capacity,
                'available_beds' => $f->available_beds,
                'trauma_level' => $f->trauma_level,
                'generator_status' => $f->generator_status,
            ])->values(),
        ]);
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
