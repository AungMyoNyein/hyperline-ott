<?php

namespace App\Rules;

use App\Support\Contracts\HostResolver;
use App\Support\SsrfGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Guards URLs the API may later reference server-side. The app never fetches
 * video itself, but stored hosts are still resolved on save so a resolvable
 * public name cannot be repointed at an internal address later without notice.
 */
class SafeStreamUrl implements ValidationRule
{
    public function __construct(private readonly HostResolver $resolver) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = is_string($value) ? trim($value) : '';
        $parts = $url === '' ? false : parse_url($url);

        if ($parts === false || $url === '') {
            $fail('The :attribute must be an absolute URL.');

            return;
        }

        $schemes = (array) config('ott.streams.allowed_schemes', ['https']);

        if (! in_array(strtolower($parts['scheme'] ?? ''), $schemes, true)) {
            $fail('The :attribute must use one of these schemes: '.implode(', ', $schemes).'.');

            return;
        }

        if (! empty($parts['user']) || ! empty($parts['pass'])) {
            $fail('The :attribute must not contain embedded credentials.');

            return;
        }

        $host = strtolower($parts['host'] ?? '');

        if ($host === '') {
            $fail('The :attribute must include a host.');

            return;
        }

        $allowedPorts = (array) config('ott.streams.allowed_ports', [443]);

        if (isset($parts['port']) && ! in_array((int) $parts['port'], $allowedPorts, true)) {
            $fail('The :attribute may only use ports: '.implode(', ', $allowedPorts).'.');

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            $addresses = $this->resolver->resolve($host);

            if ($addresses === []) {
                if (config('ott.streams.reject_unresolved_hosts')) {
                    $fail('The :attribute host could not be resolved.');
                }

                return;
            }
        }

        foreach ($addresses as $address) {
            if (! SsrfGuard::isPublicAddress($address)) {
                $fail('The :attribute must not point at a private, loopback or reserved address.');

                return;
            }
        }
    }
}
