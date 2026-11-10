<?php

namespace App\Http\Requests;

use App\Models\Permission;
use App\Models\Season;
use App\Rules\HlsManifestPath;
use App\Rules\SafeStreamUrl;
use App\Rules\YouTubeUrl;
use App\Support\Contracts\HostResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Episode creation with the Phase 1 source semantics: stream locations live in
 * sources[] rows (never on the episode), validated with the same SSRF-guarded
 * rules movies use. Sources are required on create so a new episode is either
 * playable material or explicitly disabled.
 */
class StoreEpisodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SERIES_UPDATE) ?? false;
    }

    public function rules(): array
    {
        /** @var Season $season */
        $season = $this->route('season');

        return [
            'episode_number' => [
                'required', 'integer', 'between:1,2000',
                Rule::unique('series_episodes', 'episode_number')->where('season_id', $season->id),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'synopsis' => ['nullable', 'string', 'max:5000'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'still_url' => ['nullable', 'string', 'max:1024', 'url:https'],
            'published' => ['sometimes', 'boolean'],
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
                $validator->errors()->add('sources', 'Source labels must be unique per episode.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'episode_number.unique' => 'This season already has an episode with that number.',
            'sources.required' => 'At least one video source is required.',
        ];
    }
}
