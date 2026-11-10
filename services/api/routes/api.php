<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GenreController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\MovieController;
use App\Http\Controllers\Api\V1\PlaybackSessionController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SeriesController;
use App\Http\Controllers\Api\V1\WatchlistController;
use App\Http\Controllers\Api\V1\WatchProgressController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public OTT API (v1)
|--------------------------------------------------------------------------
|
| The api middleware group already applies Sanctum's stateful-request handling,
| throttle:api and bindings. Everything below the /admin path additionally
| requires the admin role, so a signed-in subscriber cannot reach CRUD.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/health', [HealthController::class, 'show'])->name('health');

    Route::get('/home', [HomeController::class, 'index'])->name('home');
    Route::get('/movies', [MovieController::class, 'index'])->name('movies.index');
    Route::get('/movies/{movie:slug}', [MovieController::class, 'show'])->name('movies.show');
    Route::get('/series', [SeriesController::class, 'index'])->name('series.index');
    Route::get('/series/{series:slug}', [SeriesController::class, 'show'])->name('series.show');
    Route::get('/series/{series:slug}/seasons', [SeriesController::class, 'seasons'])->name('series.seasons');
    Route::get('/series/{series:slug}/seasons/{seasonNumber}/episodes', [SeriesController::class, 'episodes'])
        ->whereNumber('seasonNumber')
        ->name('series.seasons.episodes');
    Route::get('/genres', [GenreController::class, 'index'])->name('genres.index');
    Route::get('/search', SearchController::class)->name('search');

    Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login')
        ->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('/watchlist', [WatchlistController::class, 'index'])->name('watchlist.index');
        Route::post('/watchlist', [WatchlistController::class, 'store'])->name('watchlist.store');
        Route::delete('/watchlist/{movie}', [WatchlistController::class, 'destroy'])->name('watchlist.destroy');

        Route::get('/watch-progress', [WatchProgressController::class, 'index'])->name('progress.index');
        Route::post('/watch-progress', [WatchProgressController::class, 'store'])->name('progress.store');

        Route::post('/playback/sessions', [PlaybackSessionController::class, 'store'])
            ->middleware('throttle:playback')
            ->name('playback.sessions.store');
        Route::post('/playback/sessions/{session:public_id}/renew', [PlaybackSessionController::class, 'renew'])
            ->middleware('throttle:playback')->name('playback.sessions.renew');
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::post('/login', [Admin\AuthController::class, 'login'])->name('login')
            ->middleware('throttle:login');

        Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
            Route::post('/logout', [Admin\AuthController::class, 'logout'])->name('logout');
            Route::get('/dashboard', [Admin\DashboardController::class, 'index'])->name('dashboard');

            Route::get('/movies', [Admin\MovieController::class, 'index'])->name('movies.index');
            Route::post('/movies', [Admin\MovieController::class, 'store'])->name('movies.store');
            Route::get('/movies/{movie}', [Admin\MovieController::class, 'show'])->name('movies.show');
            Route::put('/movies/{movie}', [Admin\MovieController::class, 'update'])->name('movies.update');
            Route::patch('/movies/{movie}', [Admin\MovieController::class, 'update'])->name('movies.patch');
            Route::delete('/movies/{movie}', [Admin\MovieController::class, 'destroy'])->name('movies.destroy');

            Route::get('/series', [Admin\SeriesController::class, 'index'])->name('series.index');
            Route::post('/series', [Admin\SeriesController::class, 'store'])->name('series.store');
            Route::get('/series/{series}', [Admin\SeriesController::class, 'show'])->name('series.show');
            Route::put('/series/{series}', [Admin\SeriesController::class, 'update'])->name('series.update');
            Route::patch('/series/{series}', [Admin\SeriesController::class, 'update'])->name('series.patch');
            Route::delete('/series/{series}', [Admin\SeriesController::class, 'destroy'])->name('series.destroy');

            Route::post('/series/{series}/seasons', [Admin\SeasonController::class, 'store'])->name('seasons.store');
            Route::get('/series/{series}/seasons/{season}', [Admin\SeasonController::class, 'show'])->name('seasons.show');
            Route::patch('/series/{series}/seasons/{season}', [Admin\SeasonController::class, 'update'])->name('seasons.update');
            Route::delete('/series/{series}/seasons/{season}', [Admin\SeasonController::class, 'destroy'])->name('seasons.destroy');

            Route::post('/series/{series}/seasons/{season}/episodes', [Admin\EpisodeController::class, 'store'])->name('episodes.store');
            Route::get('/series/{series}/seasons/{season}/episodes/{episode}', [Admin\EpisodeController::class, 'show'])->name('episodes.show');
            Route::patch('/series/{series}/seasons/{season}/episodes/{episode}', [Admin\EpisodeController::class, 'update'])->name('episodes.update');
            Route::delete('/series/{series}/seasons/{season}/episodes/{episode}', [Admin\EpisodeController::class, 'destroy'])->name('episodes.destroy');

            // Legacy flat episode toggle kept for the existing CMS series library.
            Route::patch('/series/{series}/episodes/{episode}', [Admin\SeriesController::class, 'updateEpisode'])
                ->name('series.episodes.update');

            Route::get('/audit-logs', [Admin\AuditLogController::class, 'index'])->name('audit-logs.index');
        });
    });
});
