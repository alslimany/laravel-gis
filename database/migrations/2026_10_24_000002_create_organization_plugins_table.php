<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-organization plugin enablement (ADR-0001 §6). No FK to
     * installed_plugins so rows survive uninstall/reinstall.
     */
    public function up(): void
    {
        Schema::create('organization_plugins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('plugin_key', 64);
            $table->boolean('enabled')->default(false);
            $table->jsonb('settings')->nullable();
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'plugin_key']);
            $table->index(['plugin_key', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_plugins');
    }
};
