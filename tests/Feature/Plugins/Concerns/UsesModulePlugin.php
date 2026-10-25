<?php

namespace Tests\Feature\Plugins\Concerns;

use App\Models\InstalledPlugin;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Tests\Fixtures\Plugins\Hello\HelloServiceProvider;

/**
 * Registers the hello fixture as a plugin that declares a console module,
 * with a named route the module links to.
 */
trait UsesModulePlugin
{
    protected string $moduleInstalledJson;

    /**
     * @param  array<string, mixed>  $module
     */
    protected function setUpModulePlugin(array $module = []): void
    {
        HelloServiceProvider::$calls = [];

        Route::middleware(['web', 'auth'])
            ->get('/x/hello', fn () => Inertia::render('Extensions/Index'))
            ->name('hello.index');
        Route::middleware(['web', 'auth'])
            ->get('/x/hello/settings', fn () => 'settings')
            ->name('hello.settings');
        Route::getRoutes()->refreshNameLookups();

        $this->moduleInstalledJson = sys_get_temp_dir().'/module-installed-'.uniqid().'.json';
        file_put_contents($this->moduleInstalledJson, json_encode(['packages' => [[
            'name' => 'lumina/hello',
            'version' => '1.0.0',
            'extra' => ['lumina' => [
                'key' => 'hello',
                'name' => 'Hello',
                'provider' => HelloServiceProvider::class,
                'module' => $module + [
                    'label_en' => 'Hello Module',
                    'description_en' => 'Fixture module',
                    'icon' => 'hard-hat',
                    'accent' => 'orange',
                    'home' => 'hello.index',
                    'sections' => [
                        ['label_en' => 'Field', 'items' => [
                            ['label_en' => 'Overview', 'route' => 'hello.index', 'icon' => 'hard-hat'],
                            ['label_en' => 'Settings', 'route' => 'hello.settings', 'permission' => 'hello.manage'],
                            ['label_en' => 'Missing route', 'route' => 'hello.missing'],
                        ]],
                    ],
                ],
                'data' => ['prefix' => 'hello_', 'tables' => []],
            ]],
        ]]]));

        $this->app->forgetInstance(PluginManager::class);
        $this->app->instance(PluginManager::class, new PluginManager($this->moduleInstalledJson));
    }

    protected function installModulePlugin(): void
    {
        $row = new InstalledPlugin;
        $row->forceFill([
            'plugin_key' => 'hello', 'version' => '1.0.0', 'status' => InstalledPlugin::STATUS_INSTALLED, 'installed_at' => now(),
        ])->save();
        app(PluginManager::class)->forgetInstalled();
    }

    protected function tearDownModulePlugin(): void
    {
        @unlink($this->moduleInstalledJson);
    }
}
