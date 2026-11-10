<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEpisodeRequest;
use App\Http\Requests\UpdateEpisodeRequest;
use App\Http\Resources\AdminSeasonResource;
use App\Models\AdminAuditLog;
use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\VideoSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Episode administration nested under series + season. Both parents are
 * verified against the episode's stored ownership, so mismatched nested
 * identifiers 404 instead of silently moving records between seasons.
 * Responses return the refreshed season so the CMS can re-render in place.
 */
class EpisodeController extends Controller
{
    public function store(StoreEpisodeRequest $request, Series $series, Season $season): AdminSeasonResource
    {
        $this->guardOwnership($series, $season);
        $data = $request->validated();

        return DB::transaction(function () use ($request, $series, $season, $data): AdminSeasonResource {
            $episode = $season->episodes()->create([
                'series_id' => $series->id,
                'episode_number' => (int) $data['episode_number'],
                'title' => $data['title'] ?? null,
                'synopsis' => $data['synopsis'] ?? null,
                'duration_seconds' => $data['duration_seconds'] ?? null,
                'still_url' => $data['still_url'] ?? null,
                'published' => (bool) ($data['published'] ?? false),
            ]);

            $this->syncSources($episode, $data['sources']);

            AdminAuditLog::record(
                $request->user(),
                'series_episode.created',
                $episode,
                "Created episode {$episode->episode_number} of {$series->title} S{$season->season_number}",
                ['after' => $this->snapshot($episode)],
            );

            return new AdminSeasonResource($season->load(['series', 'episodes.videoSources']));
        });
    }

    public function show(Request $request, Series $series, Season $season, SeriesEpisode $episode): AdminSeasonResource
    {
        $this->guardOwnership($series, $season, $episode);

        return new AdminSeasonResource($season->load(['series', 'episodes.videoSources']));
    }

    public function update(UpdateEpisodeRequest $request, Series $series, Season $season, SeriesEpisode $episode): AdminSeasonResource
    {
        $this->guardOwnership($series, $season, $episode);
        $data = $request->validated();

        return DB::transaction(function () use ($request, $series, $season, $episode, $data): AdminSeasonResource {
            $before = $this->snapshot($episode->load('videoSources'));

            $episode->update(collect($data)->except('sources')->all());

            // Phase 1 movie semantics: omit `sources` to preserve rows, send a
            // complete array to replace the whole collection.
            if (array_key_exists('sources', $data)) {
                $this->syncSources($episode, $data['sources']);
            }

            $episode->load('videoSources');

            AdminAuditLog::record(
                $request->user(),
                'series_episode.updated',
                $episode,
                "Updated episode {$episode->episode_number} of {$series->title} S{$season->season_number}",
                ['changed' => array_keys($episode->getChanges()), 'before' => $before, 'after' => $this->snapshot($episode)],
            );

            return new AdminSeasonResource($season->load(['series', 'episodes.videoSources']));
        });
    }

    public function destroy(Request $request, Series $series, Season $season, SeriesEpisode $episode): JsonResponse
    {
        $this->guardOwnership($series, $season, $episode);

        return DB::transaction(function () use ($request, $series, $season, $episode): JsonResponse {
            $snapshot = $this->snapshot($episode->load('videoSources'));

            // Cascade is deliberate and documented: episode -> sources,
            // playback sessions and episode watch progress. The CMS requires an
            // explicit confirmation before calling this.
            $episode->delete();

            AdminAuditLog::record(
                $request->user(),
                'series_episode.deleted',
                $episode,
                "Deleted episode {$snapshot['episode_number']} of {$series->title} S{$season->season_number}",
                ['before' => $snapshot],
            );

            return response()->json(['status' => 'deleted']);
        });
    }

    private function guardOwnership(Series $series, Season $season, ?SeriesEpisode $episode = null): void
    {
        abort_unless((int) $season->series_id === (int) $series->id, 404);
        if ($episode !== null) {
            abort_unless((int) $episode->season_id === (int) $season->id, 404);
            abort_unless((int) $episode->series_id === (int) $series->id, 404);
        }
    }

    /**
     * Sources are replaced wholesale, mirroring the movie contract: a save is
     * a complete statement about which origins this episode may play from.
     *
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function syncSources(SeriesEpisode $episode, array $sources): void
    {
        $episode->videoSources()->delete();

        $hasDefault = collect($sources)->contains(fn ($source) => (bool) ($source['is_default'] ?? false));

        foreach ($sources as $index => $source) {
            $episode->videoSources()->create([
                'label' => $source['label'] ?? 'main',
                'kind' => $source['kind'],
                'url' => $source['url'],
                'quality' => $source['quality'] ?? null,
                'language' => $source['language'] ?? null,
                'is_default' => $hasDefault
                    ? (bool) ($source['is_default'] ?? false)
                    : $index === array_key_first($sources),
                'enabled' => (bool) ($source['enabled'] ?? true),
            ]);
        }

        $episode->unsetRelation('videoSources');
    }

    /**
     * Audit snapshots deliberately omit source URLs, so the trail never becomes
     * a second store of origin locations.
     *
     * @return array<string, mixed>
     */
    private function snapshot(SeriesEpisode $episode): array
    {
        return [
            'episode_number' => $episode->episode_number,
            'title' => $episode->title,
            'published' => $episode->published,
            'sources' => $episode->relationLoaded('videoSources')
                ? $episode->videoSources
                    ->map(fn (VideoSource $source) => [
                        'label' => $source->label,
                        'kind' => $source->kind,
                        'quality' => $source->quality,
                        'enabled' => $source->enabled,
                    ])
                    ->all()
                : [],
        ];
    }
}
