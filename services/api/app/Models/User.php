<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
        'preferences',
        'subscription_expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'subscription_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'preferences' => 'array',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function watchlist(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function watchProgress(): HasMany
    {
        return $this->hasMany(WatchProgress::class);
    }

    public function playbackSessions(): HasMany
    {
        return $this->hasMany(PlaybackSession::class);
    }

    public function hasRole(string ...$slugs): bool
    {
        $owned = $this->relationLoaded('roles')
            ? $this->roles->pluck('slug')->all()
            : $this->roles()->pluck('slug')->all();

        return (bool) array_intersect($slugs, $owned);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::ADMIN);
    }

    /**
     * Permissions resolve through roles, so granting a permission to a role is
     * enough; no per-user permission table exists.
     */
    public function hasPermission(string $slug): bool
    {
        $roleIds = $this->relationLoaded('roles')
            ? $this->roles->pluck('id')->all()
            : $this->roles()->pluck('roles.id')->all();

        if ($roleIds === []) {
            return false;
        }

        return Permission::query()
            ->where('slug', $slug)
            ->whereExists(function ($query) use ($roleIds) {
                $query->select()
                    ->from('permission_role')
                    ->whereColumn('permission_role.permission_id', 'permissions.id')
                    ->whereIn('permission_role.role_id', $roleIds);
            })
            ->exists();
    }
}
