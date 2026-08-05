<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteProfile extends Model
{
    protected $table = 'route_profile';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $attributes = [
        'cluster' => 'default',
        'default_mode' => 'open',
    ];

    protected $fillable = [
        'cluster',
        'name',
        'default_mode',
        'cname',
        'description',
        'pkey',
    ];

    protected $guarded = ['z_created', 'z_updated', 'z_updater'];

    public function lines(): HasMany
    {
        return $this->hasMany(RouteProfileLine::class, 'profile', 'shortuid');
    }

    /**
     * Resolve route model binding by shortuid (preferred), then id, then pkey.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;

        $model = static::where('shortuid', $value)->first();
        if ($model) {
            return $model;
        }

        $model = static::whereRaw('LOWER(shortuid) = ?', [strtolower($value)])->first();
        if ($model) {
            return $model;
        }

        $model = static::where('id', $value)->first();
        if ($model) {
            return $model;
        }

        return static::where('pkey', $value)->first();
    }

    /**
     * True when profile shortuid exists and cluster matches (shortuid or pkey form).
     */
    public static function belongsToCluster(string $profileShortuid, string $clusterShortuid): bool
    {
        $profile = static::where('shortuid', $profileShortuid)->first();
        if (! $profile) {
            return false;
        }

        $pc = (string) $profile->cluster;
        if ($pc === $clusterShortuid) {
            return true;
        }
        // DB may store tenant pkey; request cluster is shortuid
        $aliases = cluster_identifier_aliases($clusterShortuid);

        return in_array($pc, $aliases, true);
    }
}
