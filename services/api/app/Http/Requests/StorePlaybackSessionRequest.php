<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlaybackSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Publication is deliberately not filtered here: drafts must be refused
            // with the documented entitlement reason (403) rather than a 422, so the
            // denial path is identical for unpublished and unentitled viewers.
            // Exactly one of movie_id / episode_id must be supplied.
            'movie_id' => ['required_without:episode_id', 'nullable', 'integer', Rule::exists('movies', 'id')],
            'episode_id' => ['required_without:movie_id', 'nullable', 'integer', Rule::exists('series_episodes', 'id')],
            'source' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->filled('movie_id') && $this->filled('episode_id')) {
                    $validator->errors()->add('episode_id', 'Supply either a movie or an episode, not both.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'movie_id.exists' => 'The selected movie is not available.',
            'episode_id.exists' => 'The selected episode is not available.',
            'movie_id.required_without' => 'A movie or episode selection is required.',
        ];
    }
}
