<?php

namespace App\Support;

use BackedEnum;

/**
 * Server-side twin of resources/js/lib/enum-labels.ts: label for a raw enum/code value from the
 * shared `enum.<value>` catalog in lang/{ar,en}.json, with a humanised fallback.
 */
class EnumLabel
{
    public static function key(mixed $value): string
    {
        $raw = $value instanceof BackedEnum ? (string) $value->value : (string) $value;

        return (string) preg_replace('/[\s-]+/', '_', mb_strtolower(trim($raw)));
    }

    public static function for(mixed $value, ?string $locale = null, string ...$prefixes): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $key = self::key($value);
        // Pure unit tests (no booted app) have no translator: fall through to the humanised value.
        $canTranslate = function_exists('app') && app()->bound('translator');
        foreach ($canTranslate ? [...$prefixes, 'enum'] : [] as $prefix) {
            $full = "{$prefix}.{$key}";
            $hit = __($full, [], $locale);
            if (is_string($hit) && $hit !== $full) {
                return $hit;
            }
        }

        $text = trim(str_replace('_', ' ', $value instanceof BackedEnum ? (string) $value->value : (string) $value));

        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
