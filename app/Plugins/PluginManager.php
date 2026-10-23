<?php

namespace App\Plugins;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\PluginEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Plugin registry and gating lookups (ADR-0001 §2, §5).
 *
 * Code present (composer) != installed (installed_plugins) != enabled for an
 * org (organization_plugins). Lookups are cached and busted by model events.
 */
class PluginManager
{
    public function __construct(protected ?string $installedJsonPath = null)
    {
        $this->installedJsonPath ??= base_path('vendor/composer/installed.json');
    }

    /**
     * Manifests (extra.lumina) of every plugin package present in vendor,
     * keyed by plugin key. Cached by installed.json mtime+size.
     *
     * @return array<string, array<string, mixed>>
     */
    public function registry(): array
    {
        return Cache::rememberForever($this->registryCacheKey(), fn () => $this->readRegistry());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function manifest(string $key): ?array
    {
        return $this->registry()[$key] ?? null;
    }

    public function isPresent(string $key): bool
    {
        return $this->manifest($key) !== null;
    }

    public function isInstalled(string $key): bool
    {
        return $this->isPresent($key) && in_array($key, $this->installedKeys(), true);
    }

    public function isEnabledFor(string $key, Organization|int|null $organization): bool
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        if ($organizationId === null || ! $this->isInstalled($key)) {
            return false;
        }

        return in_array($key, $this->enabledKeysFor((int) $organizationId), true);
    }

    /**
     * Enabled plugin manifests for an organization (D13).
     *
     * @return array<string, array<string, mixed>>
     */
    public function enabledFor(Organization|int|null $organization): array
    {
        return array_filter(
            $this->registry(),
            fn (array $manifest, string $key) => $this->isEnabledFor($key, $organization),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return Collection<int, int>
     */
    public function enabledOrganizations(string $key): Collection
    {
        if (! $this->isInstalled($key)) {
            return collect();
        }

        return OrganizationPlugin::query()
            ->where('plugin_key', $key)
            ->where('enabled', true)
            ->pluck('organization_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * Enable or disable a plugin for one organization. Callers must have
     * passed the plugins.toggle gate.
     */
    public function setEnabled(string $key, Organization $organization, bool $enabled, ?User $actor = null): OrganizationPlugin
    {
        $row = OrganizationPlugin::query()->firstOrNew([
            'organization_id' => $organization->getKey(),
            'plugin_key' => $key,
        ]);

        $row->organization_id = $organization->getKey();
        $row->plugin_key = $key;
        $row->enabled = $enabled;
        if ($enabled) {
            $row->enabled_by = $actor?->getKey();
            $row->enabled_at = now();
        } else {
            $row->disabled_at = now();
        }
        $row->save();

        PluginEvent::record($key, $enabled ? PluginEvent::ENABLE : PluginEvent::DISABLE, [
            'organization_id' => $organization->getKey(),
            'actor_id' => $actor?->getKey(),
            'to_version' => InstalledPlugin::query()->where('plugin_key', $key)->value('version'),
            'meta' => ['super_admin' => (bool) $actor?->isSuperAdmin()],
        ]);

        $provider = $this->provider($key);
        $enabled ? $provider?->onEnable($organization) : $provider?->onDisable($organization);

        return $row;
    }

    /**
     * The plugin's provider instance (registered one if booted).
     */
    public function provider(string $key): ?PluginServiceProvider
    {
        $class = $this->manifest($key)['provider'] ?? null;
        if (! $class || ! class_exists($class) || ! is_subclass_of($class, PluginServiceProvider::class)) {
            return null;
        }

        return app()->getProvider($class) ?? new $class(app());
    }

    /**
     * @return list<string>
     */
    public function installedKeys(): array
    {
        return Cache::rememberForever('plugins:installed', fn () => InstalledPlugin::query()
            ->where('status', InstalledPlugin::STATUS_INSTALLED)
            ->pluck('plugin_key')
            ->all());
    }

    /**
     * @return list<string>
     */
    public function enabledKeysFor(int $organizationId): array
    {
        return Cache::rememberForever("plugins:org:{$organizationId}", fn () => OrganizationPlugin::query()
            ->where('organization_id', $organizationId)
            ->where('enabled', true)
            ->pluck('plugin_key')
            ->all());
    }

    public function forgetInstalled(): void
    {
        Cache::forget('plugins:installed');
    }

    public function forgetOrganization(int $organizationId): void
    {
        Cache::forget("plugins:org:{$organizationId}");
    }

    public function forgetRegistry(): void
    {
        Cache::forget($this->registryCacheKey());
    }

    public function registryCacheKey(): string
    {
        $path = $this->installedJsonPath;
        $hash = is_file($path) ? md5($path.':'.filemtime($path).':'.filesize($path)) : 'missing';

        return "plugins:registry:{$hash}";
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function readRegistry(): array
    {
        if (! is_file($this->installedJsonPath)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->installedJsonPath), true) ?: [];
        $packages = $data['packages'] ?? $data;

        $registry = [];
        foreach ($packages as $package) {
            $manifest = $package['extra']['lumina'] ?? null;
            if (! is_array($manifest) || empty($manifest['key']) || ! is_string($manifest['key'])) {
                continue;
            }
            $manifest['package'] = $package['name'] ?? null;
            $registry[$manifest['key']] = $manifest;
        }
        ksort($registry);

        return $registry;
    }
}
