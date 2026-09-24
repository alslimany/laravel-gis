<?php

namespace App\Ai\Tools;

use App\Helpers\GeometryColumnHelper;
use App\Helpers\QueryBuilder;
use App\Models\Layer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class BufferAnalysisTool implements Tool
{
    public function __construct(public int $organizationId) {}

    public function description(): Stringable|string
    {
        return 'Run a buffer analysis on all features of a layer. Distance is in meters. Returns a count and a sample of buffered geometries as GeoJSON. Does not run free-form SQL.';
    }

    public function handle(Request $request): Stringable|string
    {
        $layerId = (int) ($request['layer_id'] ?? 0);
        $distance = (float) ($request['distance'] ?? 0);

        if ($distance <= 0) {
            return json_encode(['error' => 'Distance must be a positive number (meters).']);
        }

        $layer = Layer::where('id', $layerId)
            ->where('organization_id', $this->organizationId)
            ->first();

        if (! $layer) {
            return json_encode(['error' => 'Layer not found in this organization.']);
        }

        try {
            $geometryColumn = GeometryColumnHelper::resolve($layer->table_name);
            $results = QueryBuilder::bufferAnalysis(
                $layer->table_name,
                $geometryColumn,
                $distance,
                []
            );

            return json_encode([
                'layer_id' => $layer->id,
                'layer_name' => $layer->name,
                'distance_meters' => $distance,
                'count' => $results->count(),
                'sample' => $results->take(10)->values(),
            ], JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'layer_id' => $schema->integer()->description('Layer ID to buffer')->required(),
            'distance' => $schema->number()->description('Buffer distance in meters')->required(),
        ];
    }
}
