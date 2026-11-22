<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->json('sections');
        });
        Schema::table('genres', function (Blueprint $table) {
            $table->string('name_my', 120)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->index(['enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration: roll back application images and retain genre metadata and homepage settings.');
    }
};
