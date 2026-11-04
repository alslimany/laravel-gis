<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-safe backfill: plugin-published layers get metadata.description_key so the UI can
 * translate their description by key (publishers now write it on every publish).
 * Only adds the key where it is missing; nothing else in the row changes.
 */
return new class extends Migration
{
    /** @var array<string, string> plugin layer kind => description catalog key */
    private const KEYS = [
        'disaster-incidents' => 'layers.desc.incidents',
        'disaster-closures' => 'layers.desc.closures',
        'disaster-assets' => 'layers.desc.assets',
        'basin-risk' => 'layers.desc.basin_risk',
    ];

    public function up(): void
    {
        DB::table('layers')->whereNotNull('metadata')->orderBy('id')->each(function ($row): void {
            $meta = json_decode((string) $row->metadata, true);
            if (! is_array($meta) || empty($meta['plugin']) || isset($meta['description_key'])) {
                return;
            }
            $key = self::KEYS[$meta['kind'] ?? ''] ?? null;
            if ($key === null) {
                return;
            }
            $meta['description_key'] = $key;
            DB::table('layers')->where('id', $row->id)->update(['metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
        });
    }

    public function down(): void
    {
        DB::table('layers')->whereNotNull('metadata')->orderBy('id')->each(function ($row): void {
            $meta = json_decode((string) $row->metadata, true);
            if (! is_array($meta) || ! in_array($meta['description_key'] ?? null, self::KEYS, true)) {
                return;
            }
            unset($meta['description_key']);
            DB::table('layers')->where('id', $row->id)->update(['metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
        });
    }
};
