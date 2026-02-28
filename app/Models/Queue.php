<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Queue extends Model
{
    //
    protected $table = 'queue';
    // Use id (KSUID, globally unique) so save() updates only one row when pkey is reused across tenants
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    // queue table (schema: sqlite_create_tenant.sql)
    protected $attributes = [
        'cluster' => 'default',
        'devicerec' => 'None',
        'options' => 'CiIknrtT',
        'timeout' => 30,
    ];

    /**
     * Mass-assignable (whitelist). Schema: sqlite_create_tenant.sql queue.
     * Excludes id, shortuid (set on create), z_* (system-only), name (deprecated, display only).
     */
    protected $fillable = [
        'pkey',
        'active',
        'alertinfo',
        'cluster',
        'cname',
        'description',
        'devicerec',
        'divert',
        'greetnum',
        'greeting',
        'members',
        'musicclass',
        'options',
        'retry',
        'wrapuptime',
        'maxlen',
        'outcome',
        'strategy',
        'timeout',
    ];

    /** name is deprecated (use cname); hide from array/JSON. */
    protected $hidden = [
        'name',
    ];

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

		// Try shortuid exact match first
		$model = static::where('shortuid', $value)->first();
		if ($model) {
			return $model;
		}

		// Try shortuid case-insensitive (SQLite TEXT can be case-sensitive)
		$model = static::whereRaw('LOWER(shortuid) = ?', [strtolower($value)])->first();
		if ($model) {
			return $model;
		}

		// Try id (KSUID) in case the segment is ever the id
		$model = static::where('id', $value)->first();
		if ($model) {
			return $model;
		}

		// Fallback to pkey for backward compatibility (tenant-scoped, may be ambiguous)
		return static::where('pkey', $value)->first();
	}
}
