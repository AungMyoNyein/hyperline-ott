<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    public const MOVIES_VIEW = 'movies.view';

    public const MOVIES_CREATE = 'movies.create';

    public const MOVIES_UPDATE = 'movies.update';

    public const MOVIES_DELETE = 'movies.delete';

    public const MOVIES_PUBLISH = 'movies.publish';

    public const SERIES_VIEW = 'series.view';

    public const SERIES_CREATE = 'series.create';

    public const SERIES_UPDATE = 'series.update';

    public const SERIES_DELETE = 'series.delete';

    public const SERIES_PUBLISH = 'series.publish';

    public const AUDIT_VIEW = 'audit.view';

    public const DASHBOARD_VIEW = 'dashboard.view';

    /** @var list<string> */
    public const ALL = [
        self::MOVIES_VIEW,
        self::MOVIES_CREATE,
        self::MOVIES_UPDATE,
        self::MOVIES_DELETE,
        self::MOVIES_PUBLISH,
        self::SERIES_VIEW,
        self::SERIES_CREATE,
        self::SERIES_UPDATE,
        self::SERIES_DELETE,
        self::SERIES_PUBLISH,
        self::AUDIT_VIEW,
        self::DASHBOARD_VIEW,
    ];

    protected $fillable = [
        'slug',
        'name',
        'description',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
