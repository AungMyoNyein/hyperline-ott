<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWatchlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'movie_id' => ['required', 'integer', Rule::exists('movies', 'id')->where('published', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'movie_id.exists' => 'The selected movie is not available.',
        ];
    }
}
