<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Liveness only. Dependency probes stay out of a public endpoint so the health
     * check cannot be used to map internal services; `php artisan migrate:status`
     * and the container healthchecks cover readiness.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'hyperline-api',
            'app' => config('app.name'),
            'time' => now()->toIso8601String(),
        ]);
    }
}
