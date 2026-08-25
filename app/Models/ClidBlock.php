<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClidBlock extends Model
{
    protected $table = 'clid_block';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'cluster' => 'default',
        'active' => 'YES',
        'action' => 'hangup',
    ];

    protected $fillable = [
        'cluster',
        'pkey',
        'active',
        'action',
        'cname',
        'description',
    ];

    protected $guarded = ['z_created', 'z_updated', 'z_updater'];

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
