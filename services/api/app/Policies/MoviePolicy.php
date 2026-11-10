<?php

namespace App\Policies;

use App\Models\Movie;
use App\Models\Permission;
use App\Models\User;

class MoviePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::MOVIES_VIEW);
    }

    public function view(User $user, Movie $movie): bool
    {
        return $user->hasPermission(Permission::MOVIES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::MOVIES_CREATE);
    }

    public function update(User $user, Movie $movie): bool
    {
        return $user->hasPermission(Permission::MOVIES_UPDATE);
    }

    public function delete(User $user, Movie $movie): bool
    {
        return $user->hasPermission(Permission::MOVIES_DELETE);
    }

    public function publish(User $user, Movie $movie): bool
    {
        return $user->hasPermission(Permission::MOVIES_PUBLISH);
    }
}
