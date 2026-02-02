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

}
