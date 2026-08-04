<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-calling-tenant dial prefix (short dial A′). Schema: dialalias.
 * Product: **prefix** (pkey = 2–4 digits). Dial target: target_fqdn (tenant FQDN).
 * target_cluster is optional shortuid label pin only.
 */
class DialAlias extends Model
{
    protected $table = 'dialalias';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $attributes = [
        'active' => 'YES',
        'cluster' => 'default',
    ];

    protected $fillable = [
        'pkey',
        'active',
        'cluster',
        'target_cluster',
        'target_fqdn',
        'cname',
        'description',
    ];

    protected $hidden = [];

    /**
     * Resolve by shortuid (preferred), then id, then pkey (last may be multi-tenant ambiguous).
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
