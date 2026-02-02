<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Route extends Model
{

    //    
    protected $table = 'route';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    // Defaults for route table (full_schema.sql has description, not desc)
    protected $attributes = [
    'active' => 'YES',
    'alternate' => null,
    'auth' => 'NO',
    'cluster' => 'default',
    'dialplan' => null,
    'path1' => null,
    'path2' => null,
    'path3' => null,
    'path4' => null,
    'strategy' => 'hunt'
    ];

    // none user updateable columns
    protected $guarded = [

    'alternate',
    'auth',
	'z_created',
	'z_updated',
	'z_updater'
    ];

    // hidden columns (mostly no longer used)
    protected $hidden = [
    'alternate',
    'auth'

    ];
}
