<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CosProfile extends Model
{
    protected $table = 'cos_profile';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $attributes = [
        'active' => 'YES',
        'cluster' => 'default',
        'is_default' => 'NO',
    ];

    protected $fillable = [
        'pkey',
        'active',
        'cluster',
        'cname',
        'description',
        'is_default',
    ];

    protected $guarded = ['z_created', 'z_updated', 'z_updater'];

    public function openRules(): HasMany
    {
        return $this->hasMany(CosProfileOpen::class, 'profile_pkey', 'pkey');
    }

    public function closedRules(): HasMany
    {
        return $this->hasMany(CosProfileClosed::class, 'profile_pkey', 'pkey');
    }

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

    /** True when profile pkey exists and cluster matches (shortuid or alias). */
    public static function belongsToCluster(string $profilePkey, string $clusterShortuid): bool
    {
        $profile = static::where('pkey', $profilePkey)->first();
        if (! $profile) {
            return false;
        }

        $pc = (string) $profile->cluster;
        if ($pc === $clusterShortuid) {
            return true;
        }
        $aliases = cluster_identifier_aliases($clusterShortuid);

        return in_array($pc, $aliases, true);
    }

    /** Default profile pkey for a tenant shortuid, or null. */
    public static function defaultPkeyForCluster(string $clusterShortuid): ?string
    {
        $aliases = cluster_identifier_aliases($clusterShortuid);
        if ($aliases === []) {
            $aliases = [$clusterShortuid];
        }
        $row = static::query()
            ->whereIn('cluster', $aliases)
            ->whereRaw("upper(trim(is_default)) = 'YES'")
            ->orderBy('pkey')
            ->first();

        return $row ? (string) $row->pkey : null;
    }
}
