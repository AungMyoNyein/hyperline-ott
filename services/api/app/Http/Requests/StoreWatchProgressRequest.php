<?php

namespace App\Http\Requests;

use App\Models\SeriesEpisode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWatchProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Exactly one owner. Progress on unpublished content is rejected so
            // drafts cannot accumulate viewer history through the public API.
            'movie_id' => [
                'required_without:episode_id', 'nullable', 'integer',
                Rule::exists('movies', 'id')->where('published', true),
            ],
            'episode_id' => [
                'required_without:movie_id', 'nullable', 'integer',
                Rule::exists('series_episodes', 'id')->where('published', true),
            ],
            'position_seconds' => ['required', 'integer', 'between:0,864000'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'between:1,864000'],
            'completed' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('movie_id') && $this->filled('episode_id')) {
                $validator->errors()->add('episode_id', 'Supply either a movie or an episode, not both.');

                return;
            }

            if ($validator->errors()->hasAny(['episode_id'])) {
                return;
            }

            // The episode's season and series must also be published; the flat
            // exists-rule cannot express the hierarchy, so it is checked here.
            if ($this->filled('episode_id')) {
                $episode = SeriesEpisode::query()->with('season.series')->find($this->integer('episode_id'));

                if ($episode === null || ! $episode->isVisibleInCatalog()) {
                    $validator->errors()->add('episode_id', 'The selected episode is not available.');
                }
            }

            $position = $this->integer('position_seconds');
            $duration = $this->filled('duration_seconds') ? $this->integer('duration_seconds') : null;

            if ($duration !== null && $position > $duration) {
                $validator->errors()->add('position_seconds', 'Playback position cannot exceed the duration.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'movie_id.exists' => 'The selected movie is not available.',
            'episode_id.exists' => 'The selected episode is not available.',
            'movie_id.required_without' => 'A movie or episode selection is required.',
            'episode_id.required_without' => 'A movie or episode selection is required.',
        ];
    }
}
