<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Support\Authentication\IssueAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, IssueAccessToken $tokens): JsonResponse
    {
        $user = User::create($request->validated());

        $user->roles()->attach(Role::firstOrCreate(
            ['slug' => Role::SUBSCRIBER],
            ['name' => 'Subscriber'],
        )->id);

        $token = $tokens->issue($user, $this->device($request));

        return response()->json([
            'data' => [
                'user' => new UserResource($user->load('roles')),
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at->toISOString(),
            ],
        ], 201);
    }

    public function login(LoginRequest $request, IssueAccessToken $tokens): JsonResponse
    {
        $user = User::query()->where('email', $request->input('email'))->first();

        if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
            // One message for both cases so the endpoint cannot enumerate accounts.
            return response()->json([
                'message' => 'These credentials do not match our records.',
                'code' => 'invalid_credentials',
            ], 401);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $tokens->issue($user, $this->device($request));

        return response()->json([
            'data' => [
                'user' => new UserResource($user->load('roles')),
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at->toISOString(),
            ],
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles'));
    }

    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->user()->currentAccessToken();

        if ($accessToken === null || ! method_exists($accessToken, 'delete')) {
            return response()->json([
                'message' => 'The current request is not token based.',
                'code' => 'not_token_session',
            ], 400);
        }

        $accessToken->delete();

        return response()->json(['status' => 'logged_out']);
    }

    private function device(Request $request): string
    {
        return (string) $request->input('device_name', 'web');
    }
}
