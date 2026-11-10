<?php

namespace App\Http\Requests;

use App\Models\Movie;
use App\Models\Permission;
use Illuminate\Validation\Rule;

class UpdateMovieRequest extends StoreMovieRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::MOVIES_UPDATE) ?? false;
    }

    public function rules(): array
    {
        /** @var Movie $movie */
        $movie = $this->route('movie');

        $rules = parent::rules();

        $rules['title'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['slug'] = [
            'sometimes',
            'required',
            'string',
            'max:255',
            'alpha_dash',
            Rule::unique('movies', 'slug')->ignore($movie->id),
        ];
        $rules['synopsis'] = ['sometimes', 'nullable', 'string', 'max:20000'];
        $rules['release_year'] = ['sometimes', 'nullable', 'integer', 'between:1900,'.(date('Y') + 1)];
        $rules['duration_minutes'] = ['sometimes', 'nullable', 'integer', 'between:1,10000'];
        $rules['poster_url'] = ['sometimes', 'nullable', 'url', 'starts_with:https://', 'max:1024'];
        $rules['backdrop_url'] = ['sometimes', 'nullable', 'url', 'starts_with:https://', 'max:1024'];
        $rules['featured'] = ['sometimes', 'boolean'];
        $rules['published'] = ['sometimes', 'boolean'];
        $rules['genres'] = ['sometimes', 'array', 'max:20'];
        $rules['sources'] = ['sometimes', 'required', 'array', 'min:1', 'max:10'];

        return $rules;
    }
}
