<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One-off fix for the basin-risk cross-tenant leak: every org's layer row pointed at one shared
 * Postgres view `ew_basin_risk`, so each publish overwrote the others. Rename each legacy row to
 * `ew_basin_risk_{organization_id}` (layer id kept, so maps/dashboards keep working) and drop the
 * shared object only when it is a VIEW. The per-org view is created on the next publish
 * (early-warning:publish-risk-layer); until then the layer draws nothing rather than another org's data.
 *
 * If an org already has an `ew_basin_risk_{org}` row, the legacy row is a duplicate: we warn and log
 * both ids, repoint map layers, dashboard widgets and layer_id foreign keys to the kept row, then
 * delete the duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('layers')) {
            return;
        }

        $rows = DB::table('layers')->where('table_name', 'ew_basin_risk')->get(['id', 'organization_id']);
        foreach ($rows as $row) {
            if ($row->organization_id === null) {
                continue;
            }
            $target = 'ew_basin_risk_'.(int) $row->organization_id;
            $keepId = DB::table('layers')->where('organization_id', $row->organization_id)
                ->where('table_name', $target)->value('id');
            if ($keepId === null) {
                DB::table('layers')->where('id', $row->id)->update(['table_name' => $target]);

                continue;
            }

            $message = "ew_basin_risk split: org {$row->organization_id} already has layer #{$keepId} ({$target}); "
                ."legacy duplicate layer #{$row->id} repointed to #{$keepId} and deleted.";
            Log::warning($message, ['organization_id' => (int) $row->organization_id, 'kept_layer_id' => (int) $keepId, 'duplicate_layer_id' => (int) $row->id]);
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                fwrite(STDERR, "  WARN {$message}\n");
            }
            // One duplicate at a time, all-or-nothing: a failure leaves that org's rows untouched.
            DB::transaction(function () use ($row, $keepId) {
                $this->repoint((int) $row->id, (int) $keepId);
                DB::table('layers')->where('id', $row->id)->delete();
            });
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('layers')->where('table_name', 'ew_basin_risk')->exists()) {
            return; // an org-less or conflicting row still uses it; leave it for a manual look
        }

        $isView = (int) DB::scalar(
            "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relname = 'ew_basin_risk' AND n.nspname = current_schema() AND c.relkind = 'v'"
        );
        if ($isView === 1) {
            DB::statement('DROP VIEW IF EXISTS ew_basin_risk');
        }
    }

    /** Point every known reference from the duplicate layer at the kept one. */
    private function repoint(int $from, int $to): void
    {
        foreach (['analysis_results', 'dm_incident_imagery', 'dm_lifeline_components', 'ew_basins', 'feature_attachments',
            'form_submissions', 'forms'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'layer_id')) {
                DB::table($table)->where('layer_id', $from)->update(['layer_id' => $to]);
            }
        }
        $this->repointRelationships($from, $to);
        if (Schema::hasTable('layer_fields')) {
            DB::table('layer_fields')->where('layer_id', $from)->delete(); // the kept layer has its own schema
        }

        $this->repointJson('maps', 'layers', function (array $entry) use ($from, $to) {
            if ((int) ($entry['id'] ?? 0) === $from) {
                $entry['id'] = $to;
            }
            if ((int) ($entry['layer_id'] ?? 0) === $from) {
                $entry['layer_id'] = $to;
            }

            return $entry;
        });
        // dashboards.widgets is a flat list today (DashboardWidgetDocument::normalize); walk nested
        // arrays anyway so a future sections/tabs shape is still repointed.
        $walk = function (array $node) use (&$walk, $from, $to): array {
            foreach ($node as $key => $value) {
                if ($key === 'layer_id' && (int) $value === $from) {
                    $node[$key] = $to;
                } elseif (is_array($value)) {
                    $node[$key] = $walk($value);
                }
            }

            return $node;
        };
        $this->repointJson('dashboards', 'widgets', $walk);
    }

    /**
     * layer_relationships is UNIQUE (layer_id, related_layer_id, foreign_key) and cascades on layer delete.
     * Repointing blindly could collide with a row the kept layer already has, or turn a dup<->kept link
     * into a self-relation. Those rows are deleted first (the cascade would have removed them anyway);
     * every other row is repointed so the kept layer keeps the relationship.
     */
    private function repointRelationships(int $from, int $to): void
    {
        if (! Schema::hasTable('layer_relationships')) {
            return;
        }
        // Self-relations after the move: dup->kept, kept->dup, dup->dup.
        DB::table('layer_relationships')->where(function ($q) use ($from, $to) {
            $q->where(fn ($w) => $w->where('layer_id', $from)->whereIn('related_layer_id', [$from, $to]))
                ->orWhere(fn ($w) => $w->where('layer_id', $to)->where('related_layer_id', $from));
        })->delete();

        foreach (DB::table('layer_relationships')->where('layer_id', $from)->orWhere('related_layer_id', $from)->orderBy('id')->get() as $rel) {
            $layerId = (int) $rel->layer_id === $from ? $to : (int) $rel->layer_id;
            $relatedId = (int) $rel->related_layer_id === $from ? $to : (int) $rel->related_layer_id;
            $collides = DB::table('layer_relationships')->where('id', '!=', $rel->id)
                ->where('layer_id', $layerId)->where('related_layer_id', $relatedId)
                ->where('foreign_key', $rel->foreign_key)->exists();
            if ($collides) {
                DB::table('layer_relationships')->where('id', $rel->id)->delete();
            } else {
                DB::table('layer_relationships')->where('id', $rel->id)->update(['layer_id' => $layerId, 'related_layer_id' => $relatedId]);
            }
        }
    }

    private function repointJson(string $table, string $column, callable $map): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        DB::table($table)->whereNotNull($column)->orderBy('id')->each(function ($row) use ($table, $column, $map) {
            $items = json_decode((string) $row->{$column}, true);
            if (! is_array($items)) {
                return;
            }
            $updated = array_map(fn ($item) => is_array($item) ? $map($item) : $item, $items);
            if ($updated !== $items) {
                DB::table($table)->where('id', $row->id)->update([$column => json_encode($updated)]);
            }
        });
    }

    public function down(): void
    {
        // One-way: restoring the shared name would restore the leak.
    }
};
