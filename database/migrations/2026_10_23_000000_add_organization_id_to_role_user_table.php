<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scope role grants to an organization (#66 / D2).
     */
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->index(['user_id', 'organization_id']);
        });

        // Backfill from the user's current organization. Users without an
        // organization keep a null grant (explicit null-org handling).
        DB::table('role_user')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => DB::raw(
                    '(select users.organization_id from users where users.id = role_user.user_id)'
                ),
            ]);
    }

    public function down(): void
    {
        // If the follow-up widening migration is still applied, put the
        // original (role_id, user_id) unique back first so this column can go.
        if (Schema::hasIndex('role_user', 'role_user_role_user_org_unique')) {
            Schema::table('role_user', function (Blueprint $table) {
                $table->unique(['role_id', 'user_id']);
            });
            Schema::table('role_user', function (Blueprint $table) {
                $table->dropUnique('role_user_role_user_org_unique');
            });
        }

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'organization_id']);
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
