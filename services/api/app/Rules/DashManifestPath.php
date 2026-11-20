<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DashManifestPath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $path = is_string($value) ? (parse_url($value, PHP_URL_PATH) ?? '') : '';
        if (! preg_match('/\.mpd$/i', $path)) {
            $fail('The :attribute must point at an .mpd manifest.');
        }
    }
}
