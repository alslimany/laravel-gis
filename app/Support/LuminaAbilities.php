<?php

namespace App\Support;

class LuminaAbilities
{
    /**
     * Fixed catalog keys shown as checkboxes when creating a token.
     *
     * @return list<string>
     */
    public static function selectable(): array
    {
        return array_keys(config('lumina-abilities.fixed', []));
    }

    /**
     * Whether an ability string is allowed on a newly created token.
     * Legacy `*` is not accepted for new tokens.
     */
    public static function isAllowed(string $ability): bool
    {
        if ($ability === '*' || $ability === '') {
            return false;
        }

        if (in_array($ability, self::selectable(), true)) {
            return true;
        }

        foreach (config('lumina-abilities.patterns', []) as $meta) {
            $regex = $meta['regex'] ?? null;
            if (is_string($regex) && preg_match($regex, $ability) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inertia props for the token settings page.
     *
     * @return list<array{key: string, description: string}>
     */
    public static function catalogForUi(): array
    {
        $out = [];

        foreach (config('lumina-abilities.fixed', []) as $key => $meta) {
            $localeKey = 'abilities.'.str_replace(':', '_', $key);
            $fallback = (string) ($meta['description'] ?? $key);
            $hit = __($localeKey);

            $out[] = [
                'key' => $key,
                'description' => (is_string($hit) && $hit !== $localeKey) ? $hit : $fallback,
            ];
        }

        return $out;
    }
}
