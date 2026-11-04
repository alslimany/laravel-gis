<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DG-1.6 — metadata for desktop Sanctum PATs (UUID plain text lives only at mint time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dc_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')
                ->constrained('personal_access_tokens')
                ->cascadeOnDelete();
            $table->string('label', 255);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique('personal_access_token_id');
            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dc_api_keys');
    }
};
