<?php

namespace Tests\Feature\Plugins;

use App\Models\InstalledPlugin;
use App\Plugins\PluginManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A4: installed plugins register schedule(); uninstalled contribute nothing. */
class PluginScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_installed_early_warning_registers_named_schedule_events(): void
    {
        (new InstalledPlugin)->forceFill([
            'plugin_key' => 'early-warning',
            'version' => '0.9.1',
            'status' => InstalledPlugin::STATUS_INSTALLED,
            'installed_at' => now(),
            'meta' => [],
        ])->save();
        app(PluginManager::class)->forgetInstalled();

        $schedule = app(Schedule::class);
        app(PluginManager::class)->registerSchedules($schedule);

        $names = collect($schedule->events())->map->description->filter()->values()->all();

        $this->assertContains('early-warning:fetch-forecasts', $names);
        $this->assertContains('early-warning:evaluate-alerts', $names);
        $this->assertContains('early-warning:dispatch-alerts', $names);
    }

    public function test_uninstalled_plugin_registers_no_schedule_events(): void
    {
        app(PluginManager::class)->forgetInstalled();

        $schedule = app(Schedule::class);
        app(PluginManager::class)->registerSchedules($schedule);

        $names = collect($schedule->events())
            ->map->description
            ->filter(fn ($name) => is_string($name) && str_starts_with($name, 'early-warning:'))
            ->values()
            ->all();

        $this->assertSame([], $names);
    }
}
