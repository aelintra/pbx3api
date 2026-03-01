<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    //
    protected $table = 'agent';
    // Use id (KSUID, globally unique) so save() updates only one row when pkey is reused across tenants
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'cluster' => 'default',
        'queue1' => 'None',
        'queue2' => 'None',
        'queue3' => 'None',
        'queue4' => 'None',
        'queue5' => 'None',
        'queue6' => 'None',
    ];

    /**
     * Mass-assignable (whitelist). Schema: sqlite_create_tenant.sql agent.
     * Excludes id, shortuid (set on create), z_* (system-only).
     */
    protected $fillable = [
        'pkey',
        'cluster',
        'conf',
        'extlen',
        'name',
        'cname',
        'description',
        'num',
        'passwd',
        'queue1',
        'queue2',
        'queue3',
        'queue4',
        'queue5',
        'queue6',
    ];

    /** conf, num: internal; name: deprecated (use cname). */
    protected $hidden = [
        'conf',
        'num',
        'name',
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
