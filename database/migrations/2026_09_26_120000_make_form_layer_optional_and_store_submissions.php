<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropForeign(['layer_id']);
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->unsignedBigInteger('layer_id')->nullable()->change();
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->foreign('layer_id')->references('id')->on('layers')->nullOnDelete();
            $table->boolean('collect_geometry')->default(false);
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('layer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('feature_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('attributes')->nullable();
            $table->text('geometry_wkt')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->timestamps();

            $table->index(['form_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');

        Schema::table('forms', function (Blueprint $table) {
            $table->dropForeign(['layer_id']);
            $table->dropColumn('collect_geometry');
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->unsignedBigInteger('layer_id')->nullable(false)->change();
            $table->foreign('layer_id')->references('id')->on('layers')->cascadeOnDelete();
        });
    }
};
