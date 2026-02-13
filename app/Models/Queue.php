<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Queue extends Model
{
    //
    protected $table = 'queue';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    // queue table (full_schema.sql; no conf column)
    protected $attributes = [
    'cluster' => 'default',
    'devicerec' => 'None',
    'greetnum' => null,
    'options' => 't',
    'name' => null,
    'outcome' => null,
    'timeout' => 0
    ];

    // none user updateable columns
    protected $guarded = [
    'id',
    'cname',
    'name',
    'outcome',
	'z_created',
	'z_updated',
	'z_updater'
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [
    'name',
 //   'id',
 //   'outcome',
    'timeout'

    ];

	/**
	 * Resolve route model binding by shortuid (globally unique) instead of pkey (tenant-scoped).
	 * Falls back to pkey for backward compatibility if shortuid not found.
	 */
	public function resolveRouteBinding($value, $field = null)
	{
		// Try shortuid first (globally unique)
		$model = static::where('shortuid', $value)->first();
		if ($model) {
			return $model;
		}
		
		// Fallback to pkey for backward compatibility (though pkey is tenant-scoped and may be ambiguous)
		return static::where('pkey', $value)->first();
	}
}
