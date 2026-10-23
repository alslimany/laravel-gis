<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solution_pack_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('slug', 60);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->json('definition');
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solution_pack_definitions');
    }
};
