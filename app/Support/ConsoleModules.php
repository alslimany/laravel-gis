<?php

namespace App\Support;

use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Builds the module switcher and sectioned sidebar for the console shell.
 *
 * Core GIS is always on, each enabled plugin contributes one module from its
 * manifest (extra.lumina.module, falling back to extra.lumina.nav), and the
 * organization-wide pages live in the admin module.
 *
 * @phpstan-type ModuleItem array{label: string, href: string, icon: string, match?: string}
 * @phpstan-type ModuleSection array{label: string, items: list<ModuleItem>}
 * @phpstan-type Module array{key: string, label: string, description: string|null, icon: string, accent: string, kind: string, href: string, sections: list<ModuleSection>}
 */
class ConsoleModules
{
    public const CORE = 'core';

    public const ADMIN = 'admin';

    /**
     * Route-name prefixes whose pages belong to the admin module.
     */
    protected const ADMIN_ROUTE_PREFIXES = [
        'users.',
        'groups.',
        'api-tokens.',
        'solution-packs.',
        'organization.',
        'extensions.',
    ];

    public function __construct(protected PluginManager $plugins) {}

    /**
     * @return list<Module>
     */
    public function for(User $user): array
    {
        $modules = [$this->core($user)];

        if (! $user->organization_id) {
            return $modules;
        }

        foreach ($this->pluginModules($user) as $module) {
            $modules[] = $module;
        }

        $admin = $this->admin($user);
        if ($admin['sections'] !== []) {
            $modules[] = $admin;
        }

        return $modules;
    }

    /**
     * The module that owns the current route. Plugin routes are named
     * "<key>.*" by PluginServiceProvider, so the prefix identifies them.
     *
     * @param  list<Module>  $modules
     */
    public function activeKey(array $modules, ?string $routeName): string
    {
        if ($routeName === null) {
            return self::CORE;
        }

        foreach ($modules as $module) {
            if ($module['kind'] === 'extension' && str_starts_with($routeName, $module['key'].'.')) {
                return $module['key'];
            }
        }

        $hasAdmin = collect($modules)->contains('key', self::ADMIN);
        foreach (self::ADMIN_ROUTE_PREFIXES as $prefix) {
            if ($hasAdmin && str_starts_with($routeName, $prefix)) {
                return self::ADMIN;
            }
        }

        return self::CORE;
    }

    /**
     * @return Module
     */
    protected function core(User $user): array
    {
        $sections = [];

        if ($user->organization_id) {
            $sections = $this->sections([
                'Data' => [
                    $this->item('Data imports', 'imports.index', 'imports'),
                    $this->item('Layers', 'layers.index', 'layers'),
                    $this->item('Catalog', 'catalog.index', 'catalog'),
                ],
                'Maps & apps' => [
                    $this->item('Maps', 'maps.index', 'maps', '/maps'),
                    $this->item('Dashboards', 'dashboards.index', 'dashboards'),
                    $this->item('Forms', 'forms.index', 'forms'),
                    $this->item('Apps', 'apps.index', 'apps', '/apps'),
                ],
                'Work' => [
                    $this->item('Projects', 'projects.index', 'projects'),
                ],
            ]);
        }

        return [
            'key' => self::CORE,
            'label' => 'Core GIS',
            'description' => 'Data, layers, maps and apps',
            'icon' => 'core',
            'accent' => 'primary',
            'kind' => 'core',
            'href' => route('dashboard'),
            'sections' => $sections,
        ];
    }

    /**
     * @return Module
     */
    protected function admin(User $user): array
    {
        $isAdmin = $user->isAdmin();

        $sections = $this->sections([
            'People' => [
                $isAdmin ? $this->item('Users', 'users.index', 'users') : null,
                $this->item('Groups', 'groups.index', 'groups'),
            ],
            'Platform' => [
                $user->organization && Gate::forUser($user)->allows('plugins.toggle', $user->organization)
                    ? $this->item('Extensions', 'extensions.index', 'extensions')
                    : null,
                $isAdmin ? $this->item('Solution packs', 'solution-packs.index', 'packs', '/solution-packs') : null,
                $this->item('API tokens', 'api-tokens.index', 'tokens'),
            ],
            'Organization' => [
                $this->item('Settings', 'organization.settings', 'organization'),
            ],
        ]);

        return [
            'key' => self::ADMIN,
            'label' => $isAdmin ? 'Admin' : 'Organization',
            'description' => $isAdmin ? 'People, extensions and organization' : 'Groups, tokens and organization',
            'icon' => 'admin',
            'accent' => 'slate',
            'kind' => 'admin',
            'href' => $sections[0]['items'][0]['href'] ?? route('dashboard'),
            'sections' => $sections,
        ];
    }

