<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMovieRequest;
use App\Http\Requests\UpdateMovieRequest;
use App\Http\Resources\AdminMovieResource;
use App\Models\AdminAuditLog;
use App\Models\Genre;
use App\Models\Movie;
use App\Models\VideoSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class MovieController extends Controller
{
    /** @var list<string> */
    private const WITH = ['genres', 'videoSources', 'creator'];

    /** @var list<string> */
    private const ATTRIBUTES = [
        'title',
        'slug',
        'synopsis',
        'release_year',
        'duration_minutes',
        'poster_url',
        'backdrop_url',
        'featured',
        'published',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'published' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'genre' => ['sometimes', 'string', 'max:120', 'exists:genres,slug'],
            'q' => ['sometimes', 'string', 'min:2', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.config('ott.catalog.max_page_size')],
        ]);

        $movies = Movie::query()
            ->with(self::WITH)
            ->genre($filters['genre'] ?? null)
            ->search($filters['q'] ?? null)
            ->when(isset($filters['published']), fn ($query) => $query->where('published', (bool) $filters['published']))
            ->when(isset($filters['featured']), fn ($query) => $query->where('featured', (bool) $filters['featured']))
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 50))
            ->withQueryString();

        return AdminMovieResource::collection($movies);
    }

    public function show(Movie $movie): AdminMovieResource
    {
        return new AdminMovieResource($movie->load(self::WITH));
    }

    public function store(StoreMovieRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $data = $request->validated();
            $published = (bool) ($data['published'] ?? false);

            $movie = Movie::create([
                ...Arr::only($data, self::ATTRIBUTES),
                'featured' => (bool) ($data['featured'] ?? false),
                'published' => $published,
                'published_at' => $published ? now() : null,
                'created_by' => $request->user()->id,
            ]);

            $this->syncGenres($movie, $data['genres'] ?? null);
            $this->syncSources($movie, $data['sources'] ?? []);

            $movie->load(self::WITH);

            AdminAuditLog::record($request->user(), 'movie.created', $movie, "Created {$movie->title}", [
                'after' => $this->snapshot($movie),
            ]);

            return AdminMovieResource::make($movie)->response()->setStatusCode(201);
        });
    }

    public function update(UpdateMovieRequest $request, Movie $movie): AdminMovieResource
    {
        return DB::transaction(function () use ($request, $movie): AdminMovieResource {
            $data = $request->validated();

            $movie->load(self::WITH);
            $before = $this->snapshot($movie);

            $attributes = Arr::only($data, self::ATTRIBUTES);

            if (array_key_exists('published', $data)) {
                $attributes['published_at'] = $data['published'] ? ($movie->published_at ?? now()) : $movie->published_at;
            }

            $movie->update($attributes);

            if (array_key_exists('genres', $data)) {
                $this->syncGenres($movie, $data['genres']);
            }

            if (array_key_exists('sources', $data)) {
                $this->syncSources($movie, $data['sources']);
            }

            $movie->load(self::WITH);

            AdminAuditLog::record($request->user(), 'movie.updated', $movie, "Updated {$movie->title}", [
                'changed' => array_keys($movie->getChanges()),
                'before' => $before,
                'after' => $this->snapshot($movie),
            ]);

            return new AdminMovieResource($movie);
        });
    }

    public function destroy(Request $request, Movie $movie): JsonResponse
    {
        return DB::transaction(function () use ($request, $movie): JsonResponse {
            $movie->load(self::WITH);
            $snapshot = $this->snapshot($movie);

            $movie->delete();

            // The instance keeps its primary key in memory after the delete, so the
            // audit row still points at the row that existed.
            AdminAuditLog::record($request->user(), 'movie.deleted', $movie, "Deleted {$snapshot['title']}", [
                'before' => $snapshot,
            ]);

            return response()->json(['status' => 'deleted']);
        });
    }

    /**
     * @param  list<string>|null  $slugs
     */
    private function syncGenres(Movie $movie, ?array $slugs): void
    {
        if ($slugs === null) {
            return;
        }

        $movie->genres()->sync(
            Genre::query()->whereIn('slug', $slugs)->pluck('id')->all()
        );
    }

    /**
     * Sources are replaced wholesale: a CMS save is a complete statement about
     * which origins this title may play from, so stale rows must not survive it.
     *
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function syncSources(Movie $movie, array $sources): void
    {
        if ($sources === []) {
            return;
        }

        $movie->videoSources()->delete();

        $hasDefault = collect($sources)->contains(fn ($source) => (bool) ($source['is_default'] ?? false));

        foreach ($sources as $index => $source) {
            $movie->videoSources()->create([
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

        $movie->unsetRelation('videoSources');
    }

    /**
     * Audit snapshots deliberately omit source URLs, so the trail never becomes a
     * second store of origin locations.
     */
    private function snapshot(Movie $movie): array
    {
        return [
            'title' => $movie->title,
            'slug' => $movie->slug,
            'published' => $movie->published,
            'featured' => $movie->featured,
            'genres' => $movie->genres->pluck('slug')->all(),
            'sources' => $movie->videoSources
                ->map(fn (VideoSource $source) => [
                    'label' => $source->label,
                    'kind' => $source->kind,
                    'quality' => $source->quality,
                    'enabled' => $source->enabled,
                ])
                ->all(),
        ];
    }
}
