<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\MovieResource;
use App\Models\AdminAuditLog;
use App\Models\Genre;
use App\Models\Movie;
use App\Models\PlaybackSession;
use App\Models\Watchlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $window = now()->subDay();

        return response()->json([
            'data' => [
                'movies' => [
                    'total' => Movie::query()->count(),
                    'published' => Movie::query()->published()->count(),
                    'draft' => Movie::query()->where('published', false)->count(),
                    'featured' => Movie::query()->featured()->count(),
                ],
                'genres' => Genre::query()->count(),
                'viewers' => [
                    'watchlist_adds_24h' => Watchlist::query()->where('created_at', '>=', $window)->count(),
                    'playback_sessions_24h' => PlaybackSession::query()->where('started_at', '>=', $window)->count(),
                ],
                'trending' => MovieResource::collection(
                    Movie::query()
                        ->withCount(['playbackSessions' => fn ($query) => $query->where('started_at', '>=', now()->subDays(30))])
                        ->orderByDesc('playback_sessions_count')
                        ->take(5)
                        ->get()
                )->toArray($request),
                'recent_activity' => AuditLogResource::collection(
                    AdminAuditLog::query()->with('user')->latest()->take(10)->get()
                )->toArray($request),
            ],
        ]);
    }
}
