<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenreResource;
use App\Models\AdminAuditLog;
use App\Models\Genre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GenreController extends Controller
{
    public function index()
    {
        return GenreResource::collection(Genre::withCount(['movies', 'series'])
            ->orderBy('sort_order')->orderBy('name')->get());
    }

    private function validated(Request $request, ?Genre $genre = null): array
    {
        return $request->validate([
            'name' => [$genre ? 'sometimes' : 'required', 'string', 'max:120'],
            'name_my' => ['sometimes', 'nullable', 'string', 'max:120'],
            'slug' => [$genre ? 'sometimes' : 'required', 'string', 'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('genres')->ignore($genre?->id)],
            'enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000000'],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return DB::transaction(function () use ($request, $data) {
            $genre = Genre::create($data);
            AdminAuditLog::record($request->user(), 'genre.created', $genre, "Created genre {$genre->name}", ['after' => $genre->toArray()]);

            return GenreResource::make($genre->fresh()->loadCount(['movies', 'series']))->response()->setStatusCode(201);
        });
    }

    public function update(Request $request, Genre $genre)
    {
        $data = $this->validated($request, $genre);

        return DB::transaction(function () use ($request, $genre, $data) {
            $genre = Genre::whereKey($genre->id)->lockForUpdate()->firstOrFail();
            $before = $genre->toArray();
            $genre->update($data);
            AdminAuditLog::record($request->user(), 'genre.updated', $genre, "Updated genre {$genre->name}", ['before' => $before, 'after' => $genre->toArray()]);

            return GenreResource::make($genre->loadCount(['movies', 'series']));
        });
    }

    public function destroy(Request $request, Genre $genre)
    {
        return DB::transaction(function () use ($request, $genre) {
            $genre = Genre::whereKey($genre->id)->lockForUpdate()->firstOrFail();
            abort_if($genre->movies()->exists() || $genre->series()->exists(), 409,
                'This genre is assigned to content. Disable it or remove assignments before deleting.');
            AdminAuditLog::record($request->user(), 'genre.deleted', $genre, "Deleted genre {$genre->name}", ['before' => $genre->toArray()]);
            $genre->delete();

            return response()->noContent();
        });
    }
}
