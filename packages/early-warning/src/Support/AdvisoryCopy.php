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
        return (string) config(
            'early-warning.advisory.message',
            'Forecast-based decision support only — not an official warning.',
        );
    }

    public static function citizenReports(): string
    {
        return (string) config(
            'early-warning.advisory.citizen_reports',
            'Citizen reports are a verification layer — not official warnings.',
        );
    }

    public static function reportReceived(): string
    {
        return (string) config(
            'early-warning.advisory.report_received',
            'Report received for verification — not an official warning.',
        );
    }

    /**
     * Short tag for risk calculator inputs / metadata.
     */
    public static function shortTag(): string
    {
        return (string) config(
            'early-warning.advisory.short_tag',
            'decision support, not an official warning',
        );
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
