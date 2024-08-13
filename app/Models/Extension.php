<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    	'provisionwith' => 'IP',
    	'sndcreds' => 'Always',
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
