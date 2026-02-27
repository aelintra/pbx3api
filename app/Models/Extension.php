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
    // Use id (KSUID, globally unique) so save() updates only one row when pkey is reused across tenants
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    // column defaults (schema: sqlite_create_tenant.sql ipphone)
    protected $attributes = [
        'abstimeout' => 1440,
        'active' => 'YES',
        'callbackto' => 'desk',
        'devicerec' => 'default',
        'cluster' => 'default',
        'protocol' => 'IPV4',
        'transport' => 'udp',
        'technology' => 'SIP',
        'z_updater' => 'system',
    ];

    /**
     * Mass-assignable (whitelist). Schema: sqlite_create_tenant.sql ipphone.
     * id/shortuid: only set by controller on create (generate_ksuid/generate_shortuid), not from request.
     * Excludes z_* (system-only) and display-only/fixed: abstimeout, basemacaddr, devicemodel, passwd, stealtime, stolen, tls.
     */
    protected $fillable = [
        'id',
        'shortuid',
        'pkey',
        'active',
        'callerid',
        'callbackto',
        'cname',
        'callmax',
        'cellphone',
        'celltwin',
        'cluster',
        'desc',
        'description',
        'device',
        'devicerec',
        'dvrvmail',
        'extalert',
        'macaddr',
        'protocol',
        'provision',
        'provisionwith',
        'pjsipuser',
        'technology',
        'transport',
        'vmailfwd',
    ];

    /** Attributes excluded from array/JSON (e.g. passwd). */
    protected $hidden = [
        'passwd',
    ];

    /** Appended when serialized (no DB column). Derived from device. */
    protected $appends = ['extension_type'];

    /**
     * Extension type derived from device: WebRTC | MAILBOX | SIP.
     * WebRTC and MAILBOX have a single device template; all other devices are SIP (hard or soft).
     */
    public function getExtensionTypeAttribute(): string
    {
        $device = $this->attributes['device'] ?? null;
        if ($device === null || $device === '') {
            return 'SIP';
        }
        if (strcasecmp($device, 'WebRTC') === 0) {
            return 'WebRTC';
        }
        if (strcasecmp($device, 'MAILBOX') === 0) {
            return 'MAILBOX';
        }
        return 'SIP';
    }

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
