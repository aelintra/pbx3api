<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trunk extends Model
{
    //
    /** API runs against tenant schema (sqlite_create_tenant.sql). Trunks table follows tenant pattern (id, pkey, ...). */
    protected $table = 'trunks';
    protected $primaryKey = 'pkey';
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
}
