<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DayTimer extends Model
{
    protected $table = 'dateseg';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'active' => 'YES',
        'cluster' => 'default',
        'datemonth' => '*',
        'dayofweek' => '*',
        'description' => '*NEW RULE*',
        'month' => '*',
        'state' => 'IDLE',
        'timespan' => '*',
    ];

    /**
     * Mass-assignable. pkey is system-generated integer; id, shortuid, state, z_* are not fillable.
     */
    protected $fillable = [
        'active',
        'cluster',
        'cname',
        'datemonth',
        'dayofweek',
        'description',
        'month',
        'timespan',
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

        if (is_numeric($value)) {
            return static::where('pkey', (int) $value)->first();
        }

        return null;
    }
}
