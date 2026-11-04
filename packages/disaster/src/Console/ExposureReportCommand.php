<?php

namespace Lumina\Disaster\Console;

use Illuminate\Console\Command;
use Lumina\Disaster\Jobs\ComputeExposureJob;
use Lumina\Disaster\Services\ExposureComputer;

class ExposureReportCommand extends Command
{
    protected $signature = 'disaster:exposure-report
        {organization_id : Organization id}
        {--hazard= : Filter ranked rows by hazard_key}
        {--compare= : Comma-separated scenario names to compare}
        {--compute-hazard= : Also compute a zone for this hazard_key before reporting}
        {--layer= : Hazard zone Core layer id}
        {--feature= : Optional hazard feature id within the layer}
        {--dam= : Critical site id for dam-failure scenario}
        {--scenario= : Scenario name for compute}
        {--hazard-level= : Override hazard_level 0–1}
        {--lat= : Optional anchor latitude when no layer}
        {--lon= : Optional anchor longitude when no layer}
        {--radius= : Buffer radius meters}
        {--sync : Run compute inline instead of queue}
        {--limit=20 : Ranked row limit}';

    protected $description = 'Ranked exposure by risk_index and optional scenario comparison';

    public function handle(ExposureComputer $computer): int
    {
        $organizationId = (int) $this->argument('organization_id');

        if ($this->option('compute-hazard') || $this->option('dam')) {
            $job = new ComputeExposureJob(
                organizationId: $organizationId,
                hazardKey: (string) ($this->option('compute-hazard') ?: 'dam_failure'),
                hazardZoneLayerId: $this->option('layer') !== null ? (int) $this->option('layer') : null,
                hazardFeatureId: $this->option('feature') !== null ? (int) $this->option('feature') : null,
                hazardLatitude: $this->option('lat') !== null ? (float) $this->option('lat') : null,
                hazardLongitude: $this->option('lon') !== null ? (float) $this->option('lon') : null,
                radiusM: $this->option('radius') !== null ? (float) $this->option('radius') : null,
                hazardLevel: $this->option('hazard-level') !== null ? (float) $this->option('hazard-level') : null,
                scenarioName: (string) ($this->option('scenario') ?? ''),
                damSiteId: $this->option('dam') !== null ? (int) $this->option('dam') : null,
            );

            if ($this->option('sync')) {
                dispatch_sync($job);
                $this->info('Zone/scenario exposure computed.');
            } else {
                ComputeExposureJob::dispatch(
                    $job->organizationId,
                    $job->hazardKey,
                    $job->hazardZoneLayerId,
                    $job->hazardFeatureId,
                    $job->hazardLatitude,
                    $job->hazardLongitude,
                    $job->radiusM,
                    $job->hazardLevel,
                    $job->scenarioName,
                    $job->damSiteId,
                );
                $this->info('ComputeExposureJob queued.');
            }
        }

        $compare = (string) ($this->option('compare') ?? '');
        if ($compare !== '') {
            $names = array_map('trim', explode(',', $compare));
            $rows = $computer->compareScenarios($organizationId, $names);
            $this->table(
                ['scenario', 'hazard_key', 'risk_index', 'population', 'roads_m', 'assets'],
                $rows->map(fn ($r) => [
                    $r->scenario_name,
                    $r->hazard_key,
                    $r->risk_index,
                    $r->population_exposed,
                    $r->road_length_m,
                    $r->assets_exposed,
                ])->all(),
            );

            return self::SUCCESS;
        }

        $ranked = $computer->ranked(
            $organizationId,
            $this->option('hazard') ? (string) $this->option('hazard') : null,
            (int) $this->option('limit'),
        );

        $this->table(
            ['rank', 'hazard_key', 'scenario', 'risk_index', 'exposure', 'vuln', 'population', 'assets'],
            $ranked->values()->map(fn ($r, $i) => [
                $i + 1,
                $r->hazard_key,
                $r->scenario_name !== '' ? $r->scenario_name : '(baseline)',
                $r->risk_index,
                $r->exposure_score,
                $r->vulnerability_score,
                $r->population_exposed,
                $r->assets_exposed,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
