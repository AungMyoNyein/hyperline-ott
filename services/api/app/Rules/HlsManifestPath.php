<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Requires a manifest path so an arbitrary endpoint cannot be stored as a
 * "stream" and later reached by clients or the server.
 */
class HlsManifestPath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $path = is_string($value) ? (parse_url($value, PHP_URL_PATH) ?? '') : '';

        if (! preg_match('/\.m3u8$/i', $path)) {
            $fail('The :attribute must point at an .m3u8 playlist.');
        }
    }
}
