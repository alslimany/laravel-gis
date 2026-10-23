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

    public const ROLLBACK = 'rollback';

    public const SUPER_ADMIN_GRANT = 'super-admin.grant';

    public const SUPER_ADMIN_REVOKE = 'super-admin.revoke';

    /** Lifecycle actions; with no actor_id they were run from the CLI (actor=cli). */
    public const CLI_ACTIONS = [self::INSTALL, self::UPGRADE, self::UNINSTALL, self::PURGE, self::ROLLBACK, self::SUPER_ADMIN_GRANT, self::SUPER_ADMIN_REVOKE];

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
        if (in_array($action, self::CLI_ACTIONS, true)) {
            $attributes['meta'] = array_merge(
                ['actor' => empty($attributes['actor_id']) ? 'cli' : 'user'],
                $attributes['meta'] ?? [],
            );
        }

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
