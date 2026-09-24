<?php

namespace App\Services;

/**
 * Allowlisted Tabler outline icons that can be drawn as point symbols.
 */
class MapIconCatalog
{
    /** @var list<string> */
    public const NAMES = [
        'building-broadcast-tower',
        'antenna',
        'satellite',
        'wifi',
        'building',
        'building-factory',
        'building-hospital',
        'school',
        'home',
        'fence',
        'car',
        'helicopter',
        'tree',
        'droplet',
        'bolt',
        'flag',
        'map-pin',
    ];

    public static function allows(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    public static function svg(string $name, string $color): ?string
    {
        if (! self::allows($name) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return null;
        }

        $path = base_path('node_modules/@tabler/icons/icons/outline/'.$name.'.svg');
        if (! is_file($path)) {
            return null;
        }

        $svg = file_get_contents($path);
        if ($svg === false) {
            return null;
        }

        return str_replace('currentColor', $color, $svg);
    }

    public static function url(string $name, string $color): string
    {
        $base = rtrim((string) config('geoserver.icon_base_url'), '/');

        return $base.'/'.$name.'.svg?color='.rawurlencode($color);
    }
}
