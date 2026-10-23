<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row for plugin lifecycle and toggles (ADR-0001 D9).
 */
class PluginEvent extends Model
{
    public const INSTALL = 'install';

    public const UPGRADE = 'upgrade';

    public const UNINSTALL = 'uninstall';

    public const PURGE = 'purge';

    public const ENABLE = 'enable';

    public const DISABLE = 'disable';

    /**
     * Written only by PluginLifecycle / PluginManager.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public static function record(string $key, string $action, array $attributes = []): self
    {
        $event = new self;
        $event->forceFill(array_merge([
            'plugin_key' => $key,
            'action' => $action,
            'outcome' => 'ok',
        ], $attributes))->save();

        return $event;
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
