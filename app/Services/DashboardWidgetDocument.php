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
     * Widget types that may read a layer or a saved analysis result.
     *
     * @var array<int, string>
     */
    public const ANALYSIS_TYPES = [
        'indicator',
        'serial',
        'pie',
        'table',
        'list',
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

        if (array_key_exists('map_id', $raw)) {
            $widget['map_id'] = (int) $raw['map_id'] ?: null;
        }

        if (array_key_exists('source', $raw)) {
            $source = strtolower((string) $raw['source']);
            $allowed = $type === 'map' ? ['layer', 'map'] : (in_array($type, self::ANALYSIS_TYPES, true) ? ['layer', 'analysis'] : []);
            if (in_array($source, $allowed, true)) {
                $widget['source'] = $source;
            }
        }

        if (array_key_exists('analysis_id', $raw) && in_array($type, self::ANALYSIS_TYPES, true)) {
            $widget['analysis_id'] = (int) $raw['analysis_id'] ?: null;
        }

        if ($type === 'map' && empty($widget['source'])) {
            $widget['source'] = ! empty($widget['map_id']) ? 'map' : 'layer';
        }

        foreach (['column', 'group_by', 'aggregation', 'prefix', 'suffix', 'body', 'title_field', 'description_field', 'chart_style', 'basemap'] as $key) {
            if (array_key_exists($key, $raw) && $raw[$key] !== null && $raw[$key] !== '') {
                $widget[$key] = $raw[$key];
            }
        }

        if ($type === 'map') {
            $viewport = $this->normalizeViewport($raw['viewport'] ?? null);
            if ($viewport !== null) {
                $widget['viewport'] = $viewport;
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

    /**
     * The view a map widget opens on, kept as posted: center/zoom/rotation or
     * a bounds rectangle. Anything unusable is dropped rather than guessed at.
     *
     * @return array{center?: array{0: float, 1: float}, zoom?: float, rotation?: float, bounds?: array{0: float, 1: float, 2: float, 3: float}}|null
     */
    protected function normalizeViewport(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $viewport = [];

        $center = $value['center'] ?? null;
        if (is_array($center) && count($center) === 2 && is_numeric($center[0]) && is_numeric($center[1])) {
            $lon = (float) $center[0];
            $lat = (float) $center[1];
            if ($lon >= -180 && $lon <= 180 && $lat >= -90 && $lat <= 90) {
                $viewport['center'] = [$lon, $lat];
            }
        }

        if (isset($value['zoom']) && is_numeric($value['zoom'])) {
            $zoom = (float) $value['zoom'];
            if ($zoom >= 0 && $zoom <= 24) {
                $viewport['zoom'] = round($zoom, 2);
            }
        }

        if (isset($value['rotation']) && is_numeric($value['rotation'])) {
            $rotation = (float) $value['rotation'];
            if (abs($rotation) <= M_PI) {
                $viewport['rotation'] = round($rotation, 4);
            }
        }

        $bounds = LayerExtent::coerceBounds($value['bounds'] ?? null);
        if ($bounds !== null) {
            $viewport['bounds'] = $bounds;
        }

        if ($viewport === []) {
            return null;
        }

        // A rectangle alone is a complete description of the view.
        if ($bounds !== null) {
            $fitted = LayerExtent::toViewport($bounds);
            $viewport['center'] ??= $fitted['center'];
            $viewport['zoom'] ??= $fitted['zoom'];
        }

        return $viewport;
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
            'indicator' => __('dash.type.indicator'),
            'serial' => __('dash.type.serial'),
            'pie' => __('dash.type.pie'),
            'table' => __('dash.type.table'),
            'list' => __('dash.type.list'),
            'map' => __('dash.type.map'),
            'text' => __('dash.type.text'),
            'category' => __('dash.type.category'),
            default => __('dash.type.widget'),
        };
    }

    /**
     * @return array<int, array{type: string, label: string, group: string, description: string}>
     */
    public function catalog(): array
    {
        return [
            ['type' => 'indicator', 'label' => __('dash.type.indicator'), 'group' => __('dash.group.data'), 'description' => __('dash.cat_desc.indicator')],
            ['type' => 'serial', 'label' => __('dash.cat.serial'), 'group' => __('dash.group.data'), 'description' => __('dash.cat_desc.serial')],
            ['type' => 'pie', 'label' => __('dash.type.pie'), 'group' => __('dash.group.data'), 'description' => __('dash.cat_desc.pie')],
            ['type' => 'table', 'label' => __('dash.type.table'), 'group' => __('dash.group.data'), 'description' => __('dash.cat_desc.table')],
            ['type' => 'list', 'label' => __('dash.type.list'), 'group' => __('dash.group.data'), 'description' => __('dash.cat_desc.list')],
            ['type' => 'map', 'label' => __('dash.type.map'), 'group' => __('dash.type.map'), 'description' => __('dash.cat_desc.map')],
            ['type' => 'text', 'label' => __('dash.type.text'), 'group' => __('dash.group.content'), 'description' => __('dash.cat_desc.text')],
            ['type' => 'category', 'label' => __('dash.cat.category'), 'group' => __('dash.group.filters'), 'description' => __('dash.cat_desc.category')],
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
