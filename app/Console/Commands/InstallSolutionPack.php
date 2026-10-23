<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Services\GeoServerPublisher;
use App\Services\SolutionPackInstaller;
use App\SolutionPacks\SolutionPack;
use App\SolutionPacks\StoredPack;
use Illuminate\Console\Command;

class InstallSolutionPack extends Command
{
    protected $signature = 'solution:pack:install
        {slug : Pack slug, for example aec-construction}
        {organization_id : Organization to install into}
        {--user= : User id owning the created layers (defaults to the first admin)}
        {--publish : Publish pack layers to GeoServer after install}';

    protected $description = 'Install a solution pack (layers, fields, relationships, sample features, map, form, dashboard) into an organization';

    public function handle(SolutionPackInstaller $installer): int
    {
        $organization = Organization::find($this->argument('organization_id'));
        if (! $organization) {
            $this->error("Organization with ID {$this->argument('organization_id')} not found");

            return self::FAILURE;
        }

        $slug = (string) $this->argument('slug');
        $pack = StoredPack::findFor($organization, $slug) ?? SolutionPack::find($slug);
        if (! $pack) {
            $available = implode(', ', array_map(fn (SolutionPack $candidate) => $candidate->slug(), SolutionPack::all()));
            $this->error("Solution pack not found. Available: {$available}");

            return self::FAILURE;
        }

        $owner = $this->resolveOwner($organization);
        if (! $owner) {
            return self::FAILURE;
        }

        $this->info("Installing {$pack->name()} into {$organization->name}...");
        $summary = $installer->install($pack, $organization, $owner);

        foreach ($summary['layers'] as $layer) {
            $this->line(($layer['created'] ? '  + ' : '  = ').'layer '.$layer['name']);
        }

        foreach ($summary['relationships'] as $name) {
            $this->line('  + relationship '.$name);
        }

        if ($summary['features'] > 0) {
            $this->line("  + {$summary['features']} sample features");
        }

        foreach (['maps' => 'map', 'forms' => 'form', 'dashboards' => 'dashboard'] as $key => $singular) {
            $count = $summary[$key] ?? 0;
            if ($count > 0) {
                $this->line("  + {$count} {$singular}".($count === 1 ? '' : 's'));
            }
        }

        $this->info($summary['installed'] ? 'Done.' : 'Already installed; nothing to do.');

        if ($this->option('publish')) {
            $this->publishPackLayers($installer, $pack, $organization);
        }

        return self::SUCCESS;
    }

    protected function publishPackLayers(SolutionPackInstaller $installer, SolutionPack $pack, Organization $organization): void
    {
        $unpublished = array_values(array_filter(
            $installer->packLayers($pack, $organization),
            fn ($layer) => ! $layer->isPublished()
        ));

        if ($unpublished === []) {
            $this->line('  = no unpublished pack layers');

            return;
        }

        $result = app(GeoServerPublisher::class)->publish($organization, $unpublished);
        $this->line("  + published {$result['published']} of ".count($unpublished).' layers to GeoServer');

        foreach ($result['failed'] as $name) {
            $this->error("  ! failed to publish {$name}");
        }
    }

    protected function resolveOwner(Organization $organization): ?User
    {
        $userId = $this->option('user');
        if ($userId) {
            $owner = User::where('id', $userId)
                ->where('organization_id', $organization->id)
                ->first();

            if (! $owner) {
                $this->error("User {$userId} does not belong to organization {$organization->name}.");

                return null;
            }

            return $owner;
        }

        return User::where('organization_id', $organization->id)
            ->whereHas('roles', fn ($query) => $query->where('name', 'admin'))
            ->orderBy('id')
            ->first()
            ?? User::where('organization_id', $organization->id)->orderBy('id')->first();
    }
}
