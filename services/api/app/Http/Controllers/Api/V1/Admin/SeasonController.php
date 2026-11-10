<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSeasonRequest;
use App\Http\Requests\UpdateSeasonRequest;
use App\Http\Resources\AdminSeasonResource;
use App\Models\AdminAuditLog;
use App\Models\Season;
use App\Models\Series;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Season administration nested under a series. The season is always resolved
 * through its series so a season belonging to a different series 404s on this
 * route (mismatched nested identifiers are rejected, never coerced).
 */
class SeasonController extends Controller
{
    public function store(StoreSeasonRequest $request, Series $series): \Illuminate\Http\JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($request, $series, $data): \Illuminate\Http\JsonResponse {
            $season = $series->seasons()->create([
                ...collect($data)->except('published')->all(),
                'published' => (bool) ($data['published'] ?? true),
            ]);

            AdminAuditLog::record(
                $request->user(),
                'series_season.created',
                $season,
                "Created season {$season->season_number} of {$series->title}",
                ['after' => $this->snapshot($season)],
            );

            return AdminSeasonResource::make($season->load('series'))->response()->setStatusCode(201);
        });
    }

    public function show(Request $request, Series $series, Season $season): AdminSeasonResource
    {
        $this->guardOwnership($series, $season);

        return new AdminSeasonResource($season->load(['series', 'episodes.videoSources']));
    }

    public function update(UpdateSeasonRequest $request, Series $series, Season $season): AdminSeasonResource
    {
        $this->guardOwnership($series, $season);
        $data = $request->validated();

        return DB::transaction(function () use ($request, $series, $season, $data): AdminSeasonResource {
            $before = $this->snapshot($season);
            $season->update($data);

            AdminAuditLog::record(
                $request->user(),
                'series_season.updated',
                $season,
                "Updated season {$season->season_number} of {$series->title}",
                ['changed' => array_keys($season->getChanges()), 'before' => $before, 'after' => $this->snapshot($season)],
            );

            return new AdminSeasonResource($season->load(['series', 'episodes.videoSources']));
        });
    }

    public function destroy(Request $request, Series $series, Season $season): \Illuminate\Http\JsonResponse
    {
        $this->guardOwnership($series, $season);

        return DB::transaction(function () use ($request, $series, $season) {
            $episodeCount = $season->episodes()->count();
            $snapshot = $this->snapshot($season);

            // Cascade is deliberate and documented: season -> episodes ->
            // sources/sessions/episode progress. The CMS requires an explicit
            // confirmation naming the episode count before calling this.
            $season->delete();

            AdminAuditLog::record(
                $request->user(),
                'series_season.deleted',
                $season,
                "Deleted season {$snapshot['season_number']} of {$series->title} with {$episodeCount} episode(s)",
                ['before' => $snapshot, 'episodes_deleted' => $episodeCount],
            );

            return response()->json(['status' => 'deleted', 'episodes_deleted' => $episodeCount]);
        });
    }

    private function guardOwnership(Series $series, Season $season): void
    {
        abort_unless((int) $season->series_id === (int) $series->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Season $season): array
    {
        return [
            'season_number' => $season->season_number,
            'title' => $season->title,
            'published' => $season->published,
        ];
    }
}
