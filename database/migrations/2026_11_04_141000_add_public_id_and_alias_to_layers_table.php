<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DG-1.1 / ADR-0002 §7: stable public layer IDs (ULID) and org-scoped aliases.
 * Core migration (A1 exception to ADR-0001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layers', function (Blueprint $table) {
            $table->string('public_id', 26)->nullable()->after('id');
            $table->string('alias', 63)->nullable()->after('name');
        });

        $claimed = [];

        DB::table('layers')->orderBy('id')->get(['id', 'organization_id', 'name', 'table_name'])->each(
            function (object $row) use (&$claimed): void {
                $orgId = (int) $row->organization_id;
                $base = $this->aliasBase((string) ($row->name ?: $row->table_name ?: 'layer'));
                $candidate = $base;
                $n = 2;

                while (isset($claimed[$orgId][$candidate])) {
                    $suffix = '_'.$n;
                    $candidate = Str::limit($base, 63 - strlen($suffix), '').$suffix;
                    $n++;
                }

                $claimed[$orgId][$candidate] = true;

                DB::table('layers')->where('id', $row->id)->update([
                    'public_id' => (string) Str::ulid(),
                    'alias' => $candidate,
                ]);
            }
        );

        Schema::table('layers', function (Blueprint $table) {
            $table->unique('public_id');
            $table->unique(['organization_id', 'alias']);
        });
    }

    public function down(): void
    {
        Schema::table('layers', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropUnique(['organization_id', 'alias']);
            $table->dropColumn(['public_id', 'alias']);
        });
    }

    private function aliasBase(string $source): string
    {
        $slug = strtolower((string) Str::slug($source, '_'));
        $slug = preg_replace('/[^a-z0-9_]/', '', $slug) ?: 'layer';

        if (! preg_match('/^[a-z]/', $slug)) {
            $slug = 'l_'.$slug;
        }

        return Str::limit($slug, 50, '');
    }
};
