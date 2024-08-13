<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    //
    protected $table = 'agent';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [

    'conf' => null,
    'cluster' => 'default',
    'name' => null,
    'num' => null,
    'passwd' => null,
    'queue1' => 'None',
    'queue2' => 'None',
    'queue3' => 'None',
    'queue4' => 'None',
    'queue5' => 'None',
    'queue6' => 'None'

    ];

    // none user updateable columns
    protected $guarded = [

    'conf',
    'name',
    'num',
	'z_created',
	'z_updated',
	'z_updater'
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [
    'conf',
    'num'

    ];
}
