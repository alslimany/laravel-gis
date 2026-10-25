<?php

namespace App\Models;

use App\Plugins\PluginManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationPlugin extends Model
{
    /**
     * organization_id / plugin_key / enabled are set explicitly by the
     * toggle code path after the plugins.toggle gate, never mass assigned.
     *
     * @var list<string>
     */
    protected $fillable = ['settings'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            /** PII / delivery targets (emails, phones, chat IDs) — encrypted at rest (A5). */
            'settings' => 'encrypted:array',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected static function booted(): void
    {
        $bust = fn (OrganizationPlugin $row) => app(PluginManager::class)->forgetOrganization((int) $row->organization_id);

        static::saved($bust);
        static::deleted($bust);
    }
}
