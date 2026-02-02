<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboundRoute extends Model
{
    //
    protected $table = 'inroutes';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    // Defaults for inroutes table; only columns that exist in schema (full_schema.sql)
    protected $attributes = [
    'active' 		=> 'YES',
	'callprogress'  => 'NO',
	'closeroute' 	=> 'operator',
	'cluster' 		=> 'default',
	'moh' 			=> 'NO',
	'openroute' 	=> 'operator',
	'swoclip' 		=> 'YES'
    ];

    // Columns not mass-assigned from request (schema: full_schema.sql inroutes)
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

    // Hidden from JSON (schema: full_schema.sql inroutes)
    protected $hidden = [
    'callback',
    'callerid',
    'callprogress',
	'channel',
	'closecallback',
	'closecustom',
	'closedisa',
	'closeext',
	'closegreet',
	'closeivr',
	'closequeue',
	'closesibling',
	'closespeed',
	'custom',
	'didnumber',
	'disa',
	'desc',
	'ext',
	'forceivr',
	'host',
	'macaddr',
	'match',
	'method',
	'openfirewall',
	'opengreet',
	'opensibling',
	'password',
	'peername',
	'pat',
	'postdial',
	'predial',
	'privileged',
	'provision',
	'register',
	'remotenum',
	'queue',
	'service',
	'sipiaxpeer',
	'sipiaxuser',
	'speed',
	'transform',
	'transformclip',
	'trunk',
	'username',
	'zapcaruser'
    ];
}
