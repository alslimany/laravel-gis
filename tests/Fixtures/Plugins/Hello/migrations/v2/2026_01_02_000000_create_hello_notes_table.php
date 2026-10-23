<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hello_notes', function (Blueprint $table) {
            $table->id();
            $table->text('body');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hello_notes');
    }
};
