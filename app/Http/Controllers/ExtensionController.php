<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateExtensionRequest;
use App\Plugins\PluginManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin › Extensions: turn installed plugins on or off for the current organization.
 */
class ExtensionController extends Controller
{
    public function __construct(private PluginManager $plugins) {}

    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;
        Gate::authorize('plugins.toggle', $organization);

        $extensions = collect($this->plugins->registry())
            ->map(function (array $manifest, string $key) use ($organization) {
                $module = is_array($manifest['module'] ?? null) ? $manifest['module'] : [];
                $installed = $this->plugins->isInstalled($key);

                return [
                    'key' => $key,
                    'name' => (string) ($module['label_en'] ?? $manifest['name'] ?? $key),
                    'description' => $module['description_en'] ?? null,
                    'icon' => (string) ($module['icon'] ?? $manifest['nav'][0]['icon'] ?? 'extensions'),
                    'accent' => (string) ($module['accent'] ?? 'cyan'),
                    'version' => $manifest['version'] ?? null,
                    'installed' => $installed,
                    'enabled' => $installed && $this->plugins->isEnabledFor($key, $organization),
                    'updateUrl' => route('extensions.update', $key),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Extensions/Index', [
            'extensions' => $extensions,
        ]);
    }

    public function update(UpdateExtensionRequest $request, string $key): RedirectResponse
    {
        abort_unless($this->plugins->isInstalled($key), 404);

        $enabled = $request->boolean('enabled');
        $this->plugins->setEnabled($key, $request->user()->organization, $enabled, $request->user());

        $name = $this->plugins->manifest($key)['module']['label_en'] ?? $this->plugins->manifest($key)['name'] ?? $key;

        return redirect()
            ->route('extensions.index')
            ->with('success', $enabled ? "{$name} is now on for your organization." : "{$name} is now off for your organization.");
    }
}
