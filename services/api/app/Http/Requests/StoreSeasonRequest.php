<?php

namespace App\Http\Requests;

use App\Models\Permission;
use App\Models\Season;
use App\Models\Series;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSeasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SERIES_UPDATE) ?? false;
    }

    public function rules(): array
    {
        /** @var Series $series */
        $series = $this->route('series');

        return [
            // Season 0 is the reserved specials bucket; main seasons start at 1.
            'season_number' => [
                'required', 'integer', 'between:0,999',
                Rule::unique('seasons', 'season_number')->where('series_id', $series->id),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'synopsis' => ['nullable', 'string', 'max:5000'],
            'poster_url' => ['nullable', 'string', 'max:1024', 'url:https'],
            'backdrop_url' => ['nullable', 'string', 'max:1024', 'url:https'],
            'published' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'season_number.unique' => 'This series already has a season with that number.',
        ];
    }
}
