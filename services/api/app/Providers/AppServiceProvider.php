<?php

namespace App\Providers;

use App\Console\Commands\CreateAdministrator;
use App\Models\Permission;
use App\Models\User;
use App\Support\Contracts\ExistingCdnAuthorizer;
use App\Support\Contracts\HostResolver;
use App\Support\Contracts\PlaybackUrlSigner;
use App\Support\DnsHostResolver;
use App\Support\Playback\UnconfiguredExistingCdnAuthorizer;
use App\Support\Playback\UnconfiguredPlaybackUrlSigner;
use App\Support\ServiceHeartbeat;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
        $this->app->bind(ExistingCdnAuthorizer::class, function ($app) {
            $class = config('ott.external_cdn.authorizer');
            $adapter = $app->make($class ?: UnconfiguredExistingCdnAuthorizer::class);
            if (! $adapter instanceof ExistingCdnAuthorizer) {
                throw new \LogicException('Existing CDN adapter must implement ExistingCdnAuthorizer.');
            }

            return $adapter;
        });
        $this->app->bind(PlaybackUrlSigner::class, function ($app) {
            $provider = config('ott.playback.signer');
            $signer = $app->make($provider ?: UnconfiguredPlaybackUrlSigner::class);
            if (! $signer instanceof PlaybackUrlSigner) {
                throw new \LogicException('Playback signer must implement PlaybackUrlSigner.');
            }

            return $signer;
        });
    }

    public function boot(): void
    {
        Queue::looping(function () {
            ServiceHeartbeat::mark('worker');
        });
        // Administrators satisfy every ability. The permission gates below exist so
        // narrower staff roles can be granted later without touching the policies.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        foreach (Permission::ALL as $ability) {
            Gate::define($ability, fn (User $user) => $user->hasPermission($ability));
        }

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('playback', fn (Request $request) => Limit::perMinute(10)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->letters()->numbers()->symbols()
            : Password::min(8));

        $this->commands(CreateAdministrator::class);
    }
}
