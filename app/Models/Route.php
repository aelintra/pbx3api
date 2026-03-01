<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Route extends Model
{

    //    
    protected $table = 'route';
    // Use id (KSUID, globally unique) so save() updates only one row when pkey is reused across tenants
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'active' => 'YES',
        'auth' => 'NO',
        'cluster' => 'default',
        'path1' => null,
        'path2' => null,
        'path3' => null,
        'path4' => null,
        'strategy' => 'hunt',
    ];

    /**
     * Mass-assignable (whitelist). Schema: sqlite_create_tenant.sql route.
     * Excludes id, shortuid (set on create), z_* (system-only).
     */
    protected $fillable = [
        'pkey',
        'active',
        'alternate',
        'auth',
        'cluster',
        'cname',
        'description',
        'dialplan',
        'path1',
        'path2',
        'path3',
        'path4',
        'route',
        'strategy',
    ];

    /** route column: deprecated (schema: same as pkey, not used). */
    protected $hidden = [
        'route',
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
