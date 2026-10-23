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

        // Drop grants that belong to an organization other than the user's
        // current one. Without the column they would look like current-org
        // grants, and re-running up() would backfill them into the user's
        // current organization (privilege escalation, e.g. an org-B admin
        // grant becoming admin in org A). DATA LOSS: these cross-org grants
        // are deleted on rollback and are not restored by re-migrating.
        DB::table('role_user')
            ->whereNotNull('organization_id')
            ->where(function ($query) {
                $query->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('users')
                        ->whereColumn('users.id', 'role_user.user_id')
                        ->whereColumn('users.organization_id', 'role_user.organization_id');
                });
            })
            ->delete();

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'organization_id']);
            $table->dropConstrainedForeignId('organization_id');
        });
    }

    /**
     * The old (role_id, user_id) unique cannot hold the same role granted in
     * several organizations. Keep one grant per pair, preferring, in order:
     * the grant in the user's current organization, then a null-org grant
     * (inert: confers no access), then the oldest other-org grant. Null and
     * non-null grants are all candidates, ranked explicitly, so NULL never
     * matches by accident. The extra grants are deleted (lossy).
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
                ->orderByRaw(
                    'case when organization_id is not null and organization_id = ? then 0 when organization_id is null then 1 else 2 end',
                    [$userOrg ?? 0]
                )
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
