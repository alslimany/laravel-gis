<?php

namespace App\Models;

use App\Plugins\PluginManager;
use Illuminate\Database\Eloquent\Model;

class InstalledPlugin extends Model
{
    public const STATUS_INSTALLING = 'installing';

    public const STATUS_INSTALLED = 'installed';

    public const STATUS_UPGRADING = 'upgrading';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNINSTALLED = 'uninstalled';

    /**
     * Install state changes only through CLI/queued lifecycle code (D4),
     * never from request input, so nothing is mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => app(PluginManager::class)->forgetInstalled());
        static::deleted(fn () => app(PluginManager::class)->forgetInstalled());
    }
}
