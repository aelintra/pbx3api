<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassOfService extends Model
{
    protected $table = 'cos';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'active' => 'YES',
        'cluster' => 'default',
        'defaultclosed' => 'NO',
        'defaultopen' => 'NO',
        'orideclosed' => 'NO',
        'orideopen' => 'NO',
    ];

    /**
     * Mass-assignable (whitelist). Schema: sqlite_create_tenant.sql cos.
     * pkey set on create only (identity-only). defaultopen, defaultclosed, orideopen, orideclosed are system/display-only.
     */
    protected $fillable = [
        'pkey',
        'active',
        'cluster',
        'cname',
        'description',
        'dialplan',
    ];

    protected $guarded = ['z_created', 'z_updated', 'z_updater'];
    protected $hidden = [];

    /**
     * Resolve route model binding by shortuid (globally unique) instead of pkey (tenant-scoped).
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
