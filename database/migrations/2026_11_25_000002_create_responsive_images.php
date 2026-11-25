<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsive_images', function (Blueprint $table) {
            $table->id();
            $table->string('source_url', 255)->unique();
            $table->string('source_sha256', 64);
            $table->unsignedBigInteger('source_size');
            $table->unsignedBigInteger('source_mtime');
            $table->json('metadata');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsive_images');
    }
};
