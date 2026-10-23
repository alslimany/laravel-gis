<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user may hold the same role in different organizations (#66 / D2).
     * The new unique index is added before the old one is dropped so MySQL
     * always keeps an index leading with role_id for the role_id FK.
     */
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->unique(['role_id', 'user_id', 'organization_id'], 'role_user_role_user_org_unique');
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropUnique(['role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        $this->dedupeSameRoleGrants();

        Schema::table('role_user', function (Blueprint $table) {
            $table->unique(['role_id', 'user_id']);
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropUnique('role_user_role_user_org_unique');
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
