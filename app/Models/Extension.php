<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Extension (ipphone table). Table has both "desc" and "description".
 * "desc" is the SIP username; the Asterisk generator uses it to build Asterisk objects — do not remove.
 * TODO: Rename "desc" to something more appropriate (e.g. sip_username) in schema and Asterisk generator when feasible.
 */
class Extension extends Model
{
    protected $table = 'ipphone';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    // column defaults
    protected $attributes = [
    	'abstimeout' => '14400',  
    	'active' => 'YES',
    	'basemacaddr' => NULL,
    	'callbackto' => 'desk',
    	'devicerec' => 'default',
    	'cluster' => 'default',
    	'protocol' => 'IPV4',
    	'transport' => 'udp',
        'technology' => 'SIP',
    	'z_updater' => 'system'

    ];

    // none user updateable columns
    protected $guarded = [
    		'abstimeout',
    		'basemacaddr',
    		'devicemodel',
    		'dialstring',
    		'firstseen',
    		'lastseen',
			'passwd',
    		'provisionwith',  // not in tenant schema (sqlite_create_tenant.sql)
    		'sndcreds',       // not in tenant schema
    		'z_created',
    		'z_updated',
    		'newformat',
    		'openfirewall',
    		'stealtime',
    		'stolen',
    		'tls',
    		'twin'
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [
    		'abstimeout',
    		'channel',
    		'dialstring',
    		'externalip',
    		'newformat',
    		'openfirewall',
			'sipiaxfriend',
    		'tls',
    		'twin'
    ];

	public function __construct(array $attributes = array())
	{
    parent::__construct($attributes);

    $this->attributes['passwd'] = ret_password(12);

	}

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
		$model = static::where('pkey', $value)->first();
		return $model;
	}

}
