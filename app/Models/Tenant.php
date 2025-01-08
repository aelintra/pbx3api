<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    //
    protected $table = 'cluster';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
    	'abstimeout' => 14400,
    	'chanmax' => '30',
    	'masteroclo' => 'AUTO'
    ];

    // none user updateable columns
    protected $guarded = [
		'name',
		'oclo', 
		'routeclassoverride',
		'routeoverride',   	
    	'z_created',
    	'z_updated'   	
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [
		'name',
		'oclo'
    ];
}