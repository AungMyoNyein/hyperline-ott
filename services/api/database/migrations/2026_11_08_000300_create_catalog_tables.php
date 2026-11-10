<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->timestamps();
        });

        Schema::create('movies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('slug', 255)->unique();
            $table->text('synopsis')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
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

        Schema::create('movie_genre', function (Blueprint $table) {
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->primary(['movie_id', 'genre_id']);
            $table->index('genre_id');
        });

        Schema::create('video_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60)->default('main');
            $table->string('kind', 20)->default('hls');
            $table->string('url', 2048);
            $table->string('quality', 20)->nullable();
            $table->string('language', 32)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['movie_id', 'label']);
            $table->index(['movie_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_sources');
        Schema::dropIfExists('movie_genre');
        Schema::dropIfExists('movies');
        Schema::dropIfExists('genres');
    }
};
