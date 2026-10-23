<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('role_user', function (Blueprint $table) {
            $table->unique(['role_id', 'user_id']);
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropUnique('role_user_role_user_org_unique');
        });
    }
};
