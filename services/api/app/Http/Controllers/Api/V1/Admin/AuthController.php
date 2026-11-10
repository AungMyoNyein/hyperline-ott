<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use App\Http\Resources\UserResource;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Support\Authentication\IssueAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(AdminLoginRequest $request, IssueAccessToken $tokens): JsonResponse
    {
        $user = User::query()->where('email', $request->input('email'))->first();

        $valid = $user !== null && Hash::check((string) $request->input('password'), $user->password);

        if (! $valid || ! $user->isAdmin()) {
            // Denied attempts are recorded, but the response cannot distinguish a bad
            // password from a non-administrator account.
            AdminAuditLog::record($user, 'admin.login_denied', $user);

            return response()->json([
                'message' => 'These credentials do not match our records.',
                'code' => 'invalid_credentials',
            ], 401);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $tokens->issue($user, (string) $request->input('device_name', 'cms'), ['cms']);

        AdminAuditLog::record($user, 'admin.login', $user, 'Administrator signed in', [
            'token_id' => $token->accessToken->id,
        ]);

        return response()->json([
            'data' => [
                'token_type' => 'Bearer',
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at->toISOString(),
                'user' => new UserResource($user->load('roles')),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessToken = $user->currentAccessToken();

        if ($accessToken === null || ! method_exists($accessToken, 'delete')) {
            return response()->json([
                'message' => 'The current request is not token based.',
                'code' => 'not_token_session',
            ], 400);
        }

        $tokenId = $accessToken->id;
        $accessToken->delete();

        AdminAuditLog::record($user, 'admin.logout', $user, 'Administrator signed out', [
            'token_id' => $tokenId,
        ]);

        return response()->json(['status' => 'logged_out']);
    }
}
