<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboundRoute extends Model
{
    //
    protected $table = 'inroutes';
    // Use id (KSUID, globally unique) so save() updates only one row when pkey is reused across tenants
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    // Defaults for inroutes table (schema: sqlite_create_tenant.sql)
    protected $attributes = [
        'active'       => 'YES',
        'callprogress' => 'YES',
        'closeroute'   => 'None',
        'cluster'      => 'default',
        'moh'          => 'NO',
        'openroute'    => 'None',
        'swoclip'      => 'YES',
    ];

    // Whitelist: only these columns may be mass-assigned (schema: inroutes)
    protected $fillable = [
        'pkey',
        'active',
        'alertinfo',
        'callback',
        'callerid',
        'callprogress',
        'closeroute',
        'cluster',
        'cname',
        'description',
        'devicerec',
        'disa',
        'disapass',
        'host',
        'iaxreg',
        'inprefix',
        'match',
        'moh',
        'openroute',
        'password',
        'peername',
        'pjsipreg',
        'privileged',
        'register',
        'swoclip',
        'tag',
        'technology',
        'transform',
        'transport',
        'trunkname',
        'username',
    ];

    // Hidden from JSON (sensitive, legacy, or display-only; not editable via API)
    protected $hidden = [
        'callprogress',
        'host',
        'iaxreg',
        'password',
        'peername',
        'pjsipreg',
        'register',
        'transport',
        'trunkname',
        'username',
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
