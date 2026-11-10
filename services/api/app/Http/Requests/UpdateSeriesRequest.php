<?php

namespace App\Http\Requests;

use App\Models\Permission;
use App\Models\Series;
use Illuminate\Validation\Rule;

class UpdateSeriesRequest extends StoreSeriesRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SERIES_UPDATE) ?? false;
    }

    public function rules(): array
    {
        /** @var Series $series */
        $series = $this->route('series');

        $rules = parent::rules();

        $rules['title'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['slug'] = [
            'sometimes',
            'required',
            'string',
            'max:255',
            'alpha_dash',
            Rule::unique('series', 'slug')->ignore($series->id),
        ];
        $rules['synopsis'] = ['sometimes', 'nullable', 'string', 'max:5000'];
        $rules['category'] = ['sometimes', 'nullable', 'string', 'max:60'];
        $rules['language'] = ['sometimes', 'nullable', 'string', 'max:60'];
        $rules['release_year'] = ['sometimes', 'nullable', 'integer', 'between:1900,'.(date('Y') + 1)];
        $rules['poster_url'] = ['sometimes', 'nullable', 'string', 'max:1024', 'url:https'];
        $rules['backdrop_url'] = ['sometimes', 'nullable', 'string', 'max:1024', 'url:https'];

        return $rules;
    }
}
