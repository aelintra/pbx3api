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

    // queue table (full_schema.sql; no conf column)
    protected $attributes = [
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
    'id',
    'cname',
    'name',
    'outcome',
	'z_created',
	'z_updated',
	'z_updater'
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [
    'name',
 //   'id',
 //   'outcome',
    'timeout'

    ];
}
