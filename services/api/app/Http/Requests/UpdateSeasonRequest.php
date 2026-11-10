<?php

namespace App\Http\Requests;

use App\Models\Season;
use Illuminate\Validation\Rule;

class UpdateSeasonRequest extends StoreSeasonRequest
{
    public function rules(): array
    {
        /** @var Season $season */
        $season = $this->route('season');
        $seriesId = $season->series_id;

        $rules = parent::rules();

        $rules['season_number'] = [
            'sometimes', 'required', 'integer', 'between:0,999',
            Rule::unique('seasons', 'season_number')->where('series_id', $seriesId)->ignore($season->id),
        ];
        $rules['title'] = ['sometimes', 'nullable', 'string', 'max:255'];
        $rules['synopsis'] = ['sometimes', 'nullable', 'string', 'max:5000'];
        $rules['poster_url'] = ['sometimes', 'nullable', 'string', 'max:1024', 'url:https'];
        $rules['backdrop_url'] = ['sometimes', 'nullable', 'string', 'max:1024', 'url:https'];

        return $rules;
    }
}
