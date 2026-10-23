<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layer_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_layer_id')->constrained('layers')->cascadeOnDelete();
            $table->string('name');
            $table->string('foreign_key');
            $table->timestamps();

            $table->unique(['layer_id', 'related_layer_id', 'foreign_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('layer_relationships');
    }
};
