<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trunk extends Model
{
    //
    /** API runs against tenant schema (sqlite_create_tenant.sql). Trunks table follows tenant pattern (id, pkey, ...). */
    protected $table = 'trunks';
    // Use id (KSUID, globally unique) so save() updates only one row when pkey is reused across tenants
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    // Defaults for trunks table; only columns that exist in schema (full_schema.sql)
    protected $attributes = [
    'active' 		=> 'YES',
	'callprogress'  => 'NO',
	'closeroute' 	=> 'Operator',
	'cluster' 		=> 'default',
	'moh' 			=> 'NO',
	'openroute' 	=> 'Operator',
	'swoclip' 		=> 'YES'
    ];

    protected $guarded = [
    'callback',
	'channel',
	'closecallback',
	'closecustom',
	'closedisa',
	'closeext',
	'closegreet',
	'closeivr',
	'closequeue',
	'closeroute',
	'closesibling',
	'closespeed',
	'custom',
	'desc',
	'didnumber',
	'ext',
	'forceivr',
	'macaddr',
	'method',
	'openfirewall',
	'opengreet',
	'openroute',
	'opensibling',
	'pat',
	'postdial',
	'predial',
	'privileged',
	'provision',
	'queue',
	'remotenum',
	'service',
	'speed',
	'technology',
	'transformclip',
	'trunk',
	'zapcaruser',
	'z_created',
	'z_updated',
	'z_updater'
    ];

    protected $hidden = [
    'callback',
	'channel',
	'closecallback',
	'closecustom',
	'closedisa',
	'closeext',
	'closegreet',
	'closeivr',
	'closequeue',
	'closeroute',
	'closesibling',
	'closespeed',
	'custom',
	'didnumber',
	'desc',
	'ext',
	'forceivr',
	'macaddr',
	'method',
	'openfirewall',
	'opengreet',
	'openroute',
	'opensibling',
	'pat',
	'postdial',
	'predial',
	'privileged',
	'provision',
	'remotenum',
	'queue',
	'service',
	'speed',
	'technology',
	'transformclip',
	'trunk',
	'zapcaruser'
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
