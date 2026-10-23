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
            $this->dedupeSameRoleGrants();

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

    /**
     * The old (role_id, user_id) unique cannot hold the same role granted in
     * several organizations. Keep one grant per pair: the one in the user's
     * current organization, otherwise the oldest (lowest id). The extra
     * grants are deleted; this is the only lossy part of the rollback.
     */
    protected function dedupeSameRoleGrants(): void
    {
        $duplicates = DB::table('role_user')
            ->select('role_id', 'user_id')
            ->groupBy('role_id', 'user_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $pair) {
            $userOrg = DB::table('users')->where('id', $pair->user_id)->value('organization_id');

            $keepId = DB::table('role_user')
                ->where('role_id', $pair->role_id)
                ->where('user_id', $pair->user_id)
                ->orderByRaw('case when organization_id = ? then 0 else 1 end', [$userOrg ?? 0])
                ->orderBy('id')
                ->value('id');

            DB::table('role_user')
                ->where('role_id', $pair->role_id)
                ->where('user_id', $pair->user_id)
                ->where('id', '!=', $keepId)
                ->delete();
        }
    }
};
