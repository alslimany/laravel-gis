<?php

namespace Lumina\Disaster\Console;

use App\Models\Organization;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use Lumina\Disaster\Demo\DemoContentSeeder;

class SeedDemoCommand extends Command
{
    protected $signature = 'disaster:seed-demo
        {--organization= : Organization id (default: first org with disaster enabled)}
        {--user= : Actor user id (defaults to first user in the organization)}';

    protected $description = 'Seed idempotent DEMO- coastal flood content across Disaster & EM modules';

    public function handle(DemoContentSeeder $seeder, PluginManager $plugins): int
    {
        $org = $this->resolveOrganization($plugins);
        if ($org === null) {
            return self::FAILURE;
        }

        if (! $plugins->isEnabledFor('disaster', $org)) {
            $this->error("Disaster plugin is not enabled for organization {$org->id}.");

            return self::FAILURE;
        }

        $actor = $this->resolveActor($org);
        if ($actor === null) {
            $this->error("No users found for organization {$org->id}.");

            return self::FAILURE;
        }

        $this->info("Seeding Disaster & EM demo for org {$org->id} ({$org->name}) as user {$actor->id}…");

        $summary = $seeder->seed($org, $actor);

        foreach ($summary as $key => $value) {
            if (is_array($value)) {
                $this->line("  {$key}: ".json_encode($value));
            } else {
                $this->line("  {$key}: ".(string) $value);
            }
        }

        $this->info('Done. Browse /x/disaster (or Disaster & EM in the sidebar).');

        return self::SUCCESS;
    }

    private function resolveOrganization(PluginManager $plugins): ?Organization
    {
        $orgId = $this->option('organization');
        if ($orgId !== null && $orgId !== '') {
            $org = Organization::query()->find((int) $orgId);
            if (! $org) {
                $this->error("Organization {$orgId} not found.");

                return null;
            }

            return $org;
        }

        $ids = $plugins->enabledOrganizations('disaster');
        if ($ids->isEmpty()) {
            $this->error('No organizations have the disaster plugin enabled.');

            return null;
        }

        return Organization::query()->find((int) $ids->first());
    }

    private function resolveActor(Organization $org): ?User
    {
        $userId = $this->option('user');
        if ($userId !== null && $userId !== '') {
            $user = User::query()
                ->where('organization_id', $org->id)
                ->whereKey((int) $userId)
                ->first();
            if (! $user) {
                $this->error("User {$userId} not found in organization {$org->id}.");

                return null;
            }

            return $user;
        }

        return User::query()
            ->where('organization_id', $org->id)
            ->orderBy('id')
            ->first();
    }
}
