<?php

namespace Lumina\Disaster\Console;

use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Lumina\Disaster\Demo\NcmcdDemoSeeder;
use Lumina\Disaster\Publishing\AssetLayerPublisher;
use Lumina\Disaster\Publishing\OpsLayerPublisher;

class SeedNcmcdDemoCommand extends Command
{
    protected $signature = 'disaster:seed-ncmcd-demo
        {--reset-passwords : Generate new passwords for the 4 NCMCD demo users}
        {--force : Allow running when APP_ENV=production (also needs --i-understand-demo)}
        {--i-understand-demo : Confirm you want fictional exercise users and data in this environment}';

    protected $description = 'Seed the idempotent Arabic NCMCD Wadi-26 exercise (own org, 4 users, NCMCD-W26- records)';

    public const CREDENTIALS_PATH = 'app/private/ncmcd-demo-credentials.txt';

    public function handle(NcmcdDemoSeeder $seeder): int
    {
        if ($this->laravel->environment('production')
            && ! ($this->option('force') && $this->option('i-understand-demo'))) {
            $this->error('Refusing to seed demo data in production. This creates 4 login accounts and fictional'
                .' exercise records. Pass both --force and --i-understand-demo to proceed.');

            return self::FAILURE;
        }

        try {
            $result = $seeder->seed((bool) $this->option('reset-passwords'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('NCMCD Wadi-26 exercise seeded (تمرين افتراضي).');
        foreach ($result['summary'] as $key => $value) {
            $this->line("  {$key}: ".(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value));
        }
        if ($result['summary']['basins_missing'] !== []) {
            $this->warn('  Missing basins were skipped (not created): '.implode(', ', $result['summary']['basins_missing']));
        }

        $this->publishMapLayers();

        if ($result['credentials'] === []) {
            $this->line('Users already existed; passwords unchanged (use --reset-passwords to rotate).');
        } else {
            $this->writeCredentials($result['credentials']);
        }

        return self::SUCCESS;
    }

    /**
     * Publish the demo org's asset, incident and closure layers so the COP and Assets maps draw them.
     * Runs after the seed transaction; a GeoServer outage only warns (Postgres views still back the tiles).
     */
    private function publishMapLayers(): void
    {
        $org = Organization::query()->where('name', NcmcdDemoSeeder::ORG_NAME)->first();
        if (! $org) {
            return;
        }

        foreach ([AssetLayerPublisher::class, OpsLayerPublisher::class] as $class) {
            try {
                $this->laravel->make($class)->publish($org);
            } catch (\Throwable $e) {
                $this->warn('  Map layer publish ('.class_basename($class).') failed: '.$e->getMessage());
            }
        }
        $this->line('  map layers: published (assets, incidents, closures)');
    }

    /** @param array<string, string> $credentials */
    private function writeCredentials(array $credentials): void
    {
        $path = storage_path(self::CREDENTIALS_PATH);
        File::ensureDirectoryExists(dirname($path), 0700);

        $lines = ['# NCMCD Wadi-26 demo credentials — local only, never commit or share in chat.', '# Generated '.now()->toIso8601String()];
        foreach ($credentials as $email => $password) {
            $lines[] = "{$email}\t{$password}";
        }

        // Create with 0600 before writing the secret.
        touch($path);
        chmod($path, 0600);
        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
        chmod($path, 0600);

        // Never print passwords: only the file path.
        $this->warn(count($credentials).' new password(s) written to '.$path.' (mode 0600). Not printed.');
    }
}
