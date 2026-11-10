<?php

namespace App\Http\Requests;

use App\Models\SeriesEpisode;
use Illuminate\Validation\Rule;

/**
 * Episode updates keep the Phase 1 movie-source contract: omit `sources` to
 * preserve the existing rows, or send a complete non-empty array to replace
 * the whole collection. Metadata/publication patches never touch sources.
 */
class UpdateEpisodeRequest extends StoreEpisodeRequest
{
    public function rules(): array
    {
        /** @var SeriesEpisode $episode */
        $episode = $this->route('episode');

        $rules = parent::rules();

        $rules['episode_number'] = [
            'sometimes', 'required', 'integer', 'between:1,2000',
            Rule::unique('series_episodes', 'episode_number')
                ->where('season_id', $episode->season_id)
                ->ignore($episode->id),
        ];
        $rules['title'] = ['sometimes', 'nullable', 'string', 'max:255'];
        $rules['synopsis'] = ['sometimes', 'nullable', 'string', 'max:5000'];
        $rules['duration_seconds'] = ['sometimes', 'nullable', 'integer', 'min:1', 'max:86400'];
        $rules['still_url'] = ['sometimes', 'nullable', 'string', 'max:1024', 'url:https'];
        $rules['sources'] = ['sometimes', 'required', 'array', 'min:1', 'max:10'];

        return $rules;
    }
}
