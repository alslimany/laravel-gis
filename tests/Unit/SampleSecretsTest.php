<?php

namespace Tests\Unit;

use Illuminate\Support\Env;
use Tests\TestCase;

class SampleSecretsTest extends TestCase
{
    public function test_env_example_uses_password_placeholders(): void
    {
        $contents = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^DB_PASSWORD=CHANGE_ME\s*$/m', $contents);
        $this->assertMatchesRegularExpression('/^GEOSERVER_ADMIN_PASSWORD=CHANGE_ME\s*$/m', $contents);
        $this->assertDoesNotMatchRegularExpression('/^DB_PASSWORD=secret\s*$/m', $contents);
        $this->assertDoesNotMatchRegularExpression('/^GEOSERVER_ADMIN_PASSWORD=geoserver\s*$/m', $contents);
        $this->assertStringContainsString('LOCAL SETUP ONLY', $contents);
    }

    public function test_local_compose_has_no_demo_password_fallbacks(): void
    {
        $contents = file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringNotContainsString(':-secret', $contents);
        $this->assertStringNotContainsString(':-geoserver', $contents);
        $this->assertStringNotContainsString(':-admin', $contents);
        $this->assertStringNotContainsString(':-postgres', $contents);
        $this->assertStringNotContainsString(':-laravel_gis', $contents);
        $this->assertStringContainsString('DB_PASSWORD:?', $contents);
        $this->assertStringContainsString('GEOSERVER_ADMIN_PASSWORD:?', $contents);
    }

    public function test_production_compose_does_not_embed_demo_passwords(): void
    {
        $contents = file_get_contents(base_path('docker-compose.production.yml'));

        $this->assertStringNotContainsString(':-secret', $contents);
        $this->assertStringNotContainsString(':-geoserver', $contents);
        $this->assertStringContainsString('${DB_PASSWORD}', $contents);
        $this->assertStringContainsString('${GEOSERVER_ADMIN_PASSWORD}', $contents);
        $this->assertDoesNotMatchRegularExpression('/PASSWORD:\s*(secret|geoserver|password)\b/', $contents);
    }

    public function test_geoserver_config_defaults_are_not_demo_passwords(): void
    {
        $repository = Env::getRepository();
        $previous = [
            'DB_PASSWORD' => $repository->get('DB_PASSWORD'),
            'GEOSERVER_ADMIN_PASSWORD' => $repository->get('GEOSERVER_ADMIN_PASSWORD'),
        ];

        $repository->clear('DB_PASSWORD');
        $repository->clear('GEOSERVER_ADMIN_PASSWORD');

        try {
            $config = require config_path('geoserver.php');

            $this->assertSame('', $config['admin_password']);
            $this->assertSame('', $config['postgis']['password']);
        } finally {
            foreach ($previous as $key => $value) {
                if ($value !== null) {
                    $repository->set($key, $value);
                }
            }
        }
    }
}
