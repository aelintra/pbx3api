<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Greeting extends Model
{
    protected $table = 'greeting';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'cluster' => 'default',
    ];

    /**
     * Mass-assignable (whitelist). Schema: sqlite_create_tenant.sql greeting.
     * Excludes id, shortuid (set on create), z_* (system-only).
     */
    protected $fillable = [
        'pkey',
        'cname',
        'filename',
        'cluster',
        'description',
        'type',
    ];

    protected $hidden = [];

    /**
     * Resolve route model binding by shortuid (globally unique) instead of pkey (tenant-scoped).
     * Tries: shortuid (exact), shortuid (case-insensitive), id, then pkey.
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
}

