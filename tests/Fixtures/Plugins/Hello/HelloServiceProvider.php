<?php

namespace Tests\Fixtures\Plugins\Hello;

use App\Models\Organization;
use App\Plugins\PluginServiceProvider;

/**
 * hello-plugin test fixture (ADR-0001 §12). Records lifecycle hook calls.
 */
class HelloServiceProvider extends PluginServiceProvider
{
    public static ?string $migrations = null;

    /** @var list<string> */
    public static array $calls = [];

    public static bool $failOnInstall = false;

    public function key(): string
    {
        return 'hello';
    }

    public static function migrationsPath(): ?string
    {
        return static::$migrations;
    }

    public function onInstall(): void
    {
        if (static::$failOnInstall) {
            throw new \RuntimeException('hello onInstall failed');
        }
        static::$calls[] = 'install';
    }

    public function onEnable(Organization $organization): void
    {
        static::$calls[] = 'enable:'.$organization->getKey();
    }

    public function onDisable(Organization $organization): void
    {
        static::$calls[] = 'disable:'.$organization->getKey();
    }

    public function onUninstall(bool $purge): void
    {
        static::$calls[] = $purge ? 'purge' : 'uninstall';
    }
}
