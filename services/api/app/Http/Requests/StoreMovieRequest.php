<?php

namespace App\Http\Requests;

use App\Models\Permission;
use App\Rules\HlsManifestPath;
use App\Rules\SafeStreamUrl;
use App\Rules\YouTubeUrl;
use App\Support\Contracts\HostResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMovieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::MOVIES_CREATE) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('movies', 'slug')],
            'synopsis' => ['nullable', 'string', 'max:20000'],
            'release_year' => ['nullable', 'integer', 'between:1900,'.(date('Y') + 1)],
            'duration_minutes' => ['nullable', 'integer', 'between:1,10000'],
            'poster_url' => ['nullable', 'url', 'starts_with:https://', 'max:1024'],
            'backdrop_url' => ['nullable', 'url', 'starts_with:https://', 'max:1024'],
            'featured' => ['sometimes', 'boolean'],
            'published' => ['sometimes', 'boolean'],
            'genres' => ['sometimes', 'array', 'max:20'],
            'genres.*' => ['string', 'max:120', 'distinct', Rule::exists('genres', 'slug')],

            // Stream locations are video_sources rows, never columns on the movie,
            // so metadata and playback origin cannot drift into one field.
            'sources' => ['required', 'array', 'min:1', 'max:10'],
        ] + $this->sourceRules();
    }

    /**
     * @return array<string, list<string>>
     */
    protected function sourceRules(): array
    {
        $sources = $this->input('sources');

        if (! is_array($sources)) {
            return [];
        }

        $resolver = app(HostResolver::class);
        $rules = [];

        foreach (array_keys($sources) as $index) {
            $kind = (string) data_get($sources, "$index.kind", 'hls');

            $url = ['required', 'string', 'max:2048', new SafeStreamUrl($resolver)];

            if ($kind === 'hls') {
                $url[] = new HlsManifestPath;
            } elseif ($kind === 'youtube') {
                $url[] = new YouTubeUrl;
            }

            $rules["sources.$index.label"] = ['sometimes', 'string', 'max:60'];
            $rules["sources.$index.kind"] = ['required', 'in:hls,dash,mp4,youtube'];
            $rules["sources.$index.url"] = $url;
            $rules["sources.$index.quality"] = ['nullable', 'string', 'max:20'];
            $rules["sources.$index.language"] = ['nullable', 'string', 'max:32'];
            $rules["sources.$index.is_default"] = ['sometimes', 'boolean'];
            $rules["sources.$index.enabled"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->has('hls_url')) {
                $validator->errors()->add(
                    'hls_url',
                    'Store stream locations in sources[], not on the movie.',
                );
            }

            $sources = (array) $this->input('sources', []);

            $defaults = collect($sources)
                ->filter(fn ($source) => filter_var((bool) ($source['is_default'] ?? false), FILTER_VALIDATE_BOOLEAN))
                ->count();

            if ($defaults > 1) {
                $validator->errors()->add('sources', 'Only one source can be marked default.');
            }

            $labels = collect($sources)
                ->map(fn ($source) => mb_strtolower((string) ($source['label'] ?? 'main')));

            if ($labels->duplicates()->isNotEmpty()) {
                $validator->errors()->add('sources', 'Source labels must be unique per movie.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sources.required' => 'At least one video source is required.',
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