    /**
     * @return list<Module>
     */
    protected function pluginModules(User $user): array
    {
        $modules = [];

        foreach ($this->plugins->enabledFor($user->organization_id) as $key => $manifest) {
            $module = $this->pluginModule($user, $key, $manifest);
            if ($module !== null) {
                $modules[] = $module;
            }
        }

        usort($modules, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(function (array $module) {
            unset($module['order']);

            return $module;
        }, $modules);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return (Module&array{order: int})|null
     */
    protected function pluginModule(User $user, string $key, array $manifest): ?array
    {
        $definition = is_array($manifest['module'] ?? null) ? $manifest['module'] : [];
        $nav = is_array($manifest['nav'] ?? null) ? $manifest['nav'] : [];
        $rawSections = $definition['sections'] ?? [['label_en' => 'General', 'items' => $nav]];

        $sections = [];
        foreach ($rawSections as $rawSection) {
            $items = [];
            foreach ($rawSection['items'] ?? [] as $entry) {
                $route = $entry['route'] ?? null;
                if (! is_string($route) || ! Route::has($route) || ! $this->permits($user, $entry['permission'] ?? null)) {
                    continue;
                }
                $items[] = [
                    'label' => $this->localized($entry, $route),
                    'href' => route($route),
                    'icon' => (string) ($entry['icon'] ?? 'extensions'),
                ];
            }
            if ($items !== []) {
                $sections[] = ['label' => $this->localized($rawSection, ''), 'items' => $items];
            }
        }

        if ($sections === []) {
            return null;
        }

        $home = $definition['home'] ?? null;

        return [
            'key' => $key,
            'label' => $this->localized($definition, (string) ($manifest['name'] ?? $key)),
            'description' => isset($definition['description_en']) ? $this->localized($definition, '', 'description') : null,
            'icon' => (string) ($definition['icon'] ?? $nav[0]['icon'] ?? 'extensions'),
            'accent' => (string) ($definition['accent'] ?? 'cyan'),
            'kind' => 'extension',
            'href' => is_string($home) && Route::has($home) ? route($home) : $sections[0]['items'][0]['href'],
            'sections' => $sections,
            'order' => (int) ($definition['order'] ?? $nav[0]['order'] ?? 100),
        ];
    }

    /**
     * Plugin permissions are enforced only when the app defines a gate for them.
     */
    protected function permits(User $user, mixed $permission): bool
    {
        if (! is_string($permission) || ! Gate::has($permission)) {
            return true;
        }

        return Gate::forUser($user)->allows($permission);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function localized(array $entry, string $fallback, string $field = 'label'): string
    {
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';

        return (string) ($entry["{$field}_{$locale}"] ?? $entry["{$field}_en"] ?? $fallback);
    }

    /**
     * @param  array<string, list<ModuleItem|null>>  $sections
     * @return list<ModuleSection>
     */
    protected function sections(array $sections): array
    {
        $result = [];

        foreach ($sections as $label => $items) {
            $items = array_values(array_filter($items));
            if ($items !== []) {
                $result[] = ['label' => $label, 'items' => $items];
            }
        }

        return $result;
    }

    /**
     * @return ModuleItem|null
     */
    protected function item(string $label, string $routeName, string $icon, ?string $match = null): ?array
    {
        if (! Route::has($routeName)) {
            return null;
        }

        $item = ['label' => $label, 'href' => route($routeName), 'icon' => $icon];

        if ($match) {
            $item['match'] = $match;
        }

        return $item;
    }
}
