<?php

namespace App\Ai\Tools;

use App\Models\Layer;
use App\Services\FeatureService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListFeaturesTool implements Tool
{
    public function __construct(public int $organizationId) {}

    public function description(): Stringable|string
    {
        return 'List features (attribute rows) for a layer in the current organization. Optionally search attribute values. Does not run free-form SQL.';
    }

    public function handle(Request $request): Stringable|string
    {
        $layerId = (int) ($request['layer_id'] ?? 0);
        $perPage = min((int) ($request['per_page'] ?? 10), 50);
        $search = isset($request['search']) ? (string) $request['search'] : null;

        $layer = Layer::where('id', $layerId)
            ->where('organization_id', $this->organizationId)
            ->first();

        if (! $layer) {
            return json_encode(['error' => 'Layer not found in this organization.']);
        }

        $result = app(FeatureService::class)->list($layer, $perPage, $search);

        return json_encode([
            'layer_id' => $layer->id,
            'layer_name' => $layer->name,
            'columns' => $result['columns'],
            'total' => $result['features']->total(),
            'features' => collect($result['features']->items())->map(fn ($row) => (array) $row)->values(),
        ], JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'layer_id' => $schema->integer()->description('Layer ID to list features from')->required(),
            'per_page' => $schema->integer()->description('Page size (default 10, max 50)'),
            'search' => $schema->string()->description('Optional search across attribute columns'),
        ];
    }
}
