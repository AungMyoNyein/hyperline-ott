<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backward-compatible series support. Existing movies, sources and sessions are
 * untouched: ownership columns only widen (movie_id becomes nullable) and gain a
 * sibling episode_id, so every pre-existing row keeps its meaning. The
 * "exactly one owner" invariant is enforced in the application layer because
 * portable CHECK constraints are unavailable on the SQLite test database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('slug', 255)->unique();
            $table->text('synopsis')->nullable();
            $table->string('category', 60)->nullable()->index();
            $table->string('language', 60)->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->string('poster_url', 1024)->nullable();
            $table->string('backdrop_url', 1024)->nullable();
            $table->boolean('featured')->default(false);
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['published', 'featured']);
            $table->index(['published', 'created_at']);
            $table->index('title');
        });

        Schema::create('series_episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->unsignedSmallInteger('episode_number');
            $table->string('title', 255)->nullable();
            $table->text('synopsis')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('still_url', 1024)->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();

            $table->unique(['series_id', 'episode_number']);
            $table->index(['series_id', 'published', 'episode_number']);
        });

        Schema::table('video_sources', function (Blueprint $table) {
            $table->foreignId('movie_id')->nullable()->change();
            $table->foreignId('episode_id')->nullable()->after('movie_id')
                ->constrained('series_episodes')->cascadeOnDelete();

            $table->unique(['episode_id', 'label']);
            $table->index(['episode_id', 'enabled']);
        });

        Schema::table('playback_sessions', function (Blueprint $table) {
            $table->foreignId('movie_id')->nullable()->change();
            $table->foreignId('episode_id')->nullable()->after('movie_id')
                ->constrained('series_episodes')->cascadeOnDelete();

            $table->index(['user_id', 'episode_id']);
            $table->index(['episode_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('playback_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'episode_id']);
            $table->dropIndex(['episode_id', 'expires_at']);
            $table->dropConstrainedForeignId('episode_id');
            $table->foreignId('movie_id')->nullable(false)->change();
        });

        Schema::table('video_sources', function (Blueprint $table) {
            $table->dropUnique(['episode_id', 'label']);
            $table->dropIndex(['episode_id', 'enabled']);
            $table->dropConstrainedForeignId('episode_id');
            $table->foreignId('movie_id')->nullable(false)->change();
        });

        Schema::dropIfExists('series_episodes');
        Schema::dropIfExists('series');
    }
};
