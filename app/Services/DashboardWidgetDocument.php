<?php

namespace App\Services;

class DashboardWidgetDocument
{
    public const TYPES = [
        'indicator',
        'serial',
        'pie',
        'table',
        'list',
        'map',
        'text',
        'category',
    ];

    /**
     * Default grid size per widget type (12-column canvas).
     *
     * @var array<string, array{w: int, h: int}>
     */
    public const DEFAULT_SIZE = [
        'indicator' => ['w' => 3, 'h' => 2],
        'serial' => ['w' => 6, 'h' => 4],
        'pie' => ['w' => 4, 'h' => 4],
        'table' => ['w' => 6, 'h' => 5],
        'list' => ['w' => 4, 'h' => 5],
        'map' => ['w' => 6, 'h' => 5],
        'text' => ['w' => 4, 'h' => 2],
        'category' => ['w' => 3, 'h' => 2],
    ];

    /**
     * Normalize stored or posted widgets: stable ids, layouts, and type aliases.
     *
     * @param  mixed  $widgets
     * @return array<int, array<string, mixed>>
     */
    public function normalize(mixed $widgets): array
    {
        $list = $this->decode($widgets);
        $cursorX = 0;
        $cursorY = 0;
        $rowHeight = 0;
        $normalized = [];

        foreach ($list as $index => $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $widget = $this->normalizeOne($raw, $index);

            if (! isset($raw['layout']) || ! is_array($raw['layout'])) {
                $size = self::DEFAULT_SIZE[$widget['type']] ?? ['w' => 4, 'h' => 3];
                if ($cursorX + $size['w'] > 12) {
                    $cursorX = 0;
                    $cursorY += max($rowHeight, 1);
                    $rowHeight = 0;
                }
                $widget['layout'] = [
                    'x' => $cursorX,
                    'y' => $cursorY,
                    'w' => $size['w'],
                    'h' => $size['h'],
                ];
                $cursorX += $size['w'];
                $rowHeight = max($rowHeight, $size['h']);
            }

            $normalized[] = $widget;
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalizeOne(array $raw, int $index = 0): array
    {
        $type = $this->canonicalType((string) ($raw['type'] ?? 'indicator'));
        $size = self::DEFAULT_SIZE[$type] ?? ['w' => 4, 'h' => 3];
        $layout = is_array($raw['layout'] ?? null) ? $raw['layout'] : [];

        $widget = [
            'id' => (string) ($raw['id'] ?? ('w_'.$index.'_'.substr(md5(json_encode($raw)), 0, 8))),
            'type' => $type,
            'title' => (string) ($raw['title'] ?? $this->defaultTitle($type)),
            'layout' => [
                'x' => max(0, min(11, (int) ($layout['x'] ?? 0))),
                'y' => max(0, (int) ($layout['y'] ?? 0)),
                'w' => max(1, min(12, (int) ($layout['w'] ?? $size['w']))),
                'h' => max(1, min(12, (int) ($layout['h'] ?? $size['h']))),
            ],
        ];

        if (isset($raw['layer_id'])) {
            $widget['layer_id'] = (int) $raw['layer_id'] ?: null;
        }

        foreach (['column', 'group_by', 'aggregation', 'prefix', 'suffix', 'body', 'title_field', 'description_field', 'chart_style', 'basemap'] as $key) {
            if (array_key_exists($key, $raw) && $raw[$key] !== null && $raw[$key] !== '') {
                $widget[$key] = $raw[$key];
            }
        }

        if (isset($raw['limit'])) {
            $widget['limit'] = max(1, min(100, (int) $raw['limit']));
        }

        if (isset($raw['columns']) && is_array($raw['columns'])) {
            $widget['columns'] = array_values(array_filter(array_map('strval', $raw['columns'])));
        }

        if ($type === 'serial' && empty($widget['chart_style'])) {
            $legacy = strtolower((string) ($raw['type'] ?? ''));
            $widget['chart_style'] = $legacy === 'line' ? 'line' : 'bar';
        }

        if (empty($widget['aggregation'])) {
            $widget['aggregation'] = 'count';
        }

        return $widget;
    }

    public function canonicalType(string $type): string
    {
        $type = strtolower($type);

        return match ($type) {
            'kpi' => 'indicator',
            'bar', 'line' => 'serial',
            'gauge' => 'indicator',
            default => in_array($type, self::TYPES, true) ? $type : 'indicator',
        };
    }

    public function defaultTitle(string $type): string
    {
        return match ($type) {
            'indicator' => 'Indicator',
            'serial' => 'Chart',
            'pie' => 'Pie chart',
            'table' => 'Table',
            'list' => 'List',
            'map' => 'Map',
            'text' => 'Text',
            'category' => 'Filter',
            default => 'Widget',
        };
    }

    /**
     * @return array<int, array{type: string, label: string, group: string, description: string}>
     */
    public function catalog(): array
    {
        return [
            ['type' => 'indicator', 'label' => 'Indicator', 'group' => 'Data', 'description' => 'Count, sum, or average'],
            ['type' => 'serial', 'label' => 'Serial chart', 'group' => 'Data', 'description' => 'Bar or line by category'],
            ['type' => 'pie', 'label' => 'Pie chart', 'group' => 'Data', 'description' => 'Share of categories'],
            ['type' => 'table', 'label' => 'Table', 'group' => 'Data', 'description' => 'Attribute rows'],
            ['type' => 'list', 'label' => 'List', 'group' => 'Data', 'description' => 'Title and description rows'],
            ['type' => 'map', 'label' => 'Map', 'group' => 'Map', 'description' => 'Published layer on a map'],
            ['type' => 'text', 'label' => 'Text', 'group' => 'Content', 'description' => 'Heading and note'],
            ['type' => 'category', 'label' => 'Category selector', 'group' => 'Filters', 'description' => 'Filter other widgets'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function decode(mixed $widgets): array
    {
        if (is_string($widgets)) {
            $decoded = json_decode($widgets, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($widgets) ? $widgets : [];
    }
}
