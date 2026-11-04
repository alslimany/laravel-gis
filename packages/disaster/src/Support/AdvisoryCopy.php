<?php

namespace Lumina\Disaster\Support;

/**
 * Central advisory wording for UI, APIs, and public dissemination stubs.
 */
final class AdvisoryCopy
{
    public static function message(): string
    {
        $default = 'Operational decision support for emergency managers — not an official warning.';
        $configured = (string) config('disaster.advisory.message', $default);

        // Custom env override stays as-written; the stock default follows the app locale.
        if ($configured === $default || $configured === '') {
            return (string) __('disaster.advisory.message');
        }

        return $configured;
    }

    /**
     * @return array{name: string, url: string|null}|null
     */
    public static function authority(): ?array
    {
        $name = trim((string) config('disaster.advisory.authority.name', ''));
        if ($name === '') {
            return null;
        }

        $url = trim((string) config('disaster.advisory.authority.url', ''));

        return [
            'name' => $name,
            'url' => $url !== '' ? $url : null,
        ];
    }

    /**
     * When the organization is flagged as an official authority and the content
     * is marked official, advisory-only wording may be dropped (Annex A.4).
     */
    public static function shouldDropAdvisory(?int $organizationId, bool $isOfficialContent = false): bool
    {
        if ($organizationId === null || ! $isOfficialContent) {
            return false;
        }

        return OrganizationDisasterSettings::isOfficialAuthority($organizationId);
    }

    /**
     * @return array{advisory: string, authority: array{name: string, url: string|null}|null, is_official_mode: bool}
     */
    public static function forUi(?string $message = null, ?int $organizationId = null, bool $isOfficialContent = false): array
    {
        $officialMode = self::shouldDropAdvisory($organizationId, $isOfficialContent);

        return [
            'advisory' => $officialMode ? '' : ($message ?? self::message()),
            'authority' => self::authority(),
            'is_official_mode' => $officialMode,
        ];
    }
}
