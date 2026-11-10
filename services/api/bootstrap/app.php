<?php

use App\Exceptions\PlaybackDeniedException;
use App\Http\Middleware\EnsureUserHasRole;
use App\Support\Playback\NginxSecureLinkSigner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // StatefulApi makes Sanctum treat same-site SPA requests as cookie based,
        // which is where CSRF applies; bearer-token calls stay stateless.
        $middleware->encryptCookies(except: [NginxSecureLinkSigner::COOKIE]);

        $middleware->statefulApi()
            ->throttleApi('api');

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        $proxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '172.16.0.0/12')),
        )));

        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Keep API server errors opaque even if APP_DEBUG was accidentally enabled.
        // Logging/reporting still uses the original exception on the server.
        $exceptions->respond(function (Response $response, Throwable $error, Request $request) {
            if (! $request->is('api/*') || $response->getStatusCode() < 500) {
                return $response;
            }
            $status = $response->getStatusCode();
            $safe = response()->json([
                'message' => $status === 503 ? 'Service temporarily unavailable.' : 'Server error.',
                'code' => $status === 503 ? 'service_unavailable' : 'server_error',
            ], $status);
            $safe->headers->set('Cache-Control', 'private, no-store');
            if ($response->headers->has('Retry-After')) {
                $safe->headers->set('Retry-After', $response->headers->get('Retry-After'));
            }

            return $safe;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json(['message' => 'Authentication required.', 'code' => 'unauthenticated'], 401)
                : null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json([
                    'message' => $e->getMessage() ?: 'Not authorised for this action.',
                    'code' => 'forbidden',
                ], 403)
                : null;
        });

        $exceptions->render(function (PlaybackDeniedException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 403)
                : null;
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json([
                    'message' => 'The given data was invalid.',
                    'code' => 'validation_failed',
                    'errors' => $e->errors(),
                ], 422)
                : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json(['message' => 'Too many requests.', 'code' => 'rate_limited'], 429)
                : null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();

            return response()->json([
                'message' => match (true) {
                    $status === 404 => 'Resource not found.',
                    $status === 403 => $e->getMessage() ?: 'Not authorised for this action.',
                    default => $e->getMessage() ?: 'Request failed.',
                },
                // Laravel converts AuthorizationException into an HTTP exception
                // before the callbacks above run, so the status is what actually
                // decides the code for 401/403/404.
                'code' => match ($status) {
                    401 => 'unauthenticated',
                    403 => 'forbidden',
                    404 => 'not_found',
                    405 => 'method_not_allowed',
                    429 => 'rate_limited',
                    default => 'error',
                },
            ], $status);
        });
    })->create();
