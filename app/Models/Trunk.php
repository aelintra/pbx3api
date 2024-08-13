<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trunk extends Model
{
    //
    protected $table = 'lineio';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
    'active' 		=> 'YES',
	'callprogress'  => 'NO',
	'closeroute' 	=> 'Operator',
	'cluster' 		=> 'default',
	'faxdetect'		=> 'NO',
	'lcl' 			=> 'NO',
	'moh' 			=> 'NO',
	'monitor' 		=> 'NO',
	'openroute' 	=> 'Operator',
	'routeable' 	=> 'NO',
    'routeclassopen' => 100,
    'routeclassclosed' => 100,
	'swoclip' 		=> 'YES'
    ];

    // none user updateable columns
    protected $guarded = [
    'callback',	
    'carrier',
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
	'faxdetect',
	'forceivr',
	'lcl',
	'macaddr',
	'method',
	'monitor',
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
	'routeable',
	'routeclassopen',
	'routeclassclosed',
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

    // hidden columns (mostly no longer used)
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
	'faxdetect',
	'forceivr',
	'lcl',
	'macaddr',
	'method',
	'monitor',
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
	'routeable',
	'routeclassopen',
	'routeclassclosed',
	'service',
	'speed',
	'technology',
	'transformclip',
	'trunk',	
	'zapcaruser'

    ];
}
