<?php

namespace Lumina\EarlyWarning\Support;

/**
 * Central advisory wording for UI, APIs, and alert channels.
 * Forecast-based decision support only — never framed as an official warning.
 */
final class AdvisoryCopy
{
    public static function message(): string
    {
        return self::localized('message', 'Forecast-based decision support only — not an official warning.');
    }

    public static function citizenReports(): string
    {
        return self::localized('citizen_reports', 'Citizen reports are a verification layer — not official warnings.');
    }

    public static function reportReceived(): string
    {
        return self::localized('report_received', 'Report received for verification — not an official warning.');
    }

    /**
     * Short tag for risk calculator inputs / metadata.
     */
    public static function shortTag(): string
    {
        return self::localized('short_tag', 'decision support, not an official warning');
    }

    /**
     * English (or any non-Arabic locale) uses the configurable text; Arabic uses the catalog
     * entry ew.advisory.<key>, unless an explicit early-warning.advisory.<key>_ar override is set.
     */
    private static function localized(string $key, string $default): string
    {
        if (app()->getLocale() === 'ar') {
            $override = trim((string) config("early-warning.advisory.{$key}_ar", ''));
            if ($override !== '') {
                return $override;
            }
            $catalogKey = "ew.advisory.{$key}";
            $hit = __($catalogKey);
            if ($hit !== $catalogKey) {
                return $hit;
            }
        }

        return (string) config("early-warning.advisory.{$key}", $default);
    }

    /**
     * Optional official authority for operators to consult.
     *
     * @return array{name: string, url: string|null}|null
     */
    public static function authority(): ?array
    {
        $name = trim((string) config('early-warning.advisory.authority.name', ''));
        if ($name === '') {
            return null;
        }

        $url = trim((string) config('early-warning.advisory.authority.url', ''));

        return [
            'name' => $name,
            'url' => $url !== '' ? $url : null,
        ];
    }

    /**
     * Props shared by console + public Inertia pages and JSON feeds.
     *
     * @return array{advisory: string, authority: array{name: string, url: string|null}|null}
     */
    public static function forUi(?string $message = null): array
    {
        return [
            'advisory' => $message ?? self::message(),
            'authority' => self::authority(),
        ];
    }

    /**
     * One-line suffix for SMS / Telegram when an authority is configured.
     */
    public static function authorityLine(): ?string
    {
        $authority = self::authority();
        if ($authority === null) {
            return null;
        }

        if ($authority['url'] !== null) {
            return 'Official authority: '.$authority['name'].' — '.$authority['url'];
        }

        return 'Official authority: '.$authority['name'];
    }
}
