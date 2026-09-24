<?php

namespace App\Ai\Tools;

use App\Models\Layer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchLayersTool implements Tool
{
    public function __construct(public int $organizationId) {}

    public function description(): Stringable|string
    {
        return 'Search layers in the current organization by name or description. Returns layer id, name, geometry type, and feature count.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = (string) ($request['query'] ?? '');
        $limit = min((int) ($request['limit'] ?? 20), 50);

        $layers = Layer::query()
            ->where('organization_id', $this->organizationId)
            ->when($query !== '', function ($q) use ($query) {
                $q->where(function ($inner) use ($query) {
                    $inner->where('name', 'ILIKE', "%{$query}%")
                        ->orWhere('description', 'ILIKE', "%{$query}%")
                        ->orWhere('table_name', 'ILIKE', "%{$query}%");
                });
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'description', 'geometry_type', 'feature_count', 'published']);

        return json_encode([
            'count' => $layers->count(),
            'layers' => $layers,
        ], JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search text for layer name/description')->required(),
            'limit' => $schema->integer()->description('Max results (default 20, max 50)'),
        ];
    }
}
