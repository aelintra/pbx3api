<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayTimer extends Model
{
    protected $table = 'holiday';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'cluster' => 'default',
        'route' => null,
        'stime' => null,
        'etime' => null,
    ];

    /**
     * Mass-assignable. id, shortuid, pkey set on create only.
     */
    protected $fillable = [
        'cluster',
        'cname',
        'description',
        'route',
        'stime',
        'etime',
    ];

    protected $guarded = ['z_created', 'z_updated', 'z_updater'];
    protected $hidden = [];

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
}
