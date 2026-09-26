<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('layer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('kind', 32);
            $table->unsignedInteger('feature_count')->default(0);
            $table->json('feature_ids')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_results');
    }
};
