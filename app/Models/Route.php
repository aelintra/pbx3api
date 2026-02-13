<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Route extends Model
{

    //    
    protected $table = 'route';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    // Defaults for route table (full_schema.sql has description, not desc)
    protected $attributes = [
    'active' => 'YES',
    'alternate' => null,
    'auth' => 'NO',
    'cluster' => 'default',
    'dialplan' => null,
    'path1' => null,
    'path2' => null,
    'path3' => null,
    'path4' => null,
    'strategy' => 'hunt'
    ];

    // none user updateable columns
    protected $guarded = [

    'alternate',
    'auth',
	'z_created',
	'z_updated',
	'z_updater'
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [
    'alternate',
    'auth'

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
