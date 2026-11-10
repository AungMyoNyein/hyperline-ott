<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')->all()),
            'subscription_expires_at' => $this->subscription_expires_at,
            'preferences' => $this->preferences,
            'created_at' => $this->created_at,
        ];
    }
}
