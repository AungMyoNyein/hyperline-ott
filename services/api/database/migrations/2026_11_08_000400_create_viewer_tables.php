<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'movie_id']);
            $table->index('movie_id');
        });

        Schema::create('watch_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('completed')->default(false);
            $table->timestamp('last_watched_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'movie_id']);
            $table->index(['user_id', 'completed']);
        });

        Schema::create('playback_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->ipAddress()->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'movie_id']);
            $table->index(['movie_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_sessions');
        Schema::dropIfExists('watch_progress');
        Schema::dropIfExists('watchlists');
    }
};
