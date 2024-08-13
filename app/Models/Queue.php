<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Queue extends Model
{
    //
    protected $table = 'queue';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [

    'conf' => "musiconhold=default
strategy=ringall
timeout=300\nretry=5
wrapuptime=0
maxlen=0
announce-frequency=30
announce-holdtime=yes",

    'cluster' => 'default',
    'devicerec' => 'None',
    'greetnum' => null,
    'options' => 't',
    'name' => null,
    'outcome' => null,
    'timeout' => 0

    ];

    // none user updateable columns
    protected $guarded = [

    'name',
    'outcome',
	'z_created',
	'z_updated',
	'z_updater'
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [
    'name',
    'home',
    'id',
    'outcome',
    'timeout'

    ];
}
