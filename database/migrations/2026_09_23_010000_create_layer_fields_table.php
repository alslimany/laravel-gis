<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layer_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('alias')->nullable();
            $table->string('type')->default('string');
            $table->json('domain_values')->nullable();
            $table->boolean('required')->default(false);
            $table->text('calculated_expression')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['layer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('layer_fields');
    }
};
