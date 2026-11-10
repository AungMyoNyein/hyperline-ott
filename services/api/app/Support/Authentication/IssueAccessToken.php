<?php

namespace App\Support\Authentication;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

class IssueAccessToken
{
    public function issue(User $user, string $name, array $abilities = ['*']): NewAccessToken
    {
        $minutes = (int) config('sanctum.expiration');

        if ($minutes < 1 || $minutes > 10080) {
            throw new \LogicException('An API token lifetime between 1 and 10080 minutes is required.');
        }

        return $user->createToken($name, $abilities, now()->addMinutes($minutes)->startOfSecond());
    }
}
