<?php

namespace App\Http\Requests;

use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SERIES_CREATE) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('series', 'slug')],
            'synopsis' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:60'],
            'language' => ['nullable', 'string', 'max:60'],
            'release_year' => ['nullable', 'integer', 'between:1900,'.(date('Y') + 1)],
            'poster_url' => ['nullable', 'string', 'max:1024', 'url:https'],
            'backdrop_url' => ['nullable', 'string', 'max:1024', 'url:https'],
            'featured' => ['sometimes', 'boolean'],
            'published' => ['sometimes', 'boolean'],
            'genres' => ['sometimes', 'array', 'max:20'],
            'genres.*' => ['string', 'max:120', 'distinct', Rule::exists('genres', 'slug')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'genres.*' => 'genre',
        ];
    }
}
